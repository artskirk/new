<?php

namespace Datto\Asset\RecoveryPoint;

use Datto\AppKernel;
use Datto\Asset\AssetService;
use Datto\Cloud\SpeedSync;
use Datto\Restore\Restore;
use Datto\Restore\RestoreService;

/**
 * Validation helper for local and offsite snapshot services.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class SnapshotValidator
{
    /** @var AssetService */
    private $assetService;

    /** @var SpeedSync */
    private $speedsync;

    /** @var RestoreService */
    private $restoreService;

    /**
     * @param AssetService|null $assetService
     * @param SpeedSync|null $speedsync
     * @param RestoreService|null $restoreService
     */
    public function __construct(
        AssetService $assetService = null,
        SpeedSync $speedsync = null,
        RestoreService $restoreService = null
    ) {
        $this->assetService = $assetService ?: new AssetService();
        $this->speedsync = $speedsync ?: new SpeedSync();
        $this->restoreService = $restoreService ?: AppKernel::getBootedInstance()->getContainer()->get(RestoreService::class);
    }

    /**
     * @param string $assetKey
     */
    public function ensureAssetExists(string $assetKey): void
    {
        if (!$this->assetService->exists($assetKey)) {
            throw new \Exception("Cannot destroy snapshots for an asset that does not exist: " . $assetKey);
        }
    }

    /**
     * @param array $snapshotEpochs
     */
    public function validateSnapshotEpochs(array $snapshotEpochs): void
    {
        foreach ($snapshotEpochs as $snapshotEpoch) {
            if (filter_var($snapshotEpoch, FILTER_VALIDATE_INT) === false) {
                throw new \Exception('Snapshot epoch is not an integer: ' . $snapshotEpoch);
            }
        }
    }

    /**
     * @param string $zfsPath
     * @param array $snapshotEpochs
     */
    public function ensureNoCriticalSnapshots(string $zfsPath, array $snapshotEpochs): void
    {
        $criticalSnapshots = array_flip($this->speedsync->getCriticalPoints($zfsPath));

        foreach ($snapshotEpochs as $snapshotEpoch) {
            if (isset($criticalSnapshots[$snapshotEpoch])) {
                throw new \Exception('Snapshot is a critical offsite snapshot: ' . $snapshotEpoch);
            }
        }
    }

    /**
     * @param string $assetKey
     * @param array $snapshotEpochs
     */
    public function ensureNoLocalRestores(string $assetKey, array $snapshotEpochs = null): void
    {
        $restores = $this->restoreService->getForAsset($assetKey);

        $allSnapshotEpochsWithRestore = [];

        foreach ($restores as $restore) {
            if (!in_array($restore->getSuffix(), Restore::OFFSITE_RESTORE_SUFFIXES)) {
                $allSnapshotEpochsWithRestore[(int)$restore->getPoint()] = true;
            }
        }

        $allSnapshotEpochsWithRestore = array_keys($allSnapshotEpochsWithRestore);

        if ($snapshotEpochs !== null) {
            $snapshotEpochsWithRestore = array_intersect($snapshotEpochs, $allSnapshotEpochsWithRestore);
        } else {
            $snapshotEpochsWithRestore = $allSnapshotEpochsWithRestore;
        }

        if (count($snapshotEpochsWithRestore) > 0) {
            throw new \Exception('Local restore exists for local snapshots: '
                . implode(',', $snapshotEpochsWithRestore));
        }
    }

    /**
     * @param string $assetKey
     * @param array $snapshotEpochs
     */
    public function ensureNoOffsiteRestores(string $assetKey, array $snapshotEpochs = null): void
    {
        $restores = $this->restoreService->getForAsset($assetKey);

        $allSnapshotEpochsWithRestore = [];

        foreach ($restores as $restore) {
            if (in_array($restore->getSuffix(), Restore::OFFSITE_RESTORE_SUFFIXES)) {
                $allSnapshotEpochsWithRestore[(int)$restore->getPoint()] = true;
            }
        }

        $allSnapshotEpochsWithRestore = array_keys($allSnapshotEpochsWithRestore);

        if ($snapshotEpochs !== null) {
            $snapshotEpochsWithRestore = array_intersect($snapshotEpochs, $allSnapshotEpochsWithRestore);
        } else {
            $snapshotEpochsWithRestore = $allSnapshotEpochsWithRestore;
        }

        if (count($snapshotEpochsWithRestore) > 0) {
            throw new \Exception('Offsite restore exists for offsite snapshots: '
                . implode(',', $snapshotEpochsWithRestore));
        }
    }
}
