<?php

namespace Datto\Asset\RecoveryPoint;

use Datto\AppKernel;
use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\VolumeMetadata;
use Datto\Asset\Agent\Volumes;
use Datto\Asset\Agent\Windows\BackupSettings;
use Datto\Asset\ApplicationResult;
use Datto\Asset\Asset;
use Datto\Asset\AssetService;
use Datto\Asset\AssetType;
use Datto\Asset\RansomwareResults;
use Datto\Asset\VerificationScriptOutput;
use Datto\Asset\VerificationScriptsResults;
use Datto\Cloud\SpeedSync;
use Datto\Cloud\SpeedSyncCache;
use Datto\Cloud\SpeedSyncCacheEntry;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\AgentConfig;
use Datto\Config\AgentConfigFactory;
use Datto\Feature\FeatureService;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Datto\Restore\Restore;
use Datto\Restore\RestoreService;
use Datto\Screenshot\ScreenshotFileRepository;
use Datto\Util\DateTimeZoneService;
use Datto\Verification\InProgressVerification;
use Datto\Verification\Notification\VerificationResults;
use Datto\Verification\VerificationService;
use Datto\ZFS\ZfsCache;
use Datto\ZFS\ZfsCacheEntry;
use Datto\ZFS\ZfsService;

/**
 * Service responsible for gathering all required information about recovery-points.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class RecoveryPointInfoService
{
    // These constants are used by Continuity Audit (index.html.twig and pdf.html.twig)
    const OFFSITE_STATUS_QUEUED = 0;
    const OFFSITE_STATUS_PROGRESS = 1;

    /** @var ScreenshotFileRepository */
    private $screenshotFileRepository;

    /** @var Filesystem */
    private $filesystem;

    /** @var SpeedSync */
    private $speedsync;

    /** @var SpeedSyncCache */
    private $speedsyncCache = null;

    /** @var RestoreService */
    private $restoreService;

    /** @var Restore[]|null */
    private $restoresCache = null;

    /** @var AssetService */
    private $assetService;

    /** @var ZfsService */
    private $zfsService;

    /**@var ZfsCache */
    private $zfsCache;

    /** @var VerificationService */
    private $verificationService;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var AgentConfigFactory */
    private $agentConfigFactory;

    /** @var FeatureService */
    private $featureService;

    /** @var DateTimeZoneService */
    private $dateTimeZoneService;

    /** @var array */
    private $transfers;

    public function __construct(
        ScreenshotFileRepository $screenshotFileRepository = null,
        Filesystem $filesystem = null,
        SpeedSync $speedsync = null,
        RestoreService $restoreService = null,
        AssetService $assetService = null,
        ZfsService $zfsService = null,
        VerificationService $verificationService = null,
        DateTimeService $dateTimeService = null,
        DateTimeZoneService $dateTimeZoneService = null,
        AgentConfigFactory $agentConfigFactory = null,
        FeatureService $featureService = null
    ) {
        $this->screenshotFileRepository = $screenshotFileRepository ?: new ScreenshotFileRepository();
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->speedsync = $speedsync ?: new SpeedSync();
        $this->restoreService = $restoreService ?: AppKernel::getBootedInstance()->getContainer()->get(RestoreService::class);
        $this->assetService = $assetService ?: new AssetService();
        $this->zfsService = $zfsService ?: new ZfsService();
        $this->verificationService = $verificationService
            ?: AppKernel::getBootedInstance()->getContainer()->get(VerificationService::class);
        $this->dateTimeService = $dateTimeService ?: new DateTimeService();
        $this->dateTimeZoneService = $dateTimeZoneService ?: new DateTimeZoneService();
        $this->agentConfigFactory = $agentConfigFactory ?: new AgentConfigFactory();
        $this->featureService = $featureService ?: new FeatureService();
    }

    /**
     * Get information for a specific recovery-point.
     *
     * @param Asset $asset
     * @param int $snapshotEpoch
     * @param bool $includeOffsiteStatus If you're only interested in local information,
     *      pass this parameter in to avoid any calls to speedsync, which slow the function down.
     *
     * @return RecoveryPointInfo|null
     */
    public function get(Asset $asset, int $snapshotEpoch, bool $includeOffsiteStatus = true)
    {
        $this->readCaches();

        $existsLocally = $this->existsLocally($asset, $snapshotEpoch);
        $existsOffsite = $includeOffsiteStatus && (bool)$this->existsOffsite($asset, $snapshotEpoch);
        if (!$existsLocally && !$existsOffsite) {
            return null;
        }

        // Offsite Information
        if ($includeOffsiteStatus) {
            $isCritical = (bool)$this->isCriticalSnapshotEpoch($asset, $snapshotEpoch);
            $offsiteStatus = (int)$this->determineOffsiteStatus($asset, $snapshotEpoch);
            $offsiteRestores = $this->getOffsiteRestores($asset, $snapshotEpoch);
        } else {
            $isCritical = false;
            $offsiteStatus = SpeedSync::OFFSITE_NONE;
            $offsiteRestores = [];
        }

        $backupTimeFormatted = $this->getBackupTimeFormatted($snapshotEpoch);

        // Local Information
        $screenshotStatus = $this->determineScreenshotStatus($asset, $snapshotEpoch);
        $transferSize = (int)$this->getTransferSize($asset, $snapshotEpoch);
        $localUsedSize = (int)$this->getLocalUsedSize($asset, $snapshotEpoch);
        $localRestores = $this->getLocalRestores($asset, $snapshotEpoch);
        $volumeBackupTypes = $this->getVolumeBackupTypes($asset, $snapshotEpoch);
        $remainingScreenshotTime = $this->getRemainingScreenshotTime($asset, $snapshotEpoch);
        $hasRestores = count($localRestores) >= 1 || count($offsiteRestores) >= 1;
        $hasScreenshotPending = $screenshotStatus === RecoveryPoint::SCREENSHOT_INPROGRESS
            || $screenshotStatus === RecoveryPoint::SCREENSHOT_QUEUED;
        $wasBackupForced = $this->wasBackupForced($asset, $snapshotEpoch);
        $osUpdatePending = $this->wasOsUpdatePending($asset, $snapshotEpoch);
        $volumes = $this->getVolumes($asset);

        // Local Verification
        $deletionTime = $this->getDeletionTime($asset, $snapshotEpoch);
        $deletionTimeFormatted = $deletionTime ? $this->getBackupTimeFormatted($deletionTime) : '';
        $deletionReason = $this->getDeletionReason($asset, $snapshotEpoch);
        $missingVolumes = $this->getMissingVolumes($asset, $snapshotEpoch);
        $filesystemIntegritySummary = $this->getFilesystemIntegritySummary($asset, $snapshotEpoch);
        $ransomwareResults = $this->getRansomwareResults($asset, $snapshotEpoch);
        $hasLocalVerificationError = $this->hasLocalVerificationError($asset, $snapshotEpoch);
        $engineUsed = $this->getEngineUsed($asset, $snapshotEpoch);
        $engineConfigured = $this->getEngineConfigured($asset, $snapshotEpoch);
        $engineFailedOver = $this->hasEngineFailedOver($engineUsed, $engineConfigured);
        $filesystemCheckResults = $this->getFilesystemCheckResults($asset, $snapshotEpoch);

        // Advanced Verification
        $verificationScriptResults = $this->getVerificationScriptResults($asset, $snapshotEpoch);
        $applicationResults = $this->getApplicationResults($asset, $snapshotEpoch);
        $hasApplicationVerificationError = $this->hasApplicationVerificationError($applicationResults);
        $serviceResults = $this->getServiceResults($asset, $snapshotEpoch);
        $hasScreenshotVerificationError = $screenshotStatus === RecoveryPoint::UNSUCCESSFUL_SCREENSHOT;
        $hasServiceVerificationError = $this->hasServiceVerificationError($serviceResults);
        $hasAdvancedVerificationError = $this->hasAdvancedVerificationError(
            $applicationResults,
            $serviceResults,
            $screenshotStatus,
            $verificationScriptResults
        );

        return new RecoveryPointInfo(
            $snapshotEpoch,
            $existsLocally,
            $existsOffsite,
            $isCritical,
            $screenshotStatus,
            $offsiteStatus,
            $transferSize,
            $localUsedSize,
            $localRestores,
            $offsiteRestores,
            $volumeBackupTypes,
            $backupTimeFormatted,
            $filesystemIntegritySummary,
            $ransomwareResults,
            $verificationScriptResults,
            $remainingScreenshotTime,
            $missingVolumes,
            $hasLocalVerificationError,
            $engineUsed,
            $engineConfigured,
            $engineFailedOver,
            $hasRestores,
            $hasScreenshotPending,
            $deletionTime,
            $deletionTimeFormatted,
            $deletionReason,
            $filesystemCheckResults,
            $hasScreenshotVerificationError,
            $applicationResults,
            $hasApplicationVerificationError,
            $serviceResults,
            $hasServiceVerificationError,
            $hasAdvancedVerificationError,
            $wasBackupForced,
            $osUpdatePending,
            $volumes
        );
    }

    /**
     * Load all caches from disk
     */
    public function readCaches(): void
    {
        if ($this->speedsyncCache === null) {
            $this->speedsyncCache = $this->speedsync->readCache();
            $this->zfsCache = $this->zfsService->readCache();
        }
    }

    /**
     * Refresh cache and re-read cache if previously loaded.
     *
     * @param Asset $asset
     */
    public function refreshCaches(Asset $asset): void
    {
        $zfsPath = $asset->getDataset()->getZfsPath();

        $this->speedsync->writeCache([$zfsPath]);
        $this->zfsService->writeCache([$zfsPath]);
    }

    /**
     * Returns backup point time based on the device time zone
     * @param int $snapshotEpoch
     * @return string
     */
    public function getBackupTimeFormatted(int $snapshotEpoch): string
    {
        $format = $this->dateTimeZoneService->universalDateFormat('date-time-short-tz');
        return (string) $this->dateTimeService->getDate($format, $snapshotEpoch);
    }

    /**
     * Returns all recovery point information for the specified asset as an array
     *
     * @param Asset $asset
     * @return array
     */
    public function getRecoveryPointsInfoAsArray(Asset $asset): array
    {
        $recoveryPointsInfo = $this->getAll($asset);

        krsort($recoveryPointsInfo);

        $hasNoOffsite = true;
        $recoveryPointsInfoArray = [];
        foreach ($recoveryPointsInfo as $snapshotEpoch => $recoveryPointInfo) {
            $recoveryPointsInfoArray[$snapshotEpoch] = $recoveryPointInfo->toArray();
            if ($hasNoOffsite && $recoveryPointInfo->existsOffsite()) {
                $hasNoOffsite = false;
            }
            $recoveryPointsInfoArray[$snapshotEpoch]['offsitePossible'] = $hasNoOffsite;
        }

        return $recoveryPointsInfoArray;
    }

    /**
     * Returns local only recovery point information for the specified asset as an array
     *
     * @param Asset $asset
     * @return array
     */
    public function getLocalRecoveryPointsInfoAsArray(Asset $asset): array
    {
        $recoveryPointsInfo = $this->getLocal($asset);

        krsort($recoveryPointsInfo);

        $recoveryPointsInfoArray = [];
        foreach ($recoveryPointsInfo as $snapshotEpoch => $recoveryPointInfo) {
            $recoveryPointsInfoArray[$snapshotEpoch] = $recoveryPointInfo->toArray();
        }

        return $recoveryPointsInfoArray;
    }

    /**
     * Get information for all recovery-points.
     *
     * @param Asset $asset
     * @return RecoveryPointInfo[]
     */
    public function getAll(Asset $asset): array
    {
        $this->readCaches();

        $snapshotEpochs = $this->getAllSnapshotEpochs($asset);

        $informationObjects = [];
        foreach ($snapshotEpochs as $snapshotEpoch) {
            $point = $this->get($asset, $snapshotEpoch);
            if ($point !== null) {
                $informationObjects[$snapshotEpoch] = $point;
            }
        }

        return $informationObjects;
    }

    /**
     * Get information for local only recovery-points.
     *
     * @param Asset $asset
     * @return RecoveryPointInfo[]
     */
    public function getLocal(Asset $asset): array
    {
        // Reload asset to get up-to-date data
        $asset = $this->assetService->get($asset->getKeyName());
        $snapshotEpochs = $this->getLocalSnapshotEpochs($asset);

        $informationObjects = [];
        foreach ($snapshotEpochs as $snapshotEpoch) {
            $point = $this->get($asset, $snapshotEpoch, false); // Exclude offsite status
            if ($point !== null) {
                $informationObjects[$snapshotEpoch] = $point;
            }
        }

        return $informationObjects;
    }

    /**
     * Refresh the recoveryPoints and recoveryPointsMeta key files for an asset
     *
     * @param Asset $asset
     */
    public function refreshKeys(Asset $asset): void
    {
        $datasetPath = $asset->getDataset()->getZfsPath();
        $snapshots = $this->zfsService->getSnapshots($datasetPath);

        $recoveryPoints = $asset->getLocal()->getRecoveryPoints();

        $newRecoveryPoints = false;
        foreach ($snapshots as $epoch) {
            if (!$recoveryPoints->exists((string) $epoch)) {
                $recoveryPoint = new RecoveryPoint($epoch);
                $recoveryPoints->add($recoveryPoint);
                $newRecoveryPoints = true;
            }
        }

        // Saving can result in clobbering a recovery point that was added during backup.
        // Avoid saving when the recovery points haven't changed. See CP-12516
        if ($newRecoveryPoints) {
            $asset->getLocal()->setRecoveryPoints($recoveryPoints);
            $this->assetService->save($asset);
        }
    }

    /**
     * @param Asset $asset
     * @return int[]
     */
    public function getLocalSnapshotEpochs(Asset $asset): array
    {
        return $asset->getLocal()
            ->getRecoveryPoints()
            ->getAllRecoveryPointTimes();
    }

    /**
     * Get the screenshot status for a particular snapshot.
     *
     * @param Asset $asset
     * @param int $snapshotEpoch
     * @return int one of the `SCREENSHOT` constants from \Datto\Asset\RecoveryPoint\RecoveryPoint
     * @see \Datto\Asset\RecoveryPoint\RecoveryPoint
     */
    public function determineScreenshotStatus(Asset $asset, int $snapshotEpoch): int
    {
        $status = $this->determineScreenshotStatusFromVerificationQueue($asset, $snapshotEpoch);
        if ($status !== RecoveryPoint::SCREENSHOT_NOT_IN_QUEUE) {
            return $status;
        }

        $snapshot = $asset->getLocal()->getRecoveryPoints()->get($snapshotEpoch);
        if ($snapshot === null) {
            return $this->determineScreenshotStatusFromExtensions($asset, $snapshotEpoch);
        }

        $screenshotResult = $snapshot->getVerificationScreenshotResult();
        if ($screenshotResult === null) {
            return $this->determineScreenshotStatusFromExtensions($asset, $snapshotEpoch);
        }

        return $this->selectScreenshotStatus(
            $screenshotResult->getFailureAnalysis() !== VerificationResults::SCREENSHOT_ANALYSIS_NO_FAILURE,
            $screenshotResult->hasScreenshot()
        );
    }

    /**
     * @param Asset $asset
     * @param int $snapshotEpoch
     * @return array
     */
    private function getFilesystemCheckResults(Asset $asset, int $snapshotEpoch): array
    {
        $recoveryPoint = $asset->getLocal()->getRecoveryPoints()->get($snapshotEpoch);

        if ($recoveryPoint) {
            return $recoveryPoint->getFilesystemCheckResults();
        } else {
            return [];
        }
    }

    /**
     * @param string|null $engineUsed
     * @param string|null $engineConfigured
     * @return bool
     */
    private function hasEngineFailedOver(string $engineUsed = null, string $engineConfigured = null): bool
    {
        return $engineUsed !== null &&
            $engineConfigured !== null &&
            $engineConfigured === BackupSettings::DEFAULT_BACKUP_ENGINE &&
            $engineUsed !== BackupSettings::VSS_BACKUP_ENGINE;
    }

    /**
     * @param Asset $asset
     * @param int $snapshotEpoch
     * @return string[]
     */
    private function getVolumeBackupTypes(Asset $asset, int $snapshotEpoch): array
    {
        $recoveryPoint = $asset->getLocal()
            ->getRecoveryPoints()
            ->get($snapshotEpoch);

        if ($recoveryPoint) {
            return $recoveryPoint->getVolumeBackupTypes();
        } else {
            return [];
        }
    }

    /**
     * @param Asset $asset
     * @param int $snapshotEpoch
     * @return ApplicationResult[]
     */
    private function getApplicationResults(Asset $asset, int $snapshotEpoch): array
    {
        if (!$asset->isType(AssetType::AGENT)) {
            return [];
        }

        $recoveryPoint = $asset->getLocal()
            ->getRecoveryPoints()
            ->get($snapshotEpoch);

        if ($recoveryPoint) {
            return $recoveryPoint->getApplicationResults();
        } else {
            return [];
        }
    }

    /**
     * @param ApplicationResult[] $applicationResults
     * @return bool
     */
    private function hasApplicationVerificationError(array $applicationResults): bool
    {
        foreach ($applicationResults as $applicationResult) {
            if (!$applicationResult->detectionCompleted()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Asset $asset
     * @param int $snapshotEpoch
     * @return ApplicationResult[]
     */
    private function getServiceResults(Asset $asset, int $snapshotEpoch): array
    {
        if (!$asset->isType(AssetType::AGENT)) {
            return [];
        }

        $recoveryPoint = $asset->getLocal()
            ->getRecoveryPoints()
            ->get($snapshotEpoch);

        if ($recoveryPoint) {
            return $recoveryPoint->getServiceResults();
        } else {
            return [];
        }
    }

    /**
     * @param ApplicationResult[] $serviceResults
     * @return bool
     */
    private function hasServiceVerificationError(array $serviceResults): bool
    {
        foreach ($serviceResults as $serviceResult) {
            if (!$serviceResult->detectionCompleted()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Asset $asset
     * @param int $snapshotEpoch
     * @return Restore[]
     */
    private function getLocalRestores(Asset $asset, int $snapshotEpoch): array
    {
        // TODO: comparisons / backup insights?

        $restores = $this->getRestores($asset);

        $filter = function (Restore $restore) use ($snapshotEpoch) {
            return (int)$restore->getPoint() === $snapshotEpoch
                && !in_array($restore->getSuffix(), Restore::OFFSITE_RESTORE_SUFFIXES);
        };

        return array_values(array_filter($restores, $filter));
    }

    /**
     * @param Asset $asset
     * @param int $snapshotEpoch
     * @return Restore[]
     */
    private function getOffsiteRestores(Asset $asset, int $snapshotEpoch): array
    {
        $restores = $this->getRestores($asset);

        $filter = function (Restore $restore) use ($snapshotEpoch) {
            return (int)$restore->getPoint() === $snapshotEpoch
                && in_array($restore->getSuffix(), Restore::OFFSITE_RESTORE_SUFFIXES);
        };

        return array_values(array_filter($restores, $filter));
    }

    /**
     * @param Asset $asset
     * @return Restore[]
     */
    private function getRestores(Asset $asset)
    {
        $assetKey = $asset->getKeyName();

        if (!isset($this->restoresCache[$assetKey])) {
            $this->restoresCache[$assetKey] = $this->restoreService->getForAsset($assetKey);
        }

        return $this->restoresCache[$assetKey];
    }

    /**
     * @param string $zfsPath
     * @return SpeedSyncCacheEntry
     */
    private function getSpeedSyncCacheEntry(string $zfsPath): SpeedSyncCacheEntry
    {
        $entry = $this->speedsyncCache->getEntry($zfsPath);

        if ($entry === null) {
            $entry = new SpeedSyncCacheEntry($zfsPath);
            $this->speedsyncCache->setEntry($zfsPath, $entry);
        }

        return $entry;
    }

    /**
     * @param Asset $asset
     * @return int[]
     */
    private function getOffsiteQueuedSnapshotEpochs(Asset $asset): array
    {
        $zfsPath = $asset->getDataset()->getZfsPath();
        $entry = $this->getSpeedSyncCacheEntry($zfsPath);
        $points = $entry->getQueuedPoints();

        if ($points === null) {
            try {
                $points = $this->speedsync->getQueuedPoints($zfsPath);
            } catch (\Throwable $e) {
                $points = [];
            }

            $entry->setQueuedPoints($points);
        }

        return array_flip($points);
    }

    /**
     * @param Asset $asset
     * @return int[]
     */
    private function getOffsiteSyncedSnapshotEpochs(Asset $asset): array
    {
        $zfsPath = $asset->getDataset()->getZfsPath();
        $entry = $this->getSpeedSyncCacheEntry($zfsPath);
        $points = $entry->getOffsitePoints();

        if ($points === null) {
            try {
                $points = $this->speedsync->getOffsitePoints($zfsPath);
            } catch (\Throwable $e) {
                $points = [];
            }

            $entry->setOffsitePoints($points);
        }

        return array_flip($points);
    }

    /**
     * @return array
     */
    private function getOffsiteActions(): array
    {
        $actions = $this->speedsyncCache->getActions();

        if ($actions === null) {
            try {
                $actions = $this->speedsync->getOffsiteActions();
            } catch (\Throwable $e) {
                $actions = [];
            }

            $this->speedsyncCache->setActions($actions);
        }

        return $actions;
    }

    /**
     * @param string $snapshotString speedsync string describing a snapshot
     * @return int snapshot epoch
     */
    private function getPointFromSnapshotString(string $snapshotString): int
    {
        return (int) substr($snapshotString, strpos($snapshotString, '@') + 1);
    }

    /**
     * @param string $snapshotString speedsync string describing a snapshot
     * @return string asset keyName
     */
    private function getKeyNameFromSnapshotString(string $snapshotString): string
    {
        return substr(
            $snapshotString,
            strrpos($snapshotString, '/') + 1,
            strpos($snapshotString, '@') - strlen($snapshotString)
        );
    }

    /**
     * @param Asset $asset
     * @return array
     */
    private function getSyncingPointsForAsset(Asset $asset): array
    {
        $rawActions = $this->speedsyncCache->getActions() ?? [];
        $points = [];
        foreach ($rawActions as $action) {
            $snapshotString = $action['snapshot'];
            $actionAssetKeyName = $this->getKeyNameFromSnapshotString($snapshotString);
            $point = $this->getPointFromSnapshotString($snapshotString);

            if ($actionAssetKeyName === $asset->getKeyName()) {
                $points[$point] = $point;
            }
        }
        return $points;
    }

    /**
     * @param Asset $asset
     * @return int[]
     */
    private function getCriticalSnapshotEpochs(Asset $asset): array
    {
        $zfsPath = $asset->getDataset()->getZfsPath();
        $entry = $this->getSpeedSyncCacheEntry($zfsPath);
        $points = $entry->getCriticalPoints();

        if ($points === null) {
            try {
                $points = $this->speedsync->getCriticalPoints($zfsPath);
            } catch (\Throwable $e) {
                $points = [];
            }

            $entry->setCriticalPoints($points);
        }

        return array_flip($points);
    }

    /**
     * @param Asset $asset
     * @return array
     */
    private function getRemoteReplicatingPoints(Asset $asset): array
    {
        $zfsPath = $asset->getDataset()->getZfsPath();
        $entry = $this->getSpeedSyncCacheEntry($zfsPath);
        $points = $entry->getRemoteReplicatingPoints();

        if ($points === null) {
            $points = $this->speedsync->getRemoteReplicatingPoints($zfsPath);
            $entry->setRemoteReplicatingPoints($points);
        }

        return array_flip($points);
    }

    /**
     * Determine if there is a local verification error.
     *
     * @param Asset $asset
     * @param int $snapshotEpoch
     * @return bool
     */
    private function hasLocalVerificationError(Asset $asset, int $snapshotEpoch): bool
    {
        if (!$asset->isType(AssetType::AGENT)) {
            // Currently there's no verification for Shares.
            return false;
        }

        $ransomwareResults = $this->getRansomwareResults($asset, $snapshotEpoch);

        $hasRansomware =
            $asset->getLocal()->isRansomwareCheckEnabled() && $ransomwareResults && $ransomwareResults->hasRansomware();

        if ($hasRansomware) {
            // Ransomware is enough to flag an error.
            return true;
        }

        if ($asset->getLocal()->isIntegrityCheckEnabled()) {
            $hasMissingVolumes = count($this->getMissingVolumes($asset, $snapshotEpoch)) > 0;

            if ($hasMissingVolumes) {
                // If integrity verification is enabled and there are missing volumes, we flag an error.
                return true;
            }

            $engineUsed = $this->getEngineUsed($asset, $snapshotEpoch);
            $engineConfigured = $this->getEngineConfigured($asset, $snapshotEpoch);

            if ($this->hasEngineFailedOver($engineUsed, $engineConfigured)) {
                return true;
            }

            $filesystemIntegritySummary = $this->getFilesystemIntegritySummary($asset, $snapshotEpoch);

            if (!$filesystemIntegritySummary->hasAllHealthy()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if verifications run at screenshot time were successful (ie. scripts, application detection, etc).
     *
     * @param ApplicationResult[] $applicationResults
     * @param ApplicationResult[] $serviceResults
     * @param int $screenshotStatus
     * @param VerificationScriptsResults|null $verificationScriptsResults
     * @return bool
     */
    private function hasAdvancedVerificationError(
        array $applicationResults,
        array $serviceResults,
        int $screenshotStatus,
        VerificationScriptsResults $verificationScriptsResults = null
    ): bool {
        return $screenshotStatus === RecoveryPoint::UNSUCCESSFUL_SCREENSHOT
            || $this->hasScriptVerificationFailure($verificationScriptsResults)
            || $this->hasApplicationVerificationFailure($applicationResults)
            || $this->hasServiceVerificationFailure($serviceResults);
    }

    /**
     * @param VerificationScriptsResults|null $verificationScriptsResults
     * @return bool
     */
    private function hasScriptVerificationFailure(VerificationScriptsResults $verificationScriptsResults = null): bool
    {
        if ($verificationScriptsResults) {
            foreach ($verificationScriptsResults->getOutput() as $output) {
                $isComplete = $output->getState() === VerificationScriptOutput::SCRIPT_COMPLETE;

                if ($isComplete && $output->getExitCode() !== 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param ApplicationResult[] $applicationResults
     * @return bool
     */
    private function hasApplicationVerificationFailure(array $applicationResults = []): bool
    {
        if ($applicationResults) {
            foreach ($applicationResults as $applicationResult) {
                if (!$applicationResult->isRunning()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param ApplicationResult[] $serviceResults
     * @return bool
     */
    private function hasServiceVerificationFailure(array $serviceResults = []): bool
    {
        if ($serviceResults) {
            foreach ($serviceResults as $serviceResult) {
                if (!$serviceResult->isRunning()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param Asset $asset
     * @param int $snapshotEpoch
     * @return bool
     */
    private function isCriticalSnapshotEpoch(Asset $asset, int $snapshotEpoch): bool
    {
        $criticalSnapshotEpochs = $this->getCriticalSnapshotEpochs($asset);
        return isset($criticalSnapshotEpochs[$snapshotEpoch]);
    }

    /**
     * @param Asset $asset
     * @return int[]
     */
    private function getAllSnapshotEpochs(Asset $asset): array
    {
        $localSnapshots = $this->getLocalSnapshotEpochs($asset);
        $offsiteSnapshots = $this->getOffsiteSnapshotEpochs($asset);
        return array_merge($localSnapshots, $offsiteSnapshots);
    }

    /**
     * @param Asset $asset
     * @return int[]
     */
    private function getOffsiteSnapshotEpochs(Asset $asset): array
    {
        return $asset->getOffsite()
            ->getRecoveryPoints()
            ->getAllRecoveryPointTimes();
    }

    /**
     * @param Asset $asset
     * @param int $snapshotEpoch
     * @return RansomwareResults|null
     */
    private function getRansomwareResults(Asset $asset, int $snapshotEpoch)
    {
        $recoveryPoint = $asset->getLocal()
            ->getRecoveryPoints()
            ->get($snapshotEpoch);

        if ($recoveryPoint) {
            return $recoveryPoint->getRansomwareResults();
        } else {
            return null;
        }
    }

    /**
     * @param Asset $asset
     * @param int $snapshotEpoch
     * @return VerificationScriptsResults|null
     */
    private function getVerificationScriptResults(Asset $asset, int $snapshotEpoch)
    {
        $recoveryPoint = $asset->getLocal()
            ->getRecoveryPoints()
            ->get($snapshotEpoch);

        if ($recoveryPoint) {
            return $recoveryPoint->getVerificationScriptsResults();
        } else {
            return null;
        }
    }

    /**
     * @param Asset $asset
     * @param int $snapshotEpoch
     * @return bool
     */
    private function existsLocally(Asset $asset, int $snapshotEpoch): bool
    {
        $localSnapshots = $this->getAllLocalUsedSizes($asset);
        return isset($localSnapshots[$snapshotEpoch]);
    }

    /**
     * @param Asset $asset
     * @param int $snapshotEpoch
     * @return bool
     */
    private function existsOffsite(Asset $asset, int $snapshotEpoch): bool
    {
        $synced = $this->getOffsiteSyncedSnapshotEpochs($asset);

        return isset($synced[$snapshotEpoch]);
    }

    /**
     * @param Asset $asset
     * @param int $snapshotEpoch
     * @return VolumeMetadata[]
     */
    private function getMissingVolumes(Asset $asset, int $snapshotEpoch): array
    {
        $missingVolumes = [];

        $localPoint = $asset->getLocal()->getRecoveryPoints()->get($snapshotEpoch);
        $offsitePoint = $asset->getOffsite()->getRecoveryPoints()->get($snapshotEpoch);

        if ($localPoint) {
            $missingVolumes = $localPoint->getMissingVolumes() ?? [];
        } elseif ($offsitePoint) {
            $missingVolumes = $offsitePoint->getMissingVolumes() ?? [];
        }

        return $missingVolumes;
    }

    /**
     * @param Asset $asset
     * @param int $snapshotEpoch
     * @return int|null
     */
    private function getDeletionTime(Asset $asset, int $snapshotEpoch)
    {
        $localPoint = $asset->getLocal()->getRecoveryPoints()->get($snapshotEpoch);
        if ($localPoint) {
            return $localPoint->getDeletionTime();
        } else {
            return null;
        }
    }

    private function getDeletionReason(Asset $asset, int $snapshotEpoch): ?string
    {
        $localPoint = $asset->getLocal()->getRecoveryPoints()->get($snapshotEpoch);
        if ($localPoint) {
            return $localPoint->getDeletionReason();
        } else {
            return null;
        }
    }

    /**
     * @param Asset $asset
     * @param int $snapshotEpoch
     * @return bool|null
     */
    private function wasBackupForced(Asset $asset, int $snapshotEpoch)
    {
        $localPoint = $asset->getLocal()->getRecoveryPoints()->get($snapshotEpoch);
        if ($localPoint) {
            return $localPoint->wasBackupForced();
        } else {
            return null;
        }
    }

    private function wasOsUpdatePending(Asset $asset, int $snapshotEpoch): ?bool
    {
        $localPoint = $asset->getLocal()->getRecoveryPoints()->get($snapshotEpoch);
        if ($localPoint) {
            return $localPoint->wasOsUpdatePending();
        } else {
            return null;
        }
    }

    /**
     * @param Asset $asset
     * @param int $snapshotEpoch
     * @return FilesystemIntegritySummary
     */
    private function getFilesystemIntegritySummary(Asset $asset, int $snapshotEpoch): FilesystemIntegritySummary
    {
        $localPoint = $asset->getLocal()->getRecoveryPoints()->get($snapshotEpoch);
        if (!$localPoint || !$asset->isType(AssetType::AGENT)) {
            return FilesystemIntegritySummary::createEmpty();
        }

        /** @var Agent $asset Guaranteed to have an agent as this point. */

        return FilesystemIntegritySummary::createFromFilesystemCheckResults(
            $localPoint->getFilesystemCheckResults(),
            $asset->getVolumes()->getArrayCopy()
        );
    }

    /**
     * @param Asset $asset
     * @param int $snapshotEpoch
     * @return string|null
     */
    private function getEngineUsed(Asset $asset, int $snapshotEpoch)
    {
        $engineUsed = null;

        $localPoint = $asset->getLocal()->getRecoveryPoints()->get($snapshotEpoch);
        $offsitePoint = $asset->getOffsite()->getRecoveryPoints()->get($snapshotEpoch);

        if ($localPoint) {
            $engineUsed = $localPoint->getEngineUsed();
        } elseif ($offsitePoint) {
            $engineUsed = $offsitePoint->getEngineUsed();
        }

        return $engineUsed;
    }

    /**
     * @param Asset $asset
     * @param int $snapshotEpoch
     * @return string|null
     */
    private function getEngineConfigured(Asset $asset, int $snapshotEpoch)
    {
        $engineConfigured = null;

        $localPoint = $asset->getLocal()->getRecoveryPoints()->get($snapshotEpoch);
        $offsitePoint = $asset->getOffsite()->getRecoveryPoints()->get($snapshotEpoch);

        if ($localPoint) {
            $engineConfigured = $localPoint->getEngineConfigured();
        } elseif ($offsitePoint) {
            $engineConfigured = $offsitePoint->getEngineConfigured();
        }

        return $engineConfigured;
    }

    private function getLocalUsedSize(Asset $asset, int $snapshotEpoch): ?int
    {
        $usedSizes = $this->getAllLocalUsedSizes($asset);

        return $usedSizes[$snapshotEpoch] ?? null;
    }

    /**
     * @param Asset $asset
     * @return int[]
     */
    private function getAllLocalUsedSizes(Asset $asset)
    {
        $zfsPath = $asset->getDataset()->getZfsPath();
        $entry = $this->getZfsCacheEntry($zfsPath);
        $sizes = $entry->getUsedSizes();

        if ($sizes === null) {
            try {
                $sizes = $this->zfsService->getSnapshotUsedSizes($zfsPath);
            } catch (\Throwable $e) {
                $sizes = [];
            }

            $entry->setUsedSizes($sizes);
        }

        return $sizes;
    }

    private function getTransferSize(Asset $asset, int $snapshotEpoch): ?int
    {
        $transferSizes = $this->getAllTransferSizes($asset);

        return $transferSizes[$snapshotEpoch] ?? null;
    }

    /**
     * @param Asset $asset
     * @return int[]
     */
    private function getAllTransferSizes(Asset $asset): array
    {
        $assetKey = $asset->getKeyName();

        if (!isset($this->transfers[$assetKey])) {
            $this->transfers[$assetKey] = $this->retrieveTransfers($assetKey);
        }

        return $this->transfers[$assetKey];
    }

    private function retrieveTransfers(string $assetKey): array
    {
        $agentConfig = $this->agentConfigFactory->create($assetKey);
        $transferData = $agentConfig->get('transfers', AgentConfig::ERROR_RESULT);

        if ($transferData === AgentConfig::ERROR_RESULT) {
            return [];
        } else {
            $transferData = explode("\n", $transferData);
            $outArray = [];
            foreach ($transferData as $transferDatum) {
                list($epoch, $size) = explode(":", $transferDatum);
                $outArray[$epoch] = $size;
            }

            return $outArray;
        }
    }

    /**
     * @param string $zfsPath
     * @return ZfsCacheEntry
     */
    private function getZfsCacheEntry(string $zfsPath): ZfsCacheEntry
    {
        $entry = $this->zfsCache->getEntry($zfsPath);

        if ($entry === null) {
            $entry = new ZfsCacheEntry($zfsPath);
            $this->zfsCache->setEntry($zfsPath, $entry);
        }

        return $entry;
    }

    /**
     * @param Asset $asset
     * @param int $snapshotEpoch
     * @return int
     */
    private function getRemainingScreenshotTime(Asset $asset, int $snapshotEpoch): int
    {
        $remainingTime = 0;

        if (!$asset->isType(AssetType::AGENT)) {
            return $remainingTime;
        }

        /** @var Agent $asset */
        $inProgress = $this->getRunningVerification($asset, $snapshotEpoch);
        if ($inProgress) {
            $start = $inProgress->getStartedAt();
            $delay = $inProgress->getDelay();
            $finish = $start
                + $this->verificationService->getScreenshotTimeout()
                + $delay
                + $this->verificationService->getScriptsTimeout($asset);
            $remainingTime = $finish - $this->dateTimeService->getTime();
        }

        return $remainingTime;
    }

    /**
     * @param Asset $asset
     * @param int $snapshotEpoch
     * @return int one of the `SCREENSHOT` constants from \Datto\Asset\RecoveryPoint\RecoveryPoint
     * @see \Datto\Asset\RecoveryPoint\RecoveryPoint
     */
    private function determineScreenshotStatusFromExtensions(Asset $asset, int $snapshotEpoch): int
    {
        $hasScreenshot = false;
        $hasFailure = false;

        $screenshotFiles = $this->screenshotFileRepository->getAllByAssetAndEpoch($asset->getKeyName(), $snapshotEpoch);
        foreach ($screenshotFiles as $screenshotFile) {
            $extension = $screenshotFile->getExtension();

            if ($extension === ScreenshotFileRepository::EXTENSION_TXT) {
                $hasFailure = true;
                break;
            } elseif ($extension === ScreenshotFileRepository::EXTENSION_JPG) {
                $hasScreenshot = true;
            }
        }

        return $this->selectScreenshotStatus($hasFailure, $hasScreenshot);
    }

    /**
     * @param bool $hasFailure `true` if a failure state was detected in the screenshot
     * @param bool $hasScreenshot `true` if a screenshot image was captured
     * @return int one of the `SCREENSHOT` constants from \Datto\Asset\RecoveryPoint\RecoveryPoint
     * @see \Datto\Asset\RecoveryPoint\RecoveryPoint
     */
    private function selectScreenshotStatus(bool $hasFailure, bool $hasScreenshot): int
    {
        if ($hasFailure) {
            return RecoveryPoint::UNSUCCESSFUL_SCREENSHOT;
        } elseif ($hasScreenshot) {
            return RecoveryPoint::SUCCESSFUL_SCREENSHOT;
        } else {
            return RecoveryPoint::NO_SCREENSHOT;
        }
    }

    /**
     * @param Asset $asset
     * @param int $snapshotEpoch
     * @return int
     */
    private function determineScreenshotStatusFromVerificationQueue(Asset $asset, int $snapshotEpoch): int
    {
        // This goes on top since the job remains in the verification queue while running
        if (!empty($this->getRunningVerification($asset, $snapshotEpoch))) {
            return RecoveryPoint::SCREENSHOT_INPROGRESS;
        }

        if (!empty($this->verificationService->getQueuedVerifications($asset, $snapshotEpoch))) {
            return RecoveryPoint::SCREENSHOT_QUEUED;
        }

        return RecoveryPoint::SCREENSHOT_NOT_IN_QUEUE;
    }

    /**
     * @param Asset $asset
     * @param int $snapshotEpoch
     * @return int
     */
    private function determineOffsiteStatus(Asset $asset, int $snapshotEpoch): int
    {
        $queued = $this->getOffsiteQueuedSnapshotEpochs($asset);
        $syncing = $this->getSyncingPointsForAsset($asset);
        $synced = $this->getOffsiteSyncedSnapshotEpochs($asset);
        $processing = $this->getRemoteReplicatingPoints($asset);

        if (isset($synced[$snapshotEpoch])) {
            return SpeedSync::OFFSITE_SYNCED;
        } elseif (isset($syncing[$snapshotEpoch])) {
            return SpeedSync::OFFSITE_SYNCING;
        } elseif (isset($processing[$snapshotEpoch])) {
            return SpeedSync::OFFSITE_PROCESSING;
        } elseif (isset($queued[$snapshotEpoch])) {
            return SpeedSync::OFFSITE_QUEUED;
        } else {
            return SpeedSync::OFFSITE_NONE;
        }
    }

    /**
     * Retrieve information for a running verification.
     *
     * @param Asset $asset
     * @param int $snapshotEpoch
     * @return InProgressVerification|null
     */
    private function getRunningVerification(Asset $asset, int $snapshotEpoch)
    {
        $inProgress = $this->verificationService->findInProgressVerification($asset->getKeyName());

        if (!$inProgress || $inProgress->getSnapshot() !== $snapshotEpoch) {
            return null;
        }

        return $inProgress;
    }

    private function getVolumes(Asset $asset): Volumes
    {
        if (!$asset->isType(AssetType::AGENT)) {
            return new Volumes([]);
        }

        /** @var Agent $asset */
        return $asset->getVolumes();
    }
}
