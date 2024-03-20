<?php

namespace Datto\Restore\Export\Stages;

/**
 * Remove the ui-restore associated with an export.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class RemoveRestoreStage extends AbstractRestoreUpdateStage
{
    /**
     * Attempts to execute this stage
     */
    public function commit()
    {
        $this->removeRestore();
    }

    /**
     * Clean up artifacts left behind in the commit stage
     */
    public function cleanup()
    {
        // Nothing
    }

    /**
     * Rolls back any committed changes
     */
    public function rollback()
    {
        // Nothing
    }
}
