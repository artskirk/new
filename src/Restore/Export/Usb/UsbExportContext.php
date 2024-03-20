<?php

namespace Datto\Restore\Export\Usb;

use Datto\Asset\Agent\Agent;
use Datto\Common\Utility\Filesystem\AbstractFuseOverlayMount;
use Datto\ImageExport\BootType;
use Datto\ImageExport\ImageType;
use Datto\Restore\Export\Context;
use Datto\Restore\Export\Serializers\StatusSerializer;
use Datto\Common\Utility\Filesystem;

/**
 * Context for exporting images to a USB drive.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class UsbExportContext extends Context
{
    private UsbDrive $usbDrive;
    private bool $cancelled;

    public function __construct(
        Agent $agent,
        int $snapshot,
        ImageType $type,
        AbstractFuseOverlayMount $fuseOverlayMount,
        BootType $bootType = null,
        Filesystem $filesystem = null,
        StatusSerializer $statusSerializer = null
    ) {
        parent::__construct($agent, $snapshot, $type, $fuseOverlayMount, false, $bootType, $filesystem, $statusSerializer);

        $this->cancelled = false;
    }

    /**
     * Save the path and size of the USB disk.
     *
     * @param string $disk
     * @param int $size
     */
    public function setUsbInformation(string $disk, int $size)
    {
        $this->usbDrive = new UsbDrive(
            $disk,
            $size,
            $this->getAgent()->getKeyName(),
            $this->getSnapshot(),
            $this->getImageType()->value()
        );
    }

    /**
     * Get the USB drive information.
     *
     * @return UsbDrive
     */
    public function getUsbInformation(): UsbDrive
    {
        return $this->usbDrive;
    }

    /**
     * {@inheritdoc}
     */
    public function isNetworkExport(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function isCancelled(): bool
    {
        if (!$this->cancelled) {
            $cancelFile = $this->getCloneMountPoint() . '/' . UsbExporter::CANCEL_FILE;
            $this->cancelled = $this->filesystem->exists($cancelFile);
        }

        return $this->cancelled;
    }
}
