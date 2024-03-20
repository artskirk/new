<?php

namespace Datto\Cloud;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Asset;
use Datto\Asset\AssetService;
use Datto\Billing\Service as BillingService;
use Datto\Common\Utility\Filesystem;
use Datto\Config\DeviceConfig;
use Datto\Log\LoggerAwareTrait;
use Datto\Resource\DateTimeService;
use Datto\System\MaintenanceModeService;
use Datto\Utility\Cloud\SpeedSync as SpeedSyncUtility;
use Datto\Utility\File\LockFactory;
use Exception;
use Psr\Log\LoggerAwareInterface;

/**
 * Manages SpeedSync's "maintenance mode" for disabling offsite replication at both a global and per-asset level.
 * In both cases, as of SpeedSync 6.12, this class (and the state files it maintains) is the "source of truth" for
 * the pause state, which is in contrast to earlier versions of SpeedSync that were paused directly from DeviceWeb,
 * and the siris-os-2 code was updated according to what it reported.
 *
 * For Global (Device-wide) pause, there are several things that contribute to the pause state:
 *  1. A Local, user-initiated pause with an optional delay/unpause time.
 *  2. A Cloud-initiated pause from DeviceWeb, updated during checkin.
 *     - Though it's not relevant to the logic itself, this can be a result of things like offsiting being disabled
 *       via the partner portal, or when the Storage Node the device is associated with is taken down.
 *  3. The device itself being in "Maintenance Mode" with all scheduled activity paused
 *  4. The device Billing information indicating that the device is "Local Only"
 *
 * For per-asset pause, this is generally a result of the user manually pausing an asset, though it can also
 * occur automatically, for example when pruning offsite snapshots or removing an asset and destroying its offsite
 * data. Regardless of the cause, all of this goes through the exact same pauseAsset/resumeAsset path. With
 * per-asset pauses, there are no delays or automatic resumes; all pauses are "indefinite".
 *
 * @author Peter Geer <pgeer@datto.com>
 * @author Geoff Amey <gamey@datto.com>
 */
