<?php

namespace Datto\Restore\Export\Stages;

/**
 * Call the disk-image-export library to repair the image export.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class RepairImageExportStage extends AbstractImageExportStage
{
    public function commit(): void
    {
        $imageExportContext = $this->createImageExportContext();
        $this->imageExporter->repair($imageExportContext);
    }

    public function cleanup(): void
    {
        // Nothing to do
    }

    public function rollback(): void
    {
        // Nothing to do
    }
}
