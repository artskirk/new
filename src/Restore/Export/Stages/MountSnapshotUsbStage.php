<?php

namespace Datto\Restore\Export\Stages;

/**
 * This stage is responsible for mounting the snapshot during the conversion process.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class MountSnapshotUsbStage extends MountSnapshotStage
{
    /**
     * Clean up artifacts left behind in the commit stage
     */
    public function cleanup()
    {
        $this->rollback();
    }
}
