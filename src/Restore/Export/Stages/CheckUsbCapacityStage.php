<?php

namespace Datto\Restore\Export\Stages;

use Datto\Common\Utility\Filesystem;
use Datto\Restore\Export\Stages\AbstractStage;
use Datto\Restore\Export\Usb\UsbExportContext;
use Datto\System\Transaction\TransactionException;

/**
 * Checks that the USB drive is big enough to hold the exported image.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class CheckUsbCapacityStage extends AbstractStage
{
    const IMAGE_FILE_PATTERNS = ['*.datto', '*.vmdk'];

    /** @var Filesystem */
    private $filesystem;

    public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * {@inheritdoc}
     */
    public function commit()
    {
        $datasetSize = $this->getExportDataSize($this->context->getCloneMountPoint());
        if ($this->context->getUsbInformation()->getSize() < $datasetSize) {
            throw new TransactionException("Dataset is too large for target disk");
        }
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup()
    {
    }

    /**
     * {@inheritdoc}
     */
    public function rollback()
    {
    }

    /**
     * Get the size of the data to be exported.
     *
     * This is based on the size of the image files generated by HIR that can be copied to the drive.
     *
     * @param string $cloneDir
     * @return int
     */
    private function getExportDataSize(string $cloneDir): int
    {
        $files = [];
        $totalSize = 0;
        foreach (static::IMAGE_FILE_PATTERNS as $pattern) {
            $files = array_merge($files, $this->filesystem->glob($cloneDir . "/" . $pattern));
        }
        foreach ($files as $file) {
            $totalSize += $this->filesystem->getSize($file);
        }
        return $totalSize;
    }
}
