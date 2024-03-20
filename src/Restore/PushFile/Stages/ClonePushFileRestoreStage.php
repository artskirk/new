<?php

namespace Datto\Restore\PushFile\Stages;

use Datto\Restore\AssetCloneManager;
use Datto\Restore\CloneSpec;
use Datto\Restore\PushFile\AbstractPushFileRestoreStage;
use Datto\Restore\RestoreType;

/**
 * Prepare ZFS clone for push file restore.
 *
 * @author Ryan Mack <rmack@datto.com>
 */
class ClonePushFileRestoreStage extends AbstractPushFileRestoreStage
{
    private AssetCloneManager $assetCloneManager;

    public function __construct(AssetCloneManager $assetCloneManager)
    {
        $this->assetCloneManager = $assetCloneManager;
    }

    public function commit()
    {
        $cloneSpec = CloneSpec::fromAsset(
            $this->context->getAgent(),
            $this->context->getSnapshot(),
            RestoreType::PUSH_FILE
        );

        if ($this->assetCloneManager->exists($cloneSpec)) {
            $this->logger->error("CPS0001 Clone already exists", ['targetDatasetName' => $cloneSpec->getTargetDatasetName()]);
            throw new \Exception("Clone already exists: " . $cloneSpec->getTargetDatasetName());
        }

        $this->context->setCloneSpec($cloneSpec);
        $this->assetCloneManager->createClone($cloneSpec);
    }

    public function cleanup()
    {
        if ($this->context->getCloneSpec() !== null) {
            $this->assetCloneManager->destroyClone($this->context->getCloneSpec());
        }
    }
}
