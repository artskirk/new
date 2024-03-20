<?php

namespace Datto\Asset\RecoveryPoint;

use Datto\Billing;
use Datto\Cloud\SpeedSyncMaintenanceService;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\AgentConfigFactory;
use Datto\Asset\AssetService;
use Datto\Asset\OffsiteSettings;
use Datto\Cloud\SpeedSync;
use Datto\Config\AgentConfig;
use Datto\Config\DeviceConfig;
use Datto\Common\Utility\Filesystem;
use Datto\Screenshot\ScreenshotFileRepository;
use Datto\Resource\DateTimeService;
use Datto\Service\Verification\Local\FilesystemIntegrityCheckReportService;
use Datto\Verification\VerificationCleanupManager;
use Datto\Restore\AssetCloneManager;
use Datto\ZFS\ZfsService;

/**
 * Service class for local recoverypoint/snapshot functionality.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class LocalSnapshotService extends SnapshotService
{
    /** @var SnapshotValidator */
    protected $validator;

    /** @var AssetCloneManager */
    protected $cloneManager;

    /** @var ScreenshotFileRepository */
    protected $screenshotFileRepository;

    /** @var VerificationCleanupManager */
    protected $verificationCleanupManager;

    /** @var DateTimeService */
    protected $dateTimeService;

    /** @var ZfsService */
    private $zfsService;

    /** @var Billing\Service */
    private $billingService;

    /** @var FilesystemIntegrityCheckReportService */
    private $filesystemIntegrityCheckReportService;

    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var SpeedSyncMaintenanceService */
    private $speedSyncMaintenanceService;

    public function __construct(
        AssetService $assetService,
        SnapshotValidator $validator,
        AssetCloneManager $cloneManager,
        ScreenshotFileRepository $screenshotFileRepository,
        VerificationCleanupManager $verificationCleanupManager,
        SpeedSync $speedsync,
        AgentConfigFactory $agentConfigFactory,
        DateTimeService $dateTimeService,
        ZfsService $zfsService,
        Billing\Service $billingService,
        FilesystemIntegrityCheckReportService $filesystemIntegrityCheckReportService,
        DeviceConfig $deviceConfig,
        SpeedSyncMaintenanceService $speedSyncMaintenanceService
    ) {
        parent::__construct($agentConfigFactory, $assetService, $speedsync);

        $this->validator = $validator;
        $this->cloneManager = $cloneManager;
        $this->screenshotFileRepository = $screenshotFileRepository;
        $this->verificationCleanupManager = $verificationCleanupManager;
        $this->dateTimeService = $dateTimeService;
        $this->zfsService = $zfsService;
        $this->billingService = $billingService;
        $this->filesystemIntegrityCheckReportService = $filesystemIntegrityCheckReportService;
        $this->deviceConfig = $deviceConfig;
        $this->speedSyncMaintenanceService = $speedSyncMaintenanceService;
    }

    /**
     * @param string $assetKey
     * @param int[] $snapshotEpochs
     * @param DestroySnapshotReason $deletionReason
     *
     * @return DestroySnapshotResults
     */
    public function destroy(
        string $assetKey,
        array $snapshotEpochs,
        DestroySnapshotReason $deletionReason
    ): DestroySnapshotResults {
        $this->logger->setAssetContext($assetKey);

        $this->logger->info('LSS0011 Destroy request for snapshots', ['snapshots' => $snapshotEpochs]);

        $agentConfig = $this->agentConfigFactory->create($assetKey);
        $zfsPath = $agentConfig->getZfsBase() . '/' . $assetKey;
        $asset = $this->assetService->get($assetKey);

        $assetSyncEnabled = $asset->getOffsite()->getReplication() !== OffsiteSettings::REPLICATION_NEVER;
        $speedsyncEnabled = $assetSyncEnabled && !$this->billingService->isLocalOnly();

        $this->validator->ensureAssetExists($assetKey);
        $this->validator->validateSnapshotEpochs($snapshotEpochs);
        if ($speedsyncEnabled && !$asset->getLocal()->isArchived()) {
            $this->validator->ensureNoCriticalSnapshots($zfsPath, $snapshotEpochs);
        }

        $this->validator->ensureNoOffsiteRestores($assetKey, $snapshotEpochs);
        $removableSnapshotEpochs = $speedsyncEnabled
            ? $this->getRemovableSnapshotEpochs($zfsPath, $snapshotEpochs)
            : $snapshotEpochs;

        $result = $this->destroySnapshots(
            $zfsPath,
            $removableSnapshotEpochs,
            $assetKey,
            $deletionReason
        );

        $this->reconcileDeletedPoints($assetKey);

        return $result;
    }

    /**
     * Delete all the local snapshots of the asset.
     * For unarchived agents this will not delete the speedsync critical points
     *
     * @param string $assetKey
     * @param DestroySnapshotReason $deletionReason
     */
    public function purge(string $assetKey, DestroySnapshotReason $deletionReason): void
    {
        $this->logger->setAssetContext($assetKey);

        $agentConfig = $this->agentConfigFactory->create($assetKey);
        $zfsPath = $agentConfig->getZfsBase() . '/' . $assetKey;

        $this->validator->ensureAssetExists($assetKey);
        $this->validator->ensureNoLocalRestores($assetKey);

        $asset = $this->assetService->get($assetKey);

        // Only delete non-critical points for unarchived assets.
        // Deleting critical points causes the cloud dataset to become 'old-chain'. When there isn't a connecting
        // snapshot for zfs send to build incremental changes from, we rename the cloud dataset with old-chain and
        // create a new dataset so we can receive a full.
        // Old-chain is not subject to retention and requires support intervention to restore. We want to avoid that.
        if (!$asset->getLocal()->isArchived()) {
            $recoveryPoints = $asset->getLocal()->getRecoveryPoints()->getAllRecoveryPointTimes();

            $criticalPoints = [];
            if (in_array($zfsPath, $this->speedsync->getDatasets(), true)) {
                $criticalPoints = $this->speedsync->getCriticalPoints($zfsPath);
            }

            $pointsToDelete = array_values(array_diff($recoveryPoints, $criticalPoints));

            $this->destroy($assetKey, $pointsToDelete, $deletionReason);
        } else {
            $this->cleanupVerifications($assetKey);

            // Pause offsiting, and halt any in-progress transfers
            $this->speedSyncMaintenanceService->pauseAsset($assetKey, true);

            $deletionTime = $this->dateTimeService->getTime();
            $this->destroyAllSnapshots($zfsPath);

            if (!$agentConfig->isShare()) {
                $this->deleteLiveDataset($assetKey);
                $this->enableFullAndDiffMergeFlags($agentConfig);
            }

            // Reset caches/stored values
            $this->markPointsDeleted($assetKey, 'all', $deletionTime, $deletionReason);
            $this->reconcileDeletedPoints($assetKey);
            $this->resetCachedUsedSizes($agentConfig);

            $this->logger->info('LSS0012 Resuming syncs for dataset', ['dataset' => $zfsPath]);
            $this->speedsync->add($zfsPath, $asset->getOffsiteTarget());
            $this->speedSyncMaintenanceService->resumeAsset($assetKey);

            $this->refreshSpeedsync($zfsPath);
        }
    }

    /**
     * @param string $assetKey
     * @param string|array $destroyedSnapshotEpochs
     * @param int $deletionTime
     * @param DestroySnapshotReason $deletionReason
     */
    protected function markPointsDeleted(
        string $assetKey,
        $destroyedSnapshotEpochs,
        int $deletionTime,
        DestroySnapshotReason $deletionReason
    ): void {
        try {
            $asset = $this->assetService->get($assetKey);
            $recoveryPoints = $asset->getLocal()->getRecoveryPoints();

            if (is_array($destroyedSnapshotEpochs)) {
                foreach ($destroyedSnapshotEpochs as $destroyedSnapshotEpoch) {
                    $recoveryPoint = $recoveryPoints->get($destroyedSnapshotEpoch);
                    $recoveryPoint->setDeletionTime($deletionTime);
                    $recoveryPoint->setDeletionReason($deletionReason);
                }
            } elseif ($destroyedSnapshotEpochs === 'all') {
                foreach ($recoveryPoints->getAll() as $recoveryPoint) {
                    $recoveryPoint->setDeletionTime($deletionTime);
                    $recoveryPoint->setDeletionReason($deletionReason);
                }
            } else {
                throw new \Exception('Unexpected value when destroying snapshots: '
                    . var_export($destroyedSnapshotEpochs, true));
            }

            $this->assetService->save($asset);
        } catch (\Throwable $e) {
            $this->logger->warning('LSS0013 Failed to update recovery points', ['exception' => $e]);
        }
    }

    /**
     * @param string $zfsPath
     * @param int[] $snapshotsToRemove
     * @return int[]
     */
    protected function getRemovableSnapshotEpochs(string $zfsPath, array $snapshotsToRemove): array
    {
        // Speedsync on cloud devices doesn't use zfs flags to queue points for offsite, it just sends every point.
        // Attempting to get queued points on a cloud device will exit 1 and throw an exception within os2.
        if (!$this->deviceConfig->isCloudDevice()) {
            $queuedSnapshotsToRemove = $this->getQueuedSnapshotEpochs($zfsPath, $snapshotsToRemove);
            $snapshotsToRemove = array_diff($snapshotsToRemove, $queuedSnapshotsToRemove);
        }

        return $snapshotsToRemove;
    }

    /**
     * @param string $zfsPath
     * @param int[] $snapshotsToRemove
     * @return int[]
     */
    protected function getQueuedSnapshotEpochs(string $zfsPath, array $snapshotsToRemove): array
    {
        $queuedSnapshotsToRemove = array_intersect($snapshotsToRemove, $this->speedsync->getQueuedPoints($zfsPath));

        if (!empty($queuedSnapshotsToRemove)) {
            $this->logger->warning(
                'LSS0001 Snapshots are queued for offsite, skipping',
                ['pointsToSkipRemoval' => implode(',', $queuedSnapshotsToRemove)]
            );
        }

        return $queuedSnapshotsToRemove;
    }

    /**
     * @param string $zfsPath
     */
    protected function refreshSpeedsync(string $zfsPath): void
    {
        try {
            $this->logger->info('LSS0002 Refreshing speedsync metadata.', ['zfsPath' => $zfsPath]);
            $this->speedsync->refresh($zfsPath);
        } catch (\Throwable $e) {
            $this->logger->warning("LSS0003 Failed to refresh speedsync metadata", ['zfsPath' => $zfsPath, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param AgentConfig $agentConfig
     */
    protected function enableFullAndDiffMergeFlags(AgentConfig $agentConfig): void
    {
        $agentConfig->set('doDiffMerge', serialize(true));
        $agentConfig->set('forceFull', serialize(true));
    }

    /**
     * @param AgentConfig $agentConfig
     */
    protected function resetCachedUsedSizes(AgentConfig $agentConfig): void
    {
        $agentInfo = unserialize($agentConfig->get('agentInfo'), ['allowed_classes' => false]);
        $agentInfo['usedBySnaps'] = 0;
        $agentInfo['localUsed'] = 0;
        $agentConfig->set('agentInfo', serialize($agentInfo));
    }

    /**
     * @param string $assetKey
     */
    protected function cleanupVerifications(string $assetKey): void
    {
        try {
            $this->verificationCleanupManager->cleanupVerifications($assetKey);
        } catch (\Throwable $e) {
            $this->logger->warning('LSS0004 Failed to cleanup verifications', ['assetKey' => $assetKey, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param string $assetKey
     */
    protected function deleteLiveDataset(string $assetKey): void
    {
        try {
            $asset = $this->assetService->get($assetKey);
            $asset->getDataset()->delete();
        } catch (\Throwable $e) {
            $this->logger->warning('LSS0005 Failed to destroy live dataset', ['assetKey' => $assetKey, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param string $zfsPath
     * @param array $snapshotEpochs
     * @param string $assetKey
     * @param DestroySnapshotReason $deletionReason
     * @return DestroySnapshotResults
     */
    protected function destroySnapshots(
        string $zfsPath,
        array $snapshotEpochs,
        string $assetKey,
        DestroySnapshotReason $deletionReason
    ): DestroySnapshotResults {
        $destroyed = [];
        $failed = [];

        foreach ($snapshotEpochs as $snapshotEpoch) {
            $snapshotZfsPath = $zfsPath . '@' . $snapshotEpoch;

            $this->logger->info('AGT1506 Removing ZFS snapshot', ['snapshotEpoch' => $snapshotEpoch]); // log code is used by device-web see DWI-2252
            try {
                $this->zfsService->destroyDataset($snapshotZfsPath);
                $this->markPointsDeleted(
                    $assetKey,
                    [$snapshotEpoch],
                    $this->dateTimeService->getTime(),
                    $deletionReason
                );
                $this->removeFilesystemIntegrityCheckReport($assetKey, $snapshotEpoch);
                $this->removeScreenshot($assetKey, $snapshotEpoch);

                $destroyed[] = $snapshotEpoch;
            } catch (\Throwable $e) {
                $this->logger->info('LSS0006 Failed to destroy local snapshot', ['snapshotEpoch' => $snapshotEpoch, 'error' => $e->getMessage()]);

                $failed[] = $snapshotEpoch;
            }
        }

        return new DestroySnapshotResults($destroyed, $failed);
    }

    /**
     * @param string $zfsPath
     */
    protected function destroyAllSnapshots(string $zfsPath): void
    {
        try {
            $this->logger->info('LSS0007 Destroying all local snapshots under path', ['zfsPath' => $zfsPath]);

            $destroyAllSnapshotsPath = $zfsPath . '@%';
            $this->zfsService->destroyDataset($destroyAllSnapshotsPath);
        } catch (\Throwable $e) {
            $this->logger->warning('LSS0008 Failed to destroy all local snapshots under path', ['zfsPath' => $zfsPath, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param string $assetKey
     * @param int $snapshotEpoch
     */
    protected function removeScreenshot(string $assetKey, int $snapshotEpoch): void
    {
        if ($this->assetService->exists($assetKey)) {
            $this->logger->info('LSS0009 Cleaning up screenshots for removed snapshot', ['snapshotEpoch' => $snapshotEpoch]);

            $screenshotFiles = $this->screenshotFileRepository->getAllByAssetAndEpoch($assetKey, $snapshotEpoch);

            foreach ($screenshotFiles as $screenshotFile) {
                $this->screenshotFileRepository->remove($screenshotFile);
            }
        }
    }

    /**
     * @param string $assetKey
     * @param int $snapshotEpoch
     */
    private function removeFilesystemIntegrityCheckReport(string $assetKey, int $snapshotEpoch): void
    {
        if ($this->assetService->exists($assetKey)) {
            $this->logger->info(
                'LSS0010 Cleaning up filesystem integrity check reports for removed snapshot',
                ['snapshotEpoch' => $snapshotEpoch]
            );

            $this->filesystemIntegrityCheckReportService->destroy($assetKey, $snapshotEpoch);
        }
    }
}
