<?php

namespace Datto\Restore\Export\Usb;

use Datto\ImageExport\ImageType;
use Datto\Restore\Export\AbstractExportTransactionFactory;
use Datto\Restore\Export\Context;
use Datto\System\Transaction\Transaction;
use Exception;

/**
 * A factory for transactions related to USB exports.
 *
 * @author Dawid Zamirski <dzamirski@datto.com>
 */
class UsbExportTransactionFactory extends AbstractExportTransactionFactory
{
    public function createExportTransaction(Context $context): Transaction
    {
        $this->logger->setAssetContext($context->getAgent()->getKeyName());
        $isVmdkLinked = $context->getImageType() === ImageType::VMDK_LINKED();

        $transaction = new Transaction(null, $this->logger, $context);
        $transaction
            ->add($this->usbExportLockStage)
            ->add($this->createRestoreUsbStage)
            ->add($this->findUsbDriveStage)
            ->add($this->mountSnapshotUsbStage)
            ->addIf($isVmdkLinked, $this->createTransparentMountUsbStage)
            ->add($this->imageExportStage)
            ->add($this->formatUsbDriveStage)
            ->add($this->mountUsbDriveStage)
            ->add($this->copyImagesToUsbStage);

        return $transaction;
    }

    public function createRemoveTransaction(Context $context): Transaction
    {
        $this->logger->setAssetContext($context->getAgent()->getKeyName());
        $isVmdkLinked = $context->getImageType() === ImageType::VMDK_LINKED();

        $transaction = new Transaction(null, $this->logger, $context);
        $transaction
            ->addIf($isVmdkLinked, $this->removeTransparentMountStage, $this->removeImageExportStage)
            ->add($this->unmountSnapshotStage)
            ->add($this->saveRestoreUsbStage);

        return $transaction;
    }

    public function createRepairTransaction(Context $context): Transaction
    {
        throw new Exception('Repair action is not supported for USB exports');
    }
}
