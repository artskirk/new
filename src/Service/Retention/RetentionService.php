<?php

namespace Datto\Service\Retention;

use Datto\Alert\AlertManager;
use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Retention\ScreenshotRetentionService;
use Datto\Billing\Service as BillingService;
use Datto\Common\Resource\ProcessFactory;
use Datto\Log\LoggerAwareTrait;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Datto\Service\Retention\Exception\RetentionCannotRunException;
use Datto\Service\Retention\Strategy\RetentionStrategyInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LogLevel;

/**
 * Service class to handle asset retention.
 *
 * The main focus is to perform deletion of given asset's recovery points as per
 * the retention schedule. This class handles retention of recovery points in
 * general, and does not focus on retention-type specific handling - this task
 * is delegated to RetentionStrategyInterface whose implementors deal with the
 * type differences. This class just calls the interface methods and is
 * indifferent to the underlying retention type.
 *
 * @author Dawid Zamirski <dzamirski@datto.com>
 */
class RetentionService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const LAST_CHECKIN_EPOCH_FILE = '/datto/config/lastCheckinEpoch';
    const RETENTION_HOURS_INFINITY = 240000;
    const RETENTION_LOCK_FILE_LOCATION = '/dev/shm';

    private BillingService $billingService;
    private ScreenshotRetentionService $screenshotService;
    private Filesystem $filesystem;
    private ProcessFactory $processFactory;
    private DateTimeService $dateTimeService;
    private AlertManager $alertManager;
    private RetentionStrategyInterface $retention;

    public function __construct(
        BillingService $billingService,
        ScreenshotRetentionService $screenshotService,
        Filesystem $filesystem,
        ProcessFactory $processFactory,
        DateTimeService $dateTimeService,
        AlertManager $alertManager
    ) {
        $this->billingService = $billingService;
        $this->screenshotService = $screenshotService;
        $this->filesystem = $filesystem;
        $this->processFactory = $processFactory;
        $this->dateTimeService = $dateTimeService;
        $this->alertManager = $alertManager;
    }

    /**
     * Runs the retention
     *
     * @param RetentionStrategyInterface $retention
     * @param bool $isNightly if set will apply the nightly limit, otherwise the "ondemand"
     */
    public function doRetention(RetentionStrategyInterface $retention, bool $isNightly = false)
    {
        $this->setupContext($retention);

        $this->logger->info('RET0450 Starting Retention');

        $this->runPreflightChecks();
        $this->lockRetention();

        $this->logger->info('RET0460 Running Retention Operations', [
            'retentionDescription' => ucfirst($this->retention->getDescription())
        ]);

        try {
            $this->runScreenshotRetention();
            $pointsToDelete = $this->getRetentionPointsForDeletion();

            if ($pointsToDelete) {
                $this->retention->deleteRecoveryPoints(
                    $pointsToDelete,
                    $isNightly
                );
            }
        } catch (RetentionCannotRunException $ex) {
            $this->logger->log($ex->getLogLevel(), $ex->getMessage());

            throw $ex;
        } finally {
            $this->unlockRetention();
        }
    }

    /**
     * Gets a list of snapshots to be killed by the current retention settings.
     *
     * @param RetentionStrategyInterface $retention
     * @return array Epochs and sizes of snapshots which would be removed by retention
     *   Each index of the returned array will be an int array with the following keys
     *     - 'time': int The epoch of the snapshot
     *     - 'size': int The size of the snapshot in bytes
     */
    public function dryRunRetention(RetentionStrategyInterface $retention): array
    {
        $this->setupContext($retention);
        $this->logger->info('RET0610 Performing a dry run of retention');
        $toDelete = [];

        try {
            $this->checkRetentionAlreadyRunning();
            $this->checkRetentionSettings();
            $this->lockRetention();
            $this->logger->info('RET0625 Generating a list of snapshots to be deleted by retention');
            $toDelete = $this->getRetentionPointsForDeletion();
        } catch (RetentionCannotRunException $ex) {
            $this->logger->warning('RET0615 Skipping dry run', ['exception' => $ex]);

            throw $ex;
        } finally {
            $this->unlockRetention();
        }

        rsort($toDelete);
        $toDelete = array_combine($toDelete, $toDelete);

        $assetSnapshotsInfo = $this->loadAssetSnapshotsInfo();

        return array_intersect_key($assetSnapshotsInfo, $toDelete);
    }

    /**
     * Sets private variables used in private methods.
     *
     * To avoid passing the same parameters over and over again.
     *
     * @param RetentionStrategyInterface $retention
     */
    private function setupContext(RetentionStrategyInterface $retention)
    {
        $this->retention = $retention;
        $this->logger->setAssetContext($retention->getAsset()->getKeyName());
    }

    /**
     * Runs a series of pre-flight checks.
     *
     * If any of the checks fails, RetentionCannotRunException is caught
     * and logged according to the serverity level returned by exception code.
     * The exception is then re-thown to allow it to bubble up.
     */
    private function runPreflightChecks()
    {
        try {
            $this->checkIsInService();
            $this->checkIsFeatureSupported();
            $this->checkIsDisabledByAdmin();
            $this->checkClockInSync();
            $this->checkSupportedAsset();
            $this->checkIsPaused();
            $this->checkRetentionSettings();
            $this->checkRetentionAlreadyRunning();
            $this->checkAssetMigrating();
        } catch (RetentionCannotRunException $ex) {
            $this->logger->log($ex->getLogLevel(), $ex->getMessage());

            throw $ex;
        }
    }

    private function checkIsInService()
    {
        if ($this->billingService->isOutOfService()) {
            throw new RetentionCannotRunException(
                'RET0454 Device is out of service (service expired over 30 days ago). ' .
                'Skipping retention.'
            );
        }
    }

    private function checkIsFeatureSupported()
    {
        if (!$this->retention->isSupported()) {
            throw new RetentionCannotRunException(sprintf(
                'RET0455 Device does not support %s retention. Skipping retention.',
                $this->retention->getDescription()
            ));
        }
    }

    private function checkIsDisabledByAdmin()
    {
        if ($this->retention->isDisabledByAdmin()) {
            throw new RetentionCannotRunException(sprintf(
                'RET0465 Admin Override is set to disabled. Stopping %s retention',
                $this->retention->getDescription()
            ));
        }
    }

    private function checkClockInSync()
    {
        // This probably could make use of CheckinService::getSecondsSince but
        // there are subtle differences, so leaving as is for now.
        $localTime  = $this->dateTimeService->getTime();
        $serverTime = $this->filesystem->exists(self::LAST_CHECKIN_EPOCH_FILE)
            ? (int)$this->filesystem->fileGetContents(self::LAST_CHECKIN_EPOCH_FILE)
            : $localTime;

        if (abs($localTime - $serverTime) >= DateTimeService::SECONDS_PER_DAY) {
            throw new RetentionCannotRunException(
                'RET1960 Local clock has skewed too far from server clock. Skipping retention.'
            );
        }
    }

    private function checkSupportedAsset()
    {
        $asset = $this->retention->getAsset();

        if (!$this->retention->isArchiveRemovalSupported() && $asset->getLocal()->isArchived()) {
            throw new RetentionCannotRunException(
                'RET0458 Agent is archived. Skipping retention.',
                LogLevel::INFO
            );
        }

        $assetKey = $asset->getKeyName();
        if ($asset->getOriginDevice()->isReplicated()) {
            throw new RetentionCannotRunException(
                sprintf(
                    'RET0459 Agent "%s" is replicated from another device. Skipping retention.',
                    $assetKey
                ),
                LogLevel::INFO
            );
        }
    }

    private function checkIsPaused()
    {
        if ($this->retention->isPaused()) {
            throw new RetentionCannotRunException(
                'RET0466 Speedsync is currently paused. Stopping offsite retention',
                LogLevel::INFO
            );
        }
    }

    /**
     * Checks if retention appears to be already running.
     *
     * Will also trigger alert codes if the retention has been taking too long.
     */
    private function checkRetentionAlreadyRunning()
    {
        $lockFile = $this->retention->getLockFilePath();
        $this->alertManager->clearAlert($this->retention->getAsset()->getKeyName(), 'RET1920');

        if (!$this->filesystem->exists($lockFile)) {
            return true;
        }

        $this->logger->info(
            'RET1915 We\'ve detected a running retention flag. Confirming before continuing.',
            ['lockFile' => $lockFile]
        );

        if ($this->hasValidRetentionLock($lockFile)) {
            throw new RetentionCannotRunException(sprintf(
                'RET1930 %s retention is already running ... skipping.',
                ucfirst($this->retention->getDescription())
            ));
        }

        if ($this->isRetentionProcessRunning()) {
            // this RET1920 log will set the alert
            throw new RetentionCannotRunException(sprintf(
                'RET1920 %s retention is still running after 3 hours... Please contact support.',
                ucfirst($this->retention->getDescription())
            ));
        }

        $this->logger->warning(
            'RET1925 Could not locate running retention process. Removing the retention running flag file and continuing.',
            ['lockFile' => $lockFile]
        );

        $this->filesystem->unlink($lockFile);
    }

    private function checkAssetMigrating()
    {
        $asset = $this->retention->getAsset();
        $assetKey = $asset->getKeyName();
        if ($asset->getLocal()->isMigrationInProgress()) {
            throw new RetentionCannotRunException(
                sprintf(
                    'RET0467 Migration in progress for agent "%s".',
                    $assetKey
                ),
                LogLevel::INFO
            );
        }
    }

    private function runScreenshotRetention()
    {
        $asset = $this->retention->getAsset();
        if ($asset instanceof Agent) {
            $this->screenshotService->removeOutdatedScreenshots($asset->getKeyName());
        }
    }

    /**
     * Checks whether retention settings are valid.
     *
     * @todo This looks like pretty useless validation - it either should be
     *   more robust and check for valid values (not just if it's 0) or drop
     *   this check altogether. Original code did just that so leaving for now.
     */
    private function checkRetentionSettings()
    {
        $retention = $this->retention->getSettings();

        if ($retention->getDaily() === 0 ||
            $retention->getWeekly() === 0 ||
            $retention->getMonthly() === 0 ||
            $retention->getMaximum() === 0
        ) {
            throw new RetentionCannotRunException(sprintf(
                'RET0477 %s has invalid retention settings, exiting.',
                $this->retention->getAsset()->getKeyName()
            ));
        }
    }

    private function lockRetention()
    {
        $file = $this->retention->getLockFilePath();
        $this->filesystem->filePutContents($file, (string) $this->dateTimeService->getTime());
    }

    private function unlockRetention()
    {
        $file = $this->retention->getLockFilePath();
        $this->filesystem->unlink($file);
    }

    /**
     * Check if the lock file has been there for a while.
     *
     * Currently, 3 hours is the threshold.
     *
     * @param string $lockFile
     *
     * @return bool
     */
    private function hasValidRetentionLock(string $lockFile): bool
    {
        $threeHoursAgo = $this->dateTimeService->getElapsedTime(
            3 * DateTimeService::SECONDS_PER_HOUR
        );
        $lockTime = (int) $this->filesystem->fileGetContents($lockFile);

        return $lockTime >= $threeHoursAgo;
    }

    /**
     * Checks whether the retention process is actually running.
     *
     * Because we can't solely rely on lock files... :-(
     *
     * @return bool
     */
    private function isRetentionProcessRunning(): bool
    {
        $process = $this->processFactory->get([
            'pgrep',
            '-c',
            $this->retention->getProcessNamePattern()
        ]);

        return $process->run() === 0;
    }

    /**
     * @return int[]
     */
    private function getRetentionPointsForDeletion(): array
    {
        $settings = $this->retention->getSettings();
        $points = $this->retention->getRecoveryPoints();
        $now = $this->dateTimeService->getTime();

        $oldestEpochToKeep = $this->getWindowStartTime(
            $now,
            $settings->getMaximum(),
            0
        );
        $oldestWeeklyEpochToKeep = $this->getWindowStartTime(
            $now,
            $settings->getMonthly(),
            $oldestEpochToKeep
        );
        $oldestDailyEpochToKeep = $this->getWindowStartTime(
            $now,
            $settings->getWeekly(),
            $oldestWeeklyEpochToKeep
        );
        $oldestIntraDailyEpochToKeep = $this->getWindowStartTime(
            $now,
            $settings->getDaily(),
            $oldestDailyEpochToKeep
        );

        $logTimeFormat = 'm/d/Y g:i:s A';
        $this->logger->debug(sprintf(
            'RET0505 Computed retention window thresholds are: ' .
            'intra-daily: %s [%s], daily: %s [%s], weekly: %s [%s], monthly: %s [%s]',
            $this->dateTimeService->format($logTimeFormat, $oldestIntraDailyEpochToKeep),
            $oldestIntraDailyEpochToKeep,
            $this->dateTimeService->format($logTimeFormat, $oldestDailyEpochToKeep),
            $oldestDailyEpochToKeep,
            $this->dateTimeService->format($logTimeFormat, $oldestWeeklyEpochToKeep),
            $oldestWeeklyEpochToKeep,
            $this->dateTimeService->format($logTimeFormat, $oldestEpochToKeep),
            $oldestEpochToKeep
        ));

        $retentionFormats = [
            'U' => $oldestIntraDailyEpochToKeep,
            'zY' => $oldestDailyEpochToKeep,
            'WY' => $oldestWeeklyEpochToKeep,
            'nY' => $oldestEpochToKeep
        ];

        sort($points);

        $this->logger->debug('RET0508 Total point count to check against retention schedule: ' . count($points));

        // for each retention window pick which points to keep.
        $pointsToKeep = [];
        foreach ($retentionFormats as $format => $startTime) {
            $intervalKeep = [];
            foreach ($points as $point) {
                if ($startTime <= $point) {
                    $pointInterval = $this->dateTimeService->format($format, $point);
                    $intervalKeep[$pointInterval] = $point;
                }
            }

            $pointsToKeep = array_merge($pointsToKeep, array_values($intervalKeep));
        }

        // diff will return points to delete
        $toDelete = array_values(array_diff($points, $pointsToKeep));

        if (!$toDelete) {
            $this->logger->info('RET0478 No points found for deletion, exiting.');
        }

        return $toDelete;
    }

    /**
     * Compute start epoch based on retention window settings.
     *
     * @todo This implementaion was moved from original snapSchedule.php but
     * seeing how UI ensures that each retention window is successive after
     * another (the don't overlap). This method may not be needed and the
     * calculation simplified to just $window = $now - $retentionHours * 3600;
     *
     * @param int $startTime
     * @param int $retentionHours
     * @param int $default
     *
     * @return int
     */
    private function getWindowStartTime(int $startTime, int $retentionHours, int $default): int
    {
        if ($retentionHours === self::RETENTION_HOURS_INFINITY) {
            return $default;
        }

        $windowEpoch = $startTime - $retentionHours * DateTimeService::SECONDS_PER_HOUR;

        // the retention windows can overlap so pick the
        return max($windowEpoch, $default);
    }

    /**
     * Get the contents of an assets's .transfers key file.
     *
     * @return array[] Epochs and sizes of snapshots which would be removed by retention
     *   Each index of the returned array will be an int array with the following keys
     *     - 'time': int The epoch of the snapshot
     *     - 'size': int The size of the snapshot in bytes
     */
    private function loadAssetSnapshotsInfo(): array
    {
        $snapshotsInfo = [];
        $transfersPath = $this->getTransfersPath();

        if (!$this->filesystem->exists($transfersPath)) {
            return $snapshotsInfo;
        }

        $transfersContents = $this->filesystem->file($transfersPath);
        foreach ($transfersContents as $line) {
            $data = explode(':', trim($line));
            if (count($data) < 2) {
                continue;
            }

            $time = intval($data[0]);
            $size = intval($data[1]);

            $snapshotsInfo[$time] = [
                'time' => $time,
                'size' => $size
            ];
        }

        return $snapshotsInfo;
    }

    private function getTransfersPath(): string
    {
        $transfersPath = sprintf(
            '%s%s.transfers',
            Agent::KEYBASE,
            $this->retention->getAsset()->getKeyName()
        );

        return $transfersPath;
    }
}
