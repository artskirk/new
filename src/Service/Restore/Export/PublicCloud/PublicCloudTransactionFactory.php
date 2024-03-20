<?php

namespace Datto\Service\Restore\Export\PublicCloud;

use Datto\Restore\Export\AbstractExportTransactionFactory;
use Datto\Restore\Export\Context;
use Datto\System\Transaction\Transaction;

/**
 * A factory for transactions related to public cloud export.
 *
 * @author Chad Barbe <cbarbe@datto.com>
 */
class PublicCloudTransactionFactory extends AbstractExportTransactionFactory
{
    public function createExportTransaction(Context $context): Transaction
    {
        $this->logger->setAssetContext($context->getAgent()->getKeyName());

        $transaction = new Transaction(
            null,
            $this->logger,
            $context
        );
        $transaction
            ->add($this->mountSnapshotStage)
            ->add($this->hideFilesStage)
            ->add($this->imageExportStage)
            ->addIf(!empty($context->getSasUriMap()), $this->publicCloudUploadStage);

        return $transaction;
    }

    public function createRemoveTransaction(Context $context): Transaction
    {
        $this->logger->setAssetContext($context->getAgent()->getKeyName());

        $transaction = new Transaction(
            null,
            $this->logger,
            $context
        );
        $transaction
            ->add($this->removeImageExportStage)
            ->add($this->unmountSnapshotStage);

        return $transaction;
    }

    public function createRepairTransaction(Context $context): Transaction
    {
        $this->logger->setAssetContext($context->getAgent()->getKeyName());

        return new Transaction(
            null,
            $this->logger,
            $context
        );
    }
}