class SpeedSyncMaintenanceService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const LOCK_FILE = '/run/datto/SpeedSyncMaintenance.lock'; // The lock file to ensure mutual exclusion
    const MAX_DELAY_HOURS = 87600; // 10 Years in hours. Represents "indefinite" pause to device-web
    const MAX_DELAY_IN_SECONDS = self::MAX_DELAY_HOURS * DateTimeService::SECONDS_PER_HOUR;
    const OFFSITE_PAUSE_INDEFINITE = -1; // A negative value for local pause means to pause forever
    const OFFSITE_PAUSE_KEY = 'offsitePause'; // Config key for a locally-initiated pause of offsite syncing
    const CLOUD_PAUSE_KEY = 'cloudOffsitePause'; // Config key for a cloud-initiated pause of offsite syncing

    const ASSET_PAUSED_KEY = 'speedSyncPaused'; // The asset keyfile indicating a pause is in place for a single asset

    private DeviceConfig $deviceConfig;
    private SpeedSyncPauseNotifier $speedSyncPauseNotifier;
    private DateTimeService $timeService;
    private AssetService $assetService;
    private Filesystem $filesystem;
    private BillingService $billingService;
    private SpeedSyncUtility $speedSyncUtility;
    private MaintenanceModeService $maintenanceModeService;
    private LockFactory $lockFactory;

    public function __construct(
        DeviceConfig $deviceConfig,
        SpeedSyncPauseNotifier $speedSyncPauseNotifier,
        DateTimeService $timeService,
        AssetService $assetService,
        Filesystem $filesystem,
        BillingService $billingService,
        SpeedSyncUtility $speedSyncUtility,
        MaintenanceModeService $maintenanceModeService,
        LockFactory $lockFactory
    ) {
        $this->deviceConfig = $deviceConfig;
        $this->speedSyncPauseNotifier = $speedSyncPauseNotifier;
        $this->timeService = $timeService;
        $this->assetService = $assetService;
        $this->filesystem = $filesystem;
        $this->billingService = $billingService;
        $this->speedSyncUtility = $speedSyncUtility;
        $this->maintenanceModeService = $maintenanceModeService;
        $this->lockFactory = $lockFactory;
    }

    //***************************************************************
    // Global Pause APIs
    //***************************************************************

    /**
     * Determine if SpeedSync is currently enabled at all, based on our current subscribed service plan
     *
     * @return bool True if offsite synchronization is allowed by the service plan
     */
    public function isEnabled(): bool
    {
        return !$this->billingService->isLocalOnly();
    }

    /**
     * Pause SpeedSync globally on the device for the given length of time
     *
     * @param int $durationSeconds Duration of the pause in seconds. Pause durations >=10 years 87600*60*60 are
     * considered to be indefinite
     */
    public function pause(int $durationSeconds): void
    {
        // For indefinite pause (values >= 10 yrs), we write a negative value to the flag.
        if ($durationSeconds >= self::MAX_DELAY_IN_SECONDS) {
            $resumeTime = self::OFFSITE_PAUSE_INDEFINITE;
        } else {
            $resumeTime = $this->timeService->getTime() + $durationSeconds;
        }

        $this->logger->info('SSM0001 Pausing offsite replication', [
            'duration' => $durationSeconds,
            'resumeTime' => $resumeTime
        ]);

        // Write the timestamp of our desired resume to the key, and run the pause check logic
        $this->deviceConfig->set(self::OFFSITE_PAUSE_KEY, $resumeTime);
        $this->check();

        // Notify device-web of the new pause state
        $this->notifyDeviceWeb();
    }

    /**
     * Resume SpeedSync globally on the device.
     */
    public function resume(): void
    {
        $this->logger->info('SSM0002 Resuming offsite replication');

        // All we need to do here is clear the local pause flag and re-run the update logic. We might still
        // be paused after running this, depending on if any other pauses are in place
        $this->deviceConfig->clear(self::OFFSITE_PAUSE_KEY);
        $this->check();

        // Make sure we notify device-web of the new pause state
        $this->notifyDeviceWeb();
    }

    /**
     * Determine if SpeedSync is currently paused for the device.
     *
     * @return bool
     */
    public function isDevicePaused(): bool
    {
        return $this->shouldBePaused();
    }

    /**
     * Get the time when the user-initiated offsite pause expires. Will return 0 if no pause is in place, or -1 if
     * the offsite pause is indefinite.
     *
     * @return int
     */
    public function getResumeTime(): int
    {
        return $this->deviceConfig->get(self::OFFSITE_PAUSE_KEY, 0);
    }

    /**
     * Return whether the current speedsync pause is indefinite
     *
     * @return bool
     */
    public function isDelayIndefinite(): bool
    {
        $localIndefinite = false;
        if ($this->getResumeTime() === self::OFFSITE_PAUSE_INDEFINITE) {
            $localIndefinite = true;
        }

        return $localIndefinite
            || !$this->isEnabled()
            || $this->billingService->isOutOfService()
            || $this->maintenanceModeService->isEnabled()
            || $this->isCloudPause();
    }

    /**
     * Expected to be called by snapctl during checkin, this indicates that the cloud requires offsite replication
     * to be paused for this device.
     */
    public function cloudPause(): void
    {
        // This is called every checkin, so if we already have this key, just return early without doing anything
        if ($this->deviceConfig->has(self::CLOUD_PAUSE_KEY)) {
            return;
        }

        $this->logger->info('SSM0003 Offsite replication paused by device-web');
        $this->deviceConfig->touch(self::CLOUD_PAUSE_KEY);
        $this->check();
        $this->notifyDeviceWeb();
    }

    /**
     * Expected to be called by snapctl during checkin, this indicates that the cloud (device-web) no longer requires
     * offsite replication to be disabled for this device.
     */
    public function cloudResume(): void
    {
        // This is called every checkin, so if we don't already have this key, just return early without doing anything
        if (!$this->deviceConfig->has(self::CLOUD_PAUSE_KEY)) {
            return;
        }

        $this->logger->info('SSM0004 Offsite replication resumed by device-web');
        $this->deviceConfig->clear(self::CLOUD_PAUSE_KEY);
        $this->check();
        $this->notifyDeviceWeb();
    }

    //***************************************************************
    // Asset Pause APIs
    //***************************************************************

    /**
     * Pause speedsync for a given asset, and optionally halt and abort ongoing transfers
     *
     * @param string $assetKeyName
     * @param bool $halt Also perform a speedsync halt, preventing future operations and dequeueing all local snapshots
     */
    public function pauseAsset(string $assetKeyName, bool $halt = false): void
    {
        $asset = $this->assetService->get($assetKeyName);

        // Replicated assets don't use speedsync. early return here.
        if ($asset->getOriginDevice()->isReplicated()) {
            return;
        }

        $this->logger->setAssetContext($assetKeyName);
        $this->logger->info('SSM0011 Pausing offsite replication for the asset.', ['halt' => $halt]);

        // Touch the file that indicates that this asset should be paused
        $this->filesystem->touch($this->assetPausedFile($assetKeyName));

        // Pause (and optionally halt) the dataset. As of SpeedSync 6.12+, the siris-os-2 codebase is the
        // source of truth for speedsync pauses.
        $pauseSuccessful = $this->pauseDataset($asset->getDataset()->getZfsPath());
        if ($halt) {
            $this->speedSyncUtility->halt($asset->getDataset()->getZfsPath());
        }

        if (!$pauseSuccessful) {
            $this->logger->error('SSM0012 Unable to pause offsite replication for the asset.');
            throw new Exception('Unable to pause speedsync for the path ' . $asset->getDataset()->getZfsPath());
        }

        // Notify DeviceWeb that the asset is paused
        $this->speedSyncPauseNotifier->sendAssetPaused($assetKeyName);
    }

    /**
     * Resume speedsync for a given asset
     *
     * @param string $assetKeyName
     */
    public function resumeAsset(string $assetKeyName): void
    {
        $asset = $this->assetService->get($assetKeyName);

        // Replicated assets don't use speedsync. early return here.
        if ($asset->getOriginDevice()->isReplicated()) {
            return;
        }

        $this->logger->setAssetContext($assetKeyName);
        $this->logger->info('SSM0013 Resuming Offsite Replication for the asset.');

        // Delete the file indicating that the asset should be paused
        $this->filesystem->unlinkIfExists($this->assetPausedFile($assetKeyName));
        $resumeSuccessful = $this->resumeDataset($asset->getDataset()->getZfsPath());

        if (!$resumeSuccessful) {
            $this->logger->error('SSM0015 Unable to resume offsite replication for the asset.');
            throw new Exception('Unable to resume speedsync for the path ' . $asset->getDataset()->getZfsPath());
        }

        // Notify DeviceWeb that the asset was resumed
        $this->speedSyncPauseNotifier->sendAssetResumed($assetKeyName);
    }

    /**
     * @param string $assetKeyName
     *
     * @return bool
     */
    public function isAssetPaused(string $assetKeyName): bool
    {
        return $this->filesystem->exists($this->assetPausedFile($assetKeyName));
    }

    /**
     * Get a list of asset names (not key names) that are currently paused.
     *
     * @return string[]
     */
    public function getPausedAssetNames(): array
    {
        $pausedAssetNames = [];
        foreach ($this->assetService->getAll() as $asset) {
            if ($this->isAssetPaused($asset->getKeyName())) {
                $pausedAssetNames[] = $asset->getDisplayName();
            }
        }
        return $pausedAssetNames;
    }

    /**
     * Sets the concurrent sync limit.
     *
     * @param int $maxSyncs
     */
    public function setMaxConcurrentSyncs(int $maxSyncs): void
    {
        $this->speedSyncUtility->setMaxSyncs($maxSyncs);
    }

    /**
     * Get the concurrent sync limit.
     *
     * @return int
     */
    public function getMaxConcurrentSyncs(): int
    {
        return $this->speedSyncUtility->getMaxSyncs();
    }

    //***************************************************************
    // Periodic Consistency Checks
    //***************************************************************

    /**
     * Check the device-wide pause state based on all the potential inputs
     */
    public function check(): void
    {
        // Get a lock, since this is called when making changes (e.g. user pausing locally), as well as periodically
        // The lock will automatically release when this function returns and $lock goes out of scope
        $lock = $this->lockFactory->create(self::LOCK_FILE);
        $lock->exclusive();

        $shouldBePaused = $this->shouldBePaused();
        $actuallyPaused = $this->isSpeedSyncPaused();

        // Pause or Resume speedsync if it's not in the correct state based on the device config files
        if ($shouldBePaused !== $actuallyPaused) {
            if ($shouldBePaused) {
                $this->logger->info('SSM0005 Pausing SpeedSync');
                $this->pauseSpeedSync();
            } else {
                $this->logger->info('SSM0006 Resuming SpeedSync');
                $this->resumeSpeedSync();
            }
        }
    }

    /**
     * Check the consistency of the paused state for all assets on the device. If an asset is found to be in an
     * inconsistent state, correct it and notify device-web of its new state.
     */
    public function checkAssets(): void
    {
        $lock = $this->lockFactory->create(self::LOCK_FILE);
        $lock->exclusive();

        foreach ($this->assetService->getAll() as $asset) {
            $this->checkAsset($asset);
        }
    }

    /**
     * Check the consistency of the paused state for an asset. If it is found to be in an inconsistent state, correct
     * it and notify device-web of its new state.
     *
     * @param Asset $asset
     */
    public function checkAsset(Asset $asset): void
    {
        try {
            // Replicated assets don't use speedsync, archived assets are speedsync halt-ed so just early return here
            if ($asset->getOriginDevice()->isReplicated() || $asset->getLocal()->isArchived()) {
                return;
            }

            $this->logger->setAssetContext($asset->getKeyName());
            $datasetPaused = $this->isDatasetPaused($asset->getDataset()->getZfsPath());
            $assetPaused = $this->isAssetPaused($asset->getKeyName());

            if ($datasetPaused !== $assetPaused) {
                $this->logger->warning('SSM0014 Asset paused state is inconsistent', [
                    'datasetPaused' => $datasetPaused,
                    'assetPaused' => $assetPaused
                ]);

                // Use the state of the asset (the existence of the agent keyfile) as the source of truth. If it
                // disagrees with what speedsync reports, then re-run the pause/unpause logic
                if ($assetPaused) {
                    $this->pauseAsset($asset->getKeyName());
                } else {
                    $this->resumeAsset($asset->getKeyName());
                }
            }
        } catch (SpeedSyncPathException $e) {
            // In some cases, an asset's path might not be added to speedsync. We don't want to blow up the operation
            // in this case, as there are a number of reasons this could happen, e.g. an asset is set to never offsite
            $this->logger->warning('SSM0017 Error checking asset state for consistency.', ['exception' => $e]);
        }
    }

    //***************************************************************
    // Private/Helper functions
    //***************************************************************

    /**
     * @return bool True if the device state should be paused based on the current inputs
     */
    private function shouldBePaused(): bool
    {
        // If any of the following conditions are true, we should have a device-wide pause in place.
        return
            $this->billingService->isLocalOnly() // Billing indicates that the device is local-only
            || $this->billingService->isOutOfService() // Device service has expired
            || $this->maintenanceModeService->isEnabled() // Global maintenance mode stops offsite replication
            || $this->isUnexpiredLocalPause() // A local pause is in place (e.g. user paused through UI)
            || $this->isCloudPause(); // A device-web originated pause is in place
    }

    /**
     * @return bool
     */
    private function isUnexpiredLocalPause(): bool
    {
        if (!$this->deviceConfig->has(self::OFFSITE_PAUSE_KEY)) {
            return false;
        }

        // If the file exists and the value is non-negative but the time is in the past, we can clear the flag
        $resumeTime = $this->getResumeTime();
        if ($resumeTime >= 0 && $resumeTime <= $this->timeService->getTime()) {
            $this->logger->info('SSM0016 Offsite Replication pause expired');
            $this->deviceConfig->clear(self::OFFSITE_PAUSE_KEY);
            return false;
        }

        return true;
    }

    /**
     * Return whether the currently-active offsitePause has expired
     */
    private function isCloudPause(): bool
    {
        return $this->deviceConfig->has(self::CLOUD_PAUSE_KEY);
    }

    /**
     * @return bool
     */
    private function isSpeedSyncPaused(): bool
    {
        $options = $this->speedSyncUtility->getGlobalOptions();
        return $options->isTransferPaused() || $options->isZfsPaused();
    }

    /**
     * Command SpeedSync to pause globally, stopping all offsite transfers
     *
     * @return bool Whether speedsync was successfully paused
     */
    private function pauseSpeedSync(): bool
    {
        $zfs = $this->speedSyncUtility->pauseDeviceZfs();
        $transfer = $this->speedSyncUtility->pauseDeviceTransfer();

        if (!$transfer || !$zfs) {
            $this->logger->warning('SSM0007 Error pausing speedsync', ['zfs' => $zfs, 'transfer' => $transfer]);
        }

        return $zfs && $transfer;
    }

    /**
     * Command SpeedSync to resume globally. Any per-asset pauses will remain, and will not be automatically resumed
     *
     * @return bool Whether speedsync was successfully resumed
     */
    private function resumeSpeedSync(): bool
    {
        $zfs = $this->speedSyncUtility->resumeDeviceZfs();
        $transfer = $this->speedSyncUtility->resumeDeviceTransfer();

        if (!$transfer || !$zfs) {
            $this->logger->warning('SSM0008 Error resuming speedsync', ['zfs' => $zfs, 'transfer' => $transfer]);
        }

        return $zfs && $transfer;
    }

    /**
     * Notifies device-web when a speedsync pause is put in place.
     *
     * @return void
     */
    private function notifyDeviceWeb(): void
    {
        if ($this->shouldBePaused()) {
            if ($this->isDelayIndefinite()) {
                $this->speedSyncPauseNotifier->sendDevicePaused(self::MAX_DELAY_HOURS);
            } else {
                // Convert the pause time remaining into hours, rounded up
                $durationSeconds = (float)max($this->getResumeTime() - $this->timeService->getTime(), 0);
                $durationHours = (int)ceil($durationSeconds / DateTimeService::SECONDS_PER_HOUR);
                $this->speedSyncPauseNotifier->sendDevicePaused($durationHours);
            }
        } else {
            $this->speedSyncPauseNotifier->sendDevicePaused(0);
        }
    }

    /**
     * Get the full name and path of the key file that indicates when an asset is paused
     *
     * @param string $assetKeyName
     * @return string
     */
    private function assetPausedFile(string $assetKeyName): string
    {
        return Agent::KEYBASE . $assetKeyName . '.' . self::ASSET_PAUSED_KEY;
    }

    /**
     * Return whether SpeedSync offsite synchronization is paused for the given dataset
     *
     * @param string $zfsPath
     * @return bool
     */
    private function isDatasetPaused(string $zfsPath): bool
    {
        try {
            $options = $this->speedSyncUtility->getDatasetOptions($zfsPath);
            return $options->isTransferPaused() || $options->isZfsPaused();
        } catch (Exception $e) {
            $message = 'Unable to parse speedsync options for the given path: ' . $zfsPath;
            throw new SpeedSyncPathException($message, $e->getCode(), $e);
        }
    }

    /**
     * Pause SpeedSync offsite synchronization for the given dataset
     *
     * @param string $zfsPath
     * @return bool Whether speedsync was successfully paused for the given zfs dataset
     */
    private function pauseDataset(string $zfsPath): bool
    {
        $zfs = $this->speedSyncUtility->pauseZfs($zfsPath);
        $transfer = $this->speedSyncUtility->pauseTransfer($zfsPath);

        if (!$transfer || !$zfs) {
            $this->logger->warning('SSM0009 Error pausing speedsync for dataset', [
                'path' => $zfsPath,
                'zfs' => $zfs,
                'transfer' => $transfer
            ]);
        }

        return $zfs && $transfer;
    }

    /**
     * Resume SpeedSync offsite synchronization for the given dataset
     *
     * @param string $zfsPath
     * @return bool Whether speedsync was successfully resumed for the given zfs path
     */
    private function resumeDataset(string $zfsPath): bool
    {
        $zfs = $this->speedSyncUtility->resumeZfs($zfsPath);
        $transfer = $this->speedSyncUtility->resumeTransfer($zfsPath);

        if (!$transfer || !$zfs) {
            $this->logger->warning('SSM0010 Error resuming speedsync for dataset', [
                'path' => $zfsPath,
                'zfs' => $zfs,
                'transfer' => $transfer
            ]);
        }

        return $zfs && $transfer;
    }
}
