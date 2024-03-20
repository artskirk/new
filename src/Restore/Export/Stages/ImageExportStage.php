<?php

namespace Datto\Restore\Export\Stages;

/**
 * Call the disk-image-export library to perform the image export.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class ImageExportStage extends AbstractImageExportStage
{
    public function commit(): void
    {
        $imageExportContext = $this->createImageExportContext();
        // Initialize to a empty array to work around an uninitialized variable
        // error in the disk-image-export library getExportedFiles() function
        $imageExportContext->setExportedFiles([]);
        $this->imageExporter->export($imageExportContext);
        // The following is needed by PublicCloudUploadStage
        $this->context->setExportedFiles($imageExportContext->getExportedFiles());
    }

    public function cleanup(): void
    {
        if (!$this->context->isNetworkExport()) {
            $imageExportContext = $this->createImageExportContext();
            $this->imageExporter->remove($imageExportContext);
        }
    }

    public function rollback(): void
    {
        $imageExportContext = $this->createImageExportContext();
        $this->imageExporter->remove($imageExportContext);
    }
}
