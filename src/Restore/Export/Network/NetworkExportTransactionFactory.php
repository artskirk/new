<?php

namespace Datto\Restore\Export\Network;

use Datto\ImageExport\ImageType;
use Datto\Restore\Export\AbstractExportTransactionFactory;
use Datto\Restore\Export\Context;
use Datto\System\Transaction\Transaction;

/**
 * A factory for transactions related to network exports.
 *
 * @author Dawid Zamirski <dzamirski@datto.com>
 */
class NetworkExportTransactionFactory extends AbstractExportTransactionFactory
{
    public function createExportTransaction(Context $context): Transaction
    {
        $this->logger->setAssetContext($context->getAgent()->getKeyName());
        $isVmdkLinked = $context->getImageType() === ImageType::VMDK_LINKED();

        $transaction = new Transaction(null, $this->logger, $context);
        $transaction
            ->add($this->createRestoreStage)
            ->add($this->mountSnapshotStage)
            ->addIf($isVmdkLinked, $this->createTransparentMountStage)
            ->add($this->imageExportStage)
            ->add($this->createNetworkShareStage)
            ->add($this->saveRestoreStage);

        return $transaction;
    }

    public function createRemoveTransaction(Context $context): Transaction
    {
        $this->logger->setAssetContext($context->getAgent()->getKeyName());
        $isVmdkLinked = $context->getImageType() === ImageType::VMDK_LINKED();

        $transaction = new Transaction(null, $this->logger, $context);
        $transaction
            ->add($this->removeNetworkShareStage)
            ->addIf($isVmdkLinked, $this->removeTransparentMountStage, $this->removeImageExportStage)
            ->add($this->unmountSnapshotStage)
            ->add($this->removeRestoreStage);

        return $transaction;
    }

    public function createRepairTransaction(Context $context): Transaction
    {
        $this->logger->setAssetContext($context->getAgent()->getKeyName());
        $isVmdkLinked = $context->getImageType() === ImageType::VMDK_LINKED();

        $transaction = new Transaction(null, $this->logger, $context);
        $transaction
            ->addIf(!$isVmdkLinked, $this->repairImageExportStage)
            ->addIf(!$isVmdkLinked, $this->createNetworkShareStage);

        return $transaction;
    }
}
