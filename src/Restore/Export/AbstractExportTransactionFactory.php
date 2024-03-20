<?php

namespace Datto\Restore\Export;

use Datto\Log\LoggerAwareTrait;
use Datto\Restore\Export\Stages\CheckUsbCapacityStage;
use Datto\Restore\Export\Stages\CopyImagesToUsbStage;
use Datto\Restore\Export\Stages\CreateNetworkShareStage;
use Datto\Restore\Export\Stages\CreateRestoreStage;
use Datto\Restore\Export\Stages\CreateRestoreUsbStage;
use Datto\Restore\Export\Stages\CreateTransparentMountStage;
use Datto\Restore\Export\Stages\CreateTransparentMountUsbStage;
use Datto\Restore\Export\Stages\FindUsbDriveStage;
use Datto\Restore\Export\Stages\FormatUsbDriveStage;
use Datto\Restore\Export\Stages\HideFilesStage;
use Datto\Restore\Export\Stages\ImageExportStage;
use Datto\Restore\Export\Stages\MountSnapshotStage;
use Datto\Restore\Export\Stages\MountSnapshotUsbStage;
use Datto\Restore\Export\Stages\MountUsbDriveStage;
use Datto\Restore\Export\Stages\RemoveImageExportStage;
use Datto\Restore\Export\Stages\RemoveNetworkShareStage;
use Datto\Restore\Export\Stages\RemoveRestoreStage;
use Datto\Restore\Export\Stages\RemoveTransparentMountStage;
use Datto\Restore\Export\Stages\RepairImageExportStage;
use Datto\Restore\Export\Stages\SaveRestoreStage;
use Datto\Restore\Export\Stages\SaveRestoreUsbStage;
use Datto\Restore\Export\Stages\UnmountSnapshotStage;
use Datto\Restore\Export\Stages\UsbExportLockStage;
use Datto\Service\Restore\Export\PublicCloud\PublicCloudUploadStage;
use Datto\System\Transaction\Transaction;
use Psr\Log\LoggerAwareInterface;

/**
 * A factory to create export transactions.
 *
 * @author Dawid Zamirski <dzamirski@datto.com>
 */
abstract class AbstractExportTransactionFactory implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var CheckUsbCapacityStage */
    protected $checkUsbCapacityStage;

    /** @var CopyImagesToUsbStage */
    protected $copyImagesToUsbStage;

    /** @var CreateNetworkShareStage */
    protected $createNetworkShareStage;

    /** @var CreateRestoreStage */
    protected $createRestoreStage;

    /** @var CreateRestoreUsbStage */
    protected $createRestoreUsbStage;

    /** @var CreateTransparentMountStage */
    protected $createTransparentMountStage;

    /** @var CreateTransparentMountUsbStage */
    protected $createTransparentMountUsbStage;

    /** @var FindUsbDriveStage */
    protected $findUsbDriveStage;

    /** @var FormatUsbDriveStage */
    protected $formatUsbDriveStage;

    /** @var MountSnapshotStage */
    protected $mountSnapshotStage;

    /** @var MountSnapshotUsbStage */
    protected $mountSnapshotUsbStage;

    /** @var MountUsbDriveStage */
    protected $mountUsbDriveStage;

    /** @var RemoveImageExportStage */
    protected $removeImageExportStage;

    /** @var RemoveNetworkShareStage */
    protected $removeNetworkShareStage;

    /** @var RemoveRestoreStage */
    protected $removeRestoreStage;

    /** @var RemoveTransparentMountStage */
    protected $removeTransparentMountStage;

    /** @var RepairImageExportStage */
    protected $repairImageExportStage;

    /** @var SaveRestoreStage */
    protected $saveRestoreStage;

    /** @var SaveRestoreUsbStage */
    protected $saveRestoreUsbStage;

    /** @var UnmountSnapshotStage */
    protected $unmountSnapshotStage;

    /** @var UsbExportLockStage */
    protected $usbExportLockStage;

    /** @var PublicCloudUploadStage */
    protected $publicCloudUploadStage;

    /** @var HideFilesStage */
    protected $hideFilesStage;

    /** @var ImageExportStage */
    protected $imageExportStage;

    public function __construct(
        CheckUsbCapacityStage $checkUsbCapacityStage,
        CopyImagesToUsbStage $copyImagesToUsbStage,
        CreateNetworkShareStage $createNetworkShareStage,
        CreateRestoreStage $createRestoreStage,
        CreateRestoreUsbStage $createRestoreUsbStage,
        CreateTransparentMountStage $createTransparentMountStage,
        CreateTransparentMountUsbStage $createTransparentMountUsbStage,
        FindUsbDriveStage $findUsbDriveStage,
        FormatUsbDriveStage $formatUsbDriveStage,
        ImageExportStage $imageExportStage,
        MountSnapshotStage $mountSnapshotStage,
        MountSnapshotUsbStage $mountSnapshotUsbStage,
        MountUsbDriveStage $mountUsbDriveStage,
        RemoveImageExportStage $removeImageExportStage,
        RemoveNetworkShareStage $removeNetworkShareStage,
        RemoveRestoreStage $removeRestoreStage,
        RemoveTransparentMountStage $removeTransparentMountStage,
        RepairImageExportStage $repairImageExportStage,
        SaveRestoreStage $saveRestoreStage,
        SaveRestoreUsbStage $saveRestoreUsbStage,
        UnmountSnapshotStage $unmountSnapshotStage,
        UsbExportLockStage $usbExportLockStage,
        PublicCloudUploadStage $publicCloudUploadStage,
        HideFilesStage $hideFilesStage
    ) {
        $this->checkUsbCapacityStage = $checkUsbCapacityStage;
        $this->copyImagesToUsbStage = $copyImagesToUsbStage;
        $this->createNetworkShareStage = $createNetworkShareStage;
        $this->createRestoreStage = $createRestoreStage;
        $this->createRestoreUsbStage = $createRestoreUsbStage;
        $this->createTransparentMountStage = $createTransparentMountStage;
        $this->createTransparentMountUsbStage = $createTransparentMountUsbStage;
        $this->findUsbDriveStage = $findUsbDriveStage;
        $this->formatUsbDriveStage = $formatUsbDriveStage;
        $this->imageExportStage = $imageExportStage;
        $this->mountSnapshotStage = $mountSnapshotStage;
        $this->mountSnapshotUsbStage = $mountSnapshotUsbStage;
        $this->mountUsbDriveStage = $mountUsbDriveStage;
        $this->removeImageExportStage = $removeImageExportStage;
        $this->removeNetworkShareStage = $removeNetworkShareStage;
        $this->removeRestoreStage = $removeRestoreStage;
        $this->removeTransparentMountStage = $removeTransparentMountStage;
        $this->repairImageExportStage = $repairImageExportStage;
        $this->saveRestoreStage = $saveRestoreStage;
        $this->saveRestoreUsbStage = $saveRestoreUsbStage;
        $this->unmountSnapshotStage = $unmountSnapshotStage;
        $this->usbExportLockStage = $usbExportLockStage;
        $this->publicCloudUploadStage = $publicCloudUploadStage;
        $this->hideFilesStage = $hideFilesStage;
    }

    abstract public function createExportTransaction(Context $context): Transaction;
    abstract public function createRepairTransaction(Context $context): Transaction;
    abstract public function createRemoveTransaction(Context $context): Transaction;
}
