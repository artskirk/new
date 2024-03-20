<?php

namespace Datto\App\Controller\Api\V1\Device\Restore\Iscsi;

use Datto\Asset\AssetService;
use Datto\Asset\RecoveryPoint\RecoveryPointInfo;
use Datto\Asset\RecoveryPoint\RecoveryPointInfoService;
use Datto\Cloud\SpeedSync;
use Datto\Restore\Iscsi\IscsiRollbackService;
use Datto\Log\LoggerFactory;
use Datto\Restore\Iscsi\IscsiMounterService;
use Datto\Util\DateTimeZoneService;
use Throwable;

/**
 * DattoNAS iSCSI Rollback Assistant
 *
 * Used for AJAX requests to:
 * - Get a list of:
 *   - Current, active restores within the rollback range
 *   - Current, queued and active transfers within the rollback range
 *   - Recovery points within the rollback range
 *
 * - Start the rollback process
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class Rollback
{
    const LIST_ID_RESTORE = 'restore';
    const LIST_ID_RECOVERY = 'recovery';
    const LIST_ID_TRANSFER = 'transfer';

    /** @var DateTimeZoneService */
    private $dateTimeZoneService;

    /** @var AssetService */
    private $assetService;

    /** @var RecoveryPointInfoService */
    private $recoveryPointInfoService;

    /** @var IscsiMounterService */
    private $iscsiMounterService;

    /** @var IscsiRollbackService */
    private $iscsiRollbackService;

    /** @var LoggerFactory */
    private $loggerFactory;

    public function __construct(
        DateTimeZoneService $dateTimeZoneService,
        AssetService $assetService,
        RecoveryPointInfoService $recoveryPointInfoService,
        IscsiMounterService $iscsiMounterService,
        IscsiRollbackService $iscsiRollbackService,
        LoggerFactory $loggerFactory
    ) {
        $this->dateTimeZoneService = $dateTimeZoneService;
        $this->assetService = $assetService;
        $this->recoveryPointInfoService = $recoveryPointInfoService;
        $this->iscsiMounterService = $iscsiMounterService;
        $this->iscsiRollbackService = $iscsiRollbackService;
        $this->loggerFactory = $loggerFactory;
    }

    /**
     * Get the list of points to be destroyed.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_ISCSI_ROLLBACK")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_ISCSI_ROLLBACK_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "assetKeyName" = @Datto\App\Security\Constraints\AssetExists(type="iscsi"),
     *     "rollbackPoint" = @Symfony\Component\Validator\Constraints\Type("integer")
     * })
     *
     * @param string $assetKeyName
     * @param int $rollbackPoint
     * @return array[][] Destroy matrix, in the form:
     *     array[point][listId] = recovery point data
     *     where, listId = 'recovery', 'transfer', or 'restore'
     */
    public function getDestructionList(string $assetKeyName, int $rollbackPoint): array
    {
        $destroyMatrix = [];

        $recoveryPointInfoList = $this->getRecoveryPointsToDestroy($assetKeyName, $rollbackPoint);

        foreach ($recoveryPointInfoList as $point => $recoveryPointInfo) {
            $isLocal = $recoveryPointInfo->existsLocally();
            $isOffsite = $recoveryPointInfo->existsOffsite();
            $offsiteStatus = $recoveryPointInfo->getOffsiteStatus();
            $hasRestore = $recoveryPointInfo->hasLocalRestore();

            $destroyMatrix[$point] = [
                self::LIST_ID_RECOVERY => $this->createDestructionListValue($point, $isLocal, $isOffsite)
            ];

            if ($offsiteStatus === SpeedSync::OFFSITE_QUEUED || $offsiteStatus === SpeedSync::OFFSITE_SYNCING) {
                $destroyMatrix[$point][self::LIST_ID_TRANSFER] = $this->createDestructionListValue($point);
            }

            if ($hasRestore) {
                $destroyMatrix[$point][self::LIST_ID_RESTORE] = $this->createDestructionListValue($point);
            }
        }

        return $destroyMatrix;
    }

    /**
     * Start the rollback operation.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_ISCSI_ROLLBACK")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_ISCSI_ROLLBACK_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "assetKeyName" = @Datto\App\Security\Constraints\AssetExists(type="iscsi"),
     *     "rollbackPoint" = @Symfony\Component\Validator\Constraints\Type("integer")
     * })
     *
     * @param string $assetKeyName
     * @param int $rollbackPoint
     */
    public function startRollback(string $assetKeyName, int $rollbackPoint): void
    {
        $logger = $this->loggerFactory->getAssetLogger($assetKeyName);

        // Remove any active restores
        $recoveryPointInfoList = $this->getRecoveryPointsToDestroy($assetKeyName, $rollbackPoint);

        foreach ($recoveryPointInfoList as $point => $recoveryPointInfo) {
            if ($recoveryPointInfo->hasLocalRestore()) {
                $logger->info('ISR0001 Removing iSCSI restore for snapshot', ['snapshot' => $point]);
                $this->removeIscsiRestore($assetKeyName, $point);
                $logger->info('ISR0002 Successfully removed iSCSI restore for snapshot', ['snapshot' => $point]);
            }
        }

        $logger->info('ISR0003 Starting rollback to snapshot', ['rollbackPoint' => $rollbackPoint]);

        try {
            $this->iscsiRollbackService->rollback($assetKeyName, $rollbackPoint);
        } catch (Throwable $e) {
            $logger->error('ISR0005 Rollback failed', ['exception' => $e, 'rollbackPoint' => $rollbackPoint]);
            throw $e;
        }

        $logger->info('ISR0004 Successfully rolled back to snapshot', ['rollbackPoint' => $rollbackPoint]);
    }

    /**
     * Gets the list of recovery points to destroy.
     *
     * @param string $assetKeyName
     * @param int $rollbackPoint
     * @return RecoveryPointInfo[] Indexed by recovery point
     */
    private function getRecoveryPointsToDestroy(string $assetKeyName, int $rollbackPoint): array
    {
        $asset = $this->assetService->get($assetKeyName);
        $this->recoveryPointInfoService->refreshCaches($asset);
        $recoveryPointInfoList = $this->recoveryPointInfoService->getAll($asset);

        $resultList = [];
        foreach ($recoveryPointInfoList as $point => $recoveryPointInfo) {
            if ($point > $rollbackPoint) {
                $resultList[$point] = $recoveryPointInfo;
            }
        }

        return $resultList;
    }

    /**
     * Creates a value to be stored in the destruction list.
     *
     * @param int $point The restore point.
     * @param bool $isLocal True if the point is stored locally.
     * @param bool $isOffsite True if the point is stored offsite.
     * @return array
     */
    private function createDestructionListValue(int $point, bool $isLocal = false, bool $isOffsite = false): array
    {
        $dateFormat = $this->dateTimeZoneService->localizedDateFormat('time-day-date');
        $formattedDate = date($dateFormat, $point);

        return [
            'date' => $formattedDate,
            'isLocal' => $isLocal,
            'isOffsite' => $isOffsite
        ];
    }

    /**
     * Removes an iSCSI active restore.
     *
     * @param string $assetKeyName The asset to destroy the iSCSI target for
     * @param int $snapshotPoint The snapshot to destroy the iSCSI target for
     */
    private function removeIscsiRestore(string $assetKeyName, int $snapshotPoint): void
    {
        $this->iscsiMounterService->destroyIscsiTarget($assetKeyName, $snapshotPoint);
        $this->iscsiMounterService->destroyClone($assetKeyName, $snapshotPoint);
        $this->iscsiMounterService->removeRestore($assetKeyName, $snapshotPoint);
    }
}
