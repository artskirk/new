<?php

namespace Datto\Restore\Export\Stages;

use Datto\ImageExport\Status;
use Datto\Restore\AssetCloneManager;

/**
 * This stage is responsible for mounting the snapshot during the conversion process.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class MountSnapshotStage extends AbstractStage
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
        $this->assetCloneManager->createClone($this->context->getCloneSpec());

        // set the export as in-progress
        $this->context->setStatus(new Status(true));
    }

    public function cleanup()
    {
        // nothing
    }

    /**
     * Rolls back any committed changes
     */
    public function rollback()
    {
        // since we unmount the clone, we don't need to worry about cleaning up the status
        $this->assetCloneManager->destroyClone($this->context->getCloneSpec());
    }
}
