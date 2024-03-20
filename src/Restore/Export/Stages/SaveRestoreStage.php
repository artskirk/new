<?php

namespace Datto\Restore\Export\Stages;

/**
 * Save a new ui-restore for an export.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class SaveRestoreStage extends AbstractRestoreUpdateStage
{
    /**
     * Attempts to execute this stage
     */
    public function commit()
    {
        $this->updateRestore(['complete' => true]);
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
