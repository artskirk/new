<?php

namespace Datto\Verification\Stages;

use Datto\Restore\AssetCloneManager;
use Datto\Verification\VerificationResultType;
use Throwable;

/**
 * Create -verification zfs clone for screenshotting purposes.
 *
 * Giovanni Carvelli <gcarvelli@datto.com>
 */
class CreateClone extends VerificationStage
{
    /** @var AssetCloneManager */
    private $assetCloneManager;

    public function __construct(AssetCloneManager $assetCloneManager)
    {
        $this->assetCloneManager = $assetCloneManager;
    }

    public function commit()
    {
        try {
            $cloneSpec = $this->context->getCloneSpec();
            $this->assetCloneManager->createClone($cloneSpec);
        } catch (Throwable $e) {
            $result = VerificationResultType::FAILURE_UNRECOVERABLE();
            $this->setResult($result, $e->getMessage(), $e->getTraceAsString());
            throw $e;
        }

        if (!$this->result) {
            $this->setResult(VerificationResultType::SUCCESS());
        }
    }

    public function cleanup()
    {
        $cloneSpec = $this->context->getCloneSpec();
        $this->assetCloneManager->destroyClone($cloneSpec);
    }
}
