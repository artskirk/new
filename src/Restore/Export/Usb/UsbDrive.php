<?php

namespace Datto\Restore\Export\Usb;

/**
 * Represents a USB drive used for an image export.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class UsbDrive
{
    const PARTITION_NUMBER = 1;
    const MOUNT_PATH_FORMAT = "/tmp/usbMnt-%s-%d-%s";

    /** @var string */
    private $format;

    /** @var int */
    private $snapshot;

    /** @var string */
    private $keyName;

    /** @var int */
    private $usbDriveSize;

    /** @var string */
    private $usbDisk;

    /**
     * @param string $disk
     * @param int $size
     * @param string $keyName
     * @param int $snapshot
     * @param string $format
     */
    public function __construct(
        string $disk,
        int $size,
        string $keyName,
        int $snapshot,
        string $format
    ) {
        $this->usbDisk = $disk;
        $this->usbDriveSize = $size;
        $this->keyName = $keyName;
        $this->snapshot = $snapshot;
        $this->format = $format;
    }

    /**
     * Get path to the raw block device of the USB drive.
     *
     * @return string
     */
    public function getPath(): string
    {
        return $this->usbDisk;
    }

    /**
     * Get the path to the device for the formatted partition.
     *
     * @return string
     */
    public function getPartition(): string
    {
        return $this->usbDisk . static::PARTITION_NUMBER;
    }

    /**
     * Get the size of the disk in bytes.
     *
     * @return int
     */
    public function getSize(): int
    {
        return $this->usbDriveSize;
    }

    /**
     * Get the path to the mount point for the USB drive.
     *
     * @return string
     */
    public function getMountPoint(): string
    {
        return sprintf(static::MOUNT_PATH_FORMAT, $this->keyName, $this->snapshot, $this->format);
    }
}
