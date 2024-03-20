<?php

namespace Datto\System;

/**
 * This class represents a mount. It is a working model that can be
 * expanded in the future.
 *
 * @author John Roland <jroland@datto.com>
 * @author Pim Otte <potte@datto.com>
 */
class Mount
{
    const PURPOSE_ACTIVE = 'active';
    const PURPOSE_BMR = 'bmr';
    const PURPOSE_ESX = 'esx';
    const PURPOSE_ESX_UPLOAD = 'esxUpload';
    const PURPOSE_EXPORT = 'export';
    const PURPOSE_SCREENSHOT = 'screenshot';
    const PURPOSE_VERIFICATION = 'verification';
    const PURPOSE_USB_BMR = 'usbBmr';
    const PURPOSE_VHD = 'vhd';
    const PURPOSE_VMDK = 'vmdk';
    const PURPOSE_DISKLESS = 'diskless';
    const PURPOSE_EXTNAS = 'extnas';

    const PURPOSE_UNKNOWN = 'UNKNOWN';

    const PURPOSE_DELIMITER = '-';

    // List of known mount purposes. NOTE: This list may not be complete.
    private static $knownMountPurposes = array(
        Mount::PURPOSE_ACTIVE,
        Mount::PURPOSE_BMR,
        Mount::PURPOSE_ESX,
        Mount::PURPOSE_ESX_UPLOAD,
        Mount::PURPOSE_EXPORT,
        Mount::PURPOSE_SCREENSHOT,
        Mount::PURPOSE_VERIFICATION,
        Mount::PURPOSE_USB_BMR,
        Mount::PURPOSE_VHD,
        Mount::PURPOSE_VMDK,
        Mount::PURPOSE_DISKLESS,
        Mount::PURPOSE_EXTNAS
    );

    /** @var string Path to the mount point */
    private $mountPoint;

    /** @var string Block device path used for the mount */
    private $device;

    /** @var string Filesystem type of the mount point */
    private $filesystemType;

    /** @var string Purpose of the mount */
    private $purpose;

    /**
     * Create a new instance of Mount and infer the purpose
     * of the mount from the mount point.
     *
     * @param string $mountPoint Path to the mount point
     * @param string $device Block device path used for the mount
     * @param string $filesystemType Filesystem type of the mount point
     */
    public function __construct(
        $mountPoint,
        $device,
        $filesystemType
    ) {
        $this->mountPoint = $mountPoint;
        $this->device = $device;
        $this->filesystemType = $filesystemType;

        $this->setPurpose($mountPoint);
    }

    /**
     * Returns the path to the mountpoint.
     *
     * @return string
     */
    public function getMountPoint()
    {
        return $this->mountPoint;
    }

    /**
     * Returns block device path used for the mount.
     *
     * @return string
     */
    public function getDevice()
    {
        return $this->device;
    }

    /**
     * Returns filesystem type of the mountpoint.
     *
     * @return string
     */
    public function getFilesystemType()
    {
        return $this->filesystemType;
    }

    /**
     * The purpose of the mount is determined by the suffix of the
     * mount point.
     *
     * Example, for a mount point
     * 'homepool/10.0.21.156-screenshot' the purpose is 'screenshot'.
     *
     * @return string the purpose of the mount.
     */
    public function getPurpose()
    {
        return $this->purpose;
    }

    /**
     * Set the purpose of the mount based on the mount point.
     *
     * @param string $mountPoint The path to the mount point
     */
    private function setPurpose($mountPoint)
    {
        $this->purpose = Mount::PURPOSE_UNKNOWN;

        $parts = explode(static::PURPOSE_DELIMITER, $mountPoint);
        if (count($parts) >= 2) {
            $suffix = $parts[count($parts) - 1];

            if (in_array($suffix, Mount::$knownMountPurposes)) {
                $this->purpose = $suffix;
            }
        }
    }
}
