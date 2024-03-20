<?php

namespace Datto\Restore\Differential\Rollback\Stages;

use Datto\Restore\AssetCloneManager;
use Datto\Restore\Differential\Rollback\DifferentialRollbackContext;
use Datto\Log\DeviceLoggerInterface;

/**
 * Creates a clone of the asset.
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class CreateCloneStage extends AbstractStage
{
    /** @var AssetCloneManager */
    private $assetCloneManager;

    /**
     * @param DifferentialRollbackContext $context
     * @param DeviceLoggerInterface $logger
     * @param AssetCloneManager $assetCloneManager
     */
    public function __construct(
        DifferentialRollbackContext $context,
        DeviceLoggerInterface $logger,
        AssetCloneManager $assetCloneManager
    ) {
        parent::__construct($context, $logger);

        $this->assetCloneManager = $assetCloneManager;
    }

    /**
     * {@inheritdoc}
     */
    public function commit()
    {
        $this->assetCloneManager->createClone($this->context->getCloneSpec());
    }

    /**
     * {@inheritdoc}
     */
    public function rollback()
    {
        $cloneName = $this->context->getCloneSpec()->getTargetDatasetName();

        $this->logger->debug("DFR0007 Removing clone $cloneName ...");

        $this->assetCloneManager->destroyClone($this->context->getCloneSpec());
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup()
    {
        // nothing
    }
}
