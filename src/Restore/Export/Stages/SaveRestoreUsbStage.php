<?php

namespace Datto\Restore\Export\Stages;

/**
 * Updates the ui-restore for a failed export.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class SaveRestoreUsbStage extends AbstractRestoreUpdateStage
{
    /**
     * Attempts to execute this stage
     */
    public function commit()
    {
        $this->updateRestore([
            'complete' => true,
            'failed' => true,
        ]);
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
