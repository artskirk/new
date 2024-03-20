<?php

namespace Datto\Restore\Iscsi;

use Datto\Asset\AssetService;
use Datto\Asset\Share\Iscsi\IscsiShare;
use Datto\Cloud\SpeedSync;
use Datto\Cloud\SpeedSyncMaintenanceService;
use Datto\Config\AgentConfigFactory;
use Datto\Core\Storage\SirisStorage;
use Datto\Core\Storage\StorageInterface;
use Datto\Core\Storage\StorageType;
use Datto\Dataset\ZVolDataset;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Datto\Utility\File\Lock;
use Datto\Iscsi\IscsiTarget;
use Datto\Log\LoggerFactory;
use Datto\Log\DeviceLoggerInterface;
use Exception;
use Throwable;

/**
 * iSCSI Rollback Service
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class IscsiRollbackService
{
    const ROLLBACK_STATUS_KEY_NAME = 'rollbackStatus';
    const ASSET_LOCK_KEY_NAME = 'lock';

    /** @var AssetService */
    private $assetService;

    /** @var AgentConfigFactory */
    private $agentConfigFactory;

    /** @var IscsiTarget */
    private $iscsiTarget;

    /** @var SpeedSyncMaintenanceService */
    private $speedSyncMaintenanceService;

    /** @var SpeedSync */
    private $speedSync;

    /** @var LoggerFactory */
    private $loggerFactory;

    /** @var Collector */
    private $collector;

    private StorageInterface $storage;
    private SirisStorage $sirisStorage;

    public function __construct(
        AssetService $assetService,
        AgentConfigFactory $agentConfigFactory,
        IscsiTarget $iscsiTarget,
        SpeedSyncMaintenanceService $speedSyncMaintenanceService,
        SpeedSync $speedSync,
        LoggerFactory $loggerFactory,
        Collector $collector,
        StorageInterface $storage,
        SirisStorage $sirisStorage
    ) {
        $this->assetService = $assetService;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->iscsiTarget = $iscsiTarget;
        $this->speedSyncMaintenanceService = $speedSyncMaintenanceService;
        $this->speedSync = $speedSync;
        $this->loggerFactory = $loggerFactory;
        $this->collector = $collector;
        $this->storage = $storage;
        $this->sirisStorage = $sirisStorage;
    }

    /**
     * Rollback an iSCSI Share to a snapshot.
     *
     * @param string $assetKeyName
     * @param int $rollbackPoint
     */
    public function rollback(string $assetKeyName, int $rollbackPoint)
    {
        $this->collector->increment(Metrics::RESTORE_STARTED, [
            'type' => Metrics::RESTORE_TYPE_ISCSI_ROLLBACK
        ]);

        $logger = $this->loggerFactory->getAssetLogger($assetKeyName);
        $assetConfig = $this->agentConfigFactory->create($assetKeyName);
        $asset = $this->assetService->get($assetKeyName);

        if (!($asset instanceof IscsiShare)) {
            throw new Exception("Asset $assetKeyName is not an iSCSI share");
        }

        $snapshotList = $asset->getDataset()->listSnapshots();
        if (!in_array($rollbackPoint, $snapshotList)) {
            throw new Exception("There is no snapshot at $rollbackPoint");
        }

        if ($assetConfig->has(self::ROLLBACK_STATUS_KEY_NAME)) {
            throw new Exception("Rollback already in progress");
        }

        try {
            $assetConfig->set(self::ROLLBACK_STATUS_KEY_NAME, true);

            $this->rollbackWithPause($asset, $rollbackPoint, $logger);
        } finally {
            $assetConfig->clear(self::ROLLBACK_STATUS_KEY_NAME);
        }
    }

    /**
     * Pause speedsync for the asset and then perform a rollback.
     * This will also refresh speedsync for the asset upon success.
     *
     * The original pause state will be saved and restored before this function
     * returns, even if an exception occurs, to ensure that the paused state
     * remains as the user configured it.
     *
     * @param IscsiShare $asset
     * @param int $rollbackPoint
     * @param DeviceLoggerInterface $logger
     */
    private function rollbackWithPause(IscsiShare $asset, int $rollbackPoint, DeviceLoggerInterface $logger)
    {
        $assetKeyName = $asset->getKeyName();

        $speedSyncPaused = $this->speedSyncMaintenanceService->isAssetPaused($assetKeyName);

        try {
            $this->speedSyncMaintenanceService->pauseAsset($assetKeyName);

            $this->rollbackWithIscsiTarget($asset, $rollbackPoint, $logger);

            $this->purgeAssetRecoveryPoints($assetKeyName, $rollbackPoint, $logger);
        } finally {
            if (!$speedSyncPaused) {
                $this->speedSyncMaintenanceService->resumeAsset($assetKeyName);
            }
        }

        if (!$speedSyncPaused) {
            $logger->info('ISR0014 Refreshing speedsync for asset.');
            $this->speedSync->refresh($asset->getDataset()->getZfsPath());
        }
    }

    /**
     * Perform the iSCSI rollback with the iSCSI target removed.
     *
     * This function performs the following steps:
     *   1. Remove the iSCSI target
     *   2. Perform the ZFS rollback
     *   3. Recreate the iSCSI target
     *
     * The iSCSI target will be recreated (step 3) even if an exception occurs.
     * This is very important because the LegacyIscsiShareSerializer will break
     * if the iSCSI target does not exist.  This would break the whole system.
     *
     * @param IscsiShare $asset
     * @param int $rollbackPoint
     * @param DeviceLoggerInterface $logger
     */
    private function rollbackWithIscsiTarget(IscsiShare $asset, int $rollbackPoint, DeviceLoggerInterface $logger)
    {
        $assetKeyName = $asset->getKeyName();
        /** @var ZVolDataset $dataset */
        $dataset = $asset->getDataset();

        $blockLink = $dataset->getBlockLink();
        $blockSize = $asset->getBlockSize();

        if (!$blockLink) {
            throw new Exception("Asset $assetKeyName dataset does not have a block link");
        }

        $targetName = $asset->getTargetName();

        try {
            $chapUsers = $this->iscsiTarget->getTargetChapUsers($targetName);
        } catch (Throwable $e) {
            $logger->warning('ISR0021 Unable to get CHAP users for iSCSI target', ['target' => $targetName, 'exception' => $e]);
            $chapUsers = [];
        }

        // IMPORTANT:  Disabling (removing) the iSCSI target breaks the
        // LegacyIscsiShareSerializer, so don't try to load any assets
        // while the iSCSI target is disabled (removed).
        try {
            $logger->info('ISR0010 Disabling iSCSI target', ['target' => $targetName]);
            $this->disableIscsiTargetIgnoreExceptions($targetName, $logger);

            $storageId = $this->sirisStorage->getStorageId($assetKeyName, StorageType::STORAGE_TYPE_BLOCK);
            $snapshotId = $this->sirisStorage->getSnapshotId($storageId, strval($rollbackPoint));

            $logger->info('ISR0011 Rolling back the ZFS dataset', ['rollbackPoint' => $rollbackPoint]);
            $this->storage->rollbackToSnapshotDestructive($snapshotId, false);
        } finally {
            $logger->info('ISR0012 Enabling iSCSI target', ['target' => $targetName]);
            $this->enableIscsiTarget($targetName, $blockLink, $blockSize, $chapUsers);
        }
    }

    /**
     * Disable the iSCSI target.
     *
     * This function will not generate an exception upon failure, but will log
     * a warning message.
     *
     * @param string $targetName
     * @param DeviceLoggerInterface $logger
     */
    private function disableIscsiTargetIgnoreExceptions(string $targetName, DeviceLoggerInterface $logger)
    {
        try {
            $this->disableIscsiTarget($targetName);
        } catch (Throwable $e) {
            $logger->warning('ISR0020 Unable to remove iSCSI target', ['target' => $targetName, 'exception' => $e]);
        }
    }

    /**
     * Disable the iSCSI target.
     *
     * @param string $targetName
     */
    private function disableIscsiTarget(string $targetName)
    {
        $this->iscsiTarget->closeSessionsOnTarget($targetName);
        $this->iscsiTarget->deleteTarget($targetName);
        $this->iscsiTarget->writeChanges();
    }

    /**
     * Enable the iSCSI target.
     *
     * @param string $targetName
     * @param string $blockLink
     * @param int $blockSize
     * @param array $chapUsers
     */
    private function enableIscsiTarget(string $targetName, string $blockLink, int $blockSize, array $chapUsers)
    {
        $this->iscsiTarget->createTarget($targetName);
        $this->iscsiTarget->addLun($targetName, $blockLink, false, false, null, ["block_size=$blockSize"]);
        foreach ($chapUsers as $userType => $chapUser) {
            $username = $chapUser[0];
            $password = $chapUser[1];
            $this->iscsiTarget->addTargetChapUser($targetName, $userType, $username, $password, false);
        }
        $this->iscsiTarget->writeChanges();
    }

    /**
     * Purge the rolled back recovery points from the asset data.
     *
     * Since this is a destructive operation, it performs two checks before
     * removing a recovery point:
     *   1.  The point must not be in the ZFS dataset
     *   2.  The point epoch time must be greater than the rollback point
     *
     * @param string $assetKeyName
     * @param int $rollbackPoint
     * @param DeviceLoggerInterface $logger
     */
    private function purgeAssetRecoveryPoints(string $assetKeyName, int $rollbackPoint, DeviceLoggerInterface $logger)
    {
        $logger->info('ISR0013 Updating the asset recovery point list.');

        $assetConfig = $this->agentConfigFactory->create($assetKeyName);
        $lockFile = $assetConfig->getConfigFilePath(self::ASSET_LOCK_KEY_NAME);
        $lock = new Lock($lockFile);
        $lock->exclusive();

        $asset = $this->assetService->get($assetKeyName);
        $zfsRecoveryPointsArray = $asset->getDataset()->listSnapshots();
        if (!$zfsRecoveryPointsArray) {
            throw new Exception('Error retrieving the snapshot points from ZFS');
        }
        $assetRecoveryPointsObject = $asset->getLocal()->getRecoveryPoints();
        $assetRecoveryPointsArray = $assetRecoveryPointsObject->getAllRecoveryPointTimes();
        $removeRecoveryPointsArray = array_diff($assetRecoveryPointsArray, $zfsRecoveryPointsArray);
        foreach ($removeRecoveryPointsArray as $point) {
            if ($point > $rollbackPoint) {
                $logger->debug('ISR0020 Removing recovery point from the asset list.', ['recoveryPoint' => $point]);
                $assetRecoveryPointsObject->remove($point);
            } else {
                $logger->warning('ISR0021 Recovery point is in the asset list but not in the ZFS list.', ['recoveryPoint' => $point]);
            }
        }
        $this->assetService->save($asset);

        // This will unlock automatically when $lock goes out of scope.
    }
}
