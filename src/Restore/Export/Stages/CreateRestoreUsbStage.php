<?php

namespace Datto\Restore\Export\Stages;

/**
 * Manage creation of UI restores for USB export.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class CreateRestoreUsbStage extends AbstractRestoreUpdateStage
{
    /**
     * {@inheritdoc}
     */
    public function commit()
    {
        $this->createRestore();
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup()
    {
        $this->removeRestore();
    }

    /**
     * {@inheritdoc}
     */
    public function rollback()
    {
        if ($this->context->isCancelled()) {
            $this->removeRestore();
        } else {
            $optionsChanged = [
                'complete' => true,
                'failed' => true,
            ];
            $this->updateRestore($optionsChanged);
        }
    }
}
