<?php

namespace Datto\Restore\Export\Stages;

/**
 * Create a new UI restore for an export.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class CreateRestoreStage extends AbstractRestoreUpdateStage
{
    /**
     * Attempts to execute this stage
     */
    public function commit()
    {
        $this->createRestore();
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
        $optionChanges = [
            'complete' => true,
            'failed' => true,
        ];
        $this->updateRestore($optionChanges);
    }
}
