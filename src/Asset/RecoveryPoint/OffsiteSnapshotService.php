<?php

namespace Datto\Asset\RecoveryPoint;

use Datto\Cloud\SpeedSyncMaintenanceService;
use Datto\Config\AgentConfigFactory;
use Datto\Asset\AssetService;
use Datto\Cloud\SpeedSync;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Datto\Utility\Cloud\RemoteDestroyException;

/**
 * Service class for offsite recoverypoint/snapshot functionality.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class OffsiteSnapshotService extends SnapshotService
{
    /** @var DestroyOffsiteSnapshotAuditor */
    protected $auditor;

    /** @var SnapshotValidator */
    protected $validator;

    /** @var Collector */
    private $collector;

    /** @var SpeedSyncMaintenanceService */
    private $speedSyncMaintenanceService;

    public function __construct(
        DestroyOffsiteSnapshotAuditor $destroyOffsiteAuditor,
        SpeedSync $speedsync,
        AssetService $assetService,
        SnapshotValidator $validator,
        AgentConfigFactory $agentConfigFactory,
        Collector $collector,
        SpeedSyncMaintenanceService $speedSyncMaintenanceService
    ) {
        parent::__construct($agentConfigFactory, $assetService, $speedsync);

        $this->auditor = $destroyOffsiteAuditor;
        $this->validator = $validator;
        $this->collector = $collector;
        $this->speedSyncMaintenanceService = $speedSyncMaintenanceService;
    }

    /**
     * Destroy offsite snapshots.
     *
     * @param string $assetKey
     * @param int[] $snapshotEpochs
     * @param DestroySnapshotReason $sourceReason
     * @param string|null $sourceAddress
     *
     * @return DestroySnapshotResults
     */
    public function destroy(
        string $assetKey,
        array $snapshotEpochs,
        DestroySnapshotReason $sourceReason,
        string $sourceAddress = null
    ): DestroySnapshotResults {
        $this->logger->setAssetContext($assetKey);

        $this->logger->info('OSN0002 Starting request to destroy offsite snapshots ...'); // log code is used by device-web see DWI-2252

        $agentConfig = $this->agentConfigFactory->create($assetKey);
        $zfsPath = $agentConfig->getZfsBase() . '/' . $assetKey;

        $this->validator->ensureAssetExists($assetKey);
        $this->validator->validateSnapshotEpochs($snapshotEpochs);
        $this->validator->ensureNoOffsiteRestores($assetKey, $snapshotEpochs);
        $this->validator->ensureNoCriticalSnapshots($zfsPath, $snapshotEpochs);

        $this->auditor->report($assetKey, $snapshotEpochs, $sourceReason, $sourceAddress);

        $result = $this->destroySnapshots($zfsPath, $snapshotEpochs, $sourceReason);

        $this->recordOffsiteRetention($result, $sourceReason);

        $this->refreshSpeedsync($zfsPath);

        $this->reconcileDeletedPoints($assetKey);

        return $result;
    }

    /**
     * Purge all offsite snapshots. This will clear away all offsite snapshots including critical points.
     *
     * @param string $assetKey
     * @param DestroySnapshotReason $sourceReason
     * @param string|null $sourceAddress
     */
    public function purge(
        string $assetKey,
        DestroySnapshotReason $sourceReason,
        string $sourceAddress = null
    ): void {
        $this->logger->setAssetContext($assetKey);

        $this->logger->info('OSN0003 Starting request to purge offsite snapshots ...'); // log code is used by device-web see DWI-2252

        $agentConfig = $this->agentConfigFactory->create($assetKey);
        $zfsPath = $agentConfig->getZfsBase() . '/' . $assetKey;

        $this->validator->ensureAssetExists($assetKey);
        $this->validator->ensureNoOffsiteRestores($assetKey);

        $this->auditor->reportPurge($assetKey, $sourceReason, $sourceAddress);

        $this->logger->info('OSN0004 Pausing syncs for dataset', ['zfsPath' => $zfsPath]);
        $this->speedSyncMaintenanceService->pauseAsset($assetKey);

        $this->logger->info('OSN0005 Destroying offsite snapshots for dataset', ['zfsPath' => $zfsPath]);
        try {
            $this->speedsync->remoteDestroy($zfsPath, DestroySnapshotReason::MANUAL());
        } catch (RemoteDestroyException $e) {
            $this->logger->warning("OSN0012 Could not destroy offsite snapshots", ['zfsPath' => $zfsPath, 'errorCode' => $e->getCode(), 'error' => $e->getMessage()]);
        }

        $offsiteTarget = json_decode($agentConfig->get('offsiteTarget', null), true)['offsiteTarget'] ?? null;
        $this->logger->info('OSN0006 Resuming syncs for dataset', ['zfsPath' => $zfsPath]);
        $this->speedsync->add($zfsPath, $offsiteTarget);
        $this->speedSyncMaintenanceService->resumeAsset($assetKey);

        $this->refreshSpeedsync($zfsPath);

        $this->reconcileDeletedPoints($assetKey);
    }

    protected function refreshSpeedsync(string $zfsPath): void
    {
        try {
            $this->logger->info("OSN0007 Refreshing speedsync metadata ...", ['zfsPath' => $zfsPath]);
            $this->speedsync->refresh($zfsPath);
        } catch (\Throwable $e) {
            $this->logger->warning("OSN0008 Failed to refresh speedsync metadata", ['zfsPath' => $zfsPath]);
        }
    }

    protected function destroySnapshots(string $zfsPath, array $snapshotEpochs, DestroySnapshotReason $sourceReason): DestroySnapshotResults
    {
        $destroyed = [];
        $failed = [];

        foreach ($snapshotEpochs as $snapshotEpoch) {
            $snapshotZfsPath = $zfsPath . '@' . $snapshotEpoch;

            $this->logger->info('OSN0009 Destroying offsite snapshot', ['zfsPath' => $snapshotZfsPath]);

            try {
                $this->speedsync->remoteDestroy($snapshotZfsPath, $sourceReason);
                $this->logger->info('OSN0010 Sucessfully destroyed offsite snapshot', ['zfsPath' => $snapshotZfsPath]);
                $destroyed[] = $snapshotEpoch;
            } catch (RemoteDestroyException $e) {
                $this->logger->error('OSN0011 Failed to destroy offsite snapshot', ['zfsPath' => $snapshotZfsPath, 'errorCode' => $e->getCode(), 'error' => $e->getMessage()]);
                $failed[] = $snapshotEpoch;
            }
        }

        return new DestroySnapshotResults($destroyed, $failed);
    }

    /**
     * Send count of destroyed points
     *
     * @param DestroySnapshotResults $results
     * @param DestroySnapshotReason $reason
     */
    private function recordOffsiteRetention(
        DestroySnapshotResults $results,
        DestroySnapshotReason $reason
    ): void {
        $destroyedPoints = $results->getDestroyedSnapshotEpochs();
        $destroyedPointsCount = count($destroyedPoints);
        for ($i = 0; $i < $destroyedPointsCount; $i++) {
            $this->collector->increment(
                Metrics::DTC_OFFSITE_RETENTION_COUNT,
                [
                    'reason' => $reason->value()
                ]
            );
        }
    }
}
