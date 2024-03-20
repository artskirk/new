<?php

namespace Datto\Restore\Export\Stages;

/**
 * Call the disk-image-export library to remove the image export.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class RemoveImageExportStage extends AbstractImageExportStage
{
    public function commit(): void
    {
        $imageExportContext = $this->createImageExportContext();
        $this->imageExporter->remove($imageExportContext);
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
