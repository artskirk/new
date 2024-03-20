<?php

namespace Datto\Restore\File\Stages;

use Datto\Restore\AssetCloneManager;
use Datto\Restore\CloneSpec;
use Datto\Restore\File\AbstractFileRestoreStage;
use Datto\Restore\File\FileRestoreContext;
use Datto\Restore\RestoreType;
use Datto\Log\DeviceLoggerInterface;

/**
 * Prepare ZFS clone for file restore.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class CloneFileRestoreStage extends AbstractFileRestoreStage
{
    /** @var AssetCloneManager */
    private $assetCloneManager;

    /**
     * @param FileRestoreContext $context
     * @param DeviceLoggerInterface $logger
     * @param AssetCloneManager $assetCloneManager
     */
    public function __construct(FileRestoreContext $context, DeviceLoggerInterface $logger, AssetCloneManager $assetCloneManager)
    {
        parent::__construct($context, $logger);
        $this->assetCloneManager = $assetCloneManager;
    }


    /**
     * Attempts to execute this stage
     */
    public function commit()
    {
        $cloneSpec = CloneSpec::fromAsset(
            $this->context->getAsset(),
            $this->context->getSnapshot(),
            RestoreType::FILE
        );

        if ($this->assetCloneManager->exists($cloneSpec)) {
            $datasetName = $cloneSpec->getTargetDatasetName();
            $this->logger->error('FIR0007 Clone already exists', ['cloneDatasetName' => $datasetName]);
            throw new \Exception("Clone already exists: $datasetName");
        }

        $this->context->setCloneSpec($cloneSpec);
        $this->assetCloneManager->createClone($cloneSpec);
    }

    /**
     * Clean up artifacts left behind in the commit stage
     */
    public function cleanup()
    {
        // nothing
    }

    /**
     * Rolls back any committed changes
     */
    public function rollback()
    {
        $this->assetCloneManager->destroyClone($this->context->getCloneSpec());
    }
}
