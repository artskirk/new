<?php

namespace Datto\Restore\Export\Stages;

use Datto\Restore\AssetCloneManager;

/**
 * This stage is responsible for unmounting the snapshot used during an export.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class UnmountSnapshotStage extends AbstractStage
{
    /** @var AssetCloneManager */
    private $assetCloneManager;

    public function __construct(AssetCloneManager $assetCloneManager)
    {
        $this->assetCloneManager = $assetCloneManager;
    }

    /**
     * Attempts to execute this stage
     */
    public function commit()
    {
        $this->assetCloneManager->destroyClone($this->context->getCloneSpec());
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
        // nothing
    }
}
