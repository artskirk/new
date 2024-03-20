<?php

namespace Datto\Utility\Virtualization\GuestFs;

/**
 * Provides partition API of libguestfs.
 */
class PartitionManager extends GuestFsHelper
{
    /**
     * @var int The MBR partition entry ID for extended partitions.
     * @link https://en.wikipedia.org/wiki/Partition_type#List_of_partition_IDs
     */
    public const MBR_EXTENDED_ID = 0x5;

    /** @var string */
    public const PART_TYPE_MBR = 'msdos';

    /** @var string */
    public const PART_TYPE_GPT = 'gpt';

    /**
     * Parses the partition table on the device and returns partition locations.
     *
     * @param string $device The block device path, e.g. /dev/sda
     *
     * @return array An an array of arrays, each containing partition info.
     *  <code>
     *      $partitions = array(0 => array(
     *          'part_num' => 1,
     *          'part_start' => 0,
     *          'part_end' => 100,
     *          'part_size' => 101,
     *      ));
     *  </code>
     */
    public function getPartitionLocations(string $device): array
    {
        return $this->throwOnFalse(guestfs_part_list($this->getHandle(), $device));
    }

    /**
     * Checks if the given partition on the device has the boot flag set.
     *
     * @param string $device The block device path, without partition number, e.g. /dev/sda
     * @param int $partNumber The partition number.
     *
     * @return bool Whether the device is bootable
     */
    public function isBootable(string $device, int $partNumber): bool
    {
        return $this->throwOnNewError(function () use ($device, $partNumber) {
            return guestfs_part_get_bootable($this->getHandle(), $device, $partNumber);
        });
    }

    /**
     * Gets the partition type as in MBR or GPT.
     *
     * @param string $device A block device without part number, e.g. /dev/sda
     *
     * @return string PART_TYPE_MBR or PART_TYPE_GPT.
     */
    public function getPartitionType(string $device): string
    {
        return $this->throwOnFalse(guestfs_part_get_parttype($this->getHandle(), $device));
    }

    /**
     * Get the device path from partition.
     * Basically given "/dev/sda2", will return "/dev/sda"
     *
     * @param string $mountable A block device path that is mountable, e.g. /dev/sda1
     *
     * @return string
     */
    public function getPartitionDevice(string $mountable): string
    {
        return $this->throwOnFalse(guestfs_part_to_dev($this->getHandle(), $mountable));
    }

    /**
     * Get the partition number.
     *
     * Given "/dev/sda2", returns 2
     *
     * @param string $mountable A block device path that is mountable, e.g. /dev/sda1
     *
     * @return int
     */
    public function getPartitionNumber(string $mountable): int
    {
        return $this->throwOnFalse(guestfs_part_to_partnum($this->getHandle(), $mountable));
    }

    /**
     * Get partition label
     *
     * @param string $mountable A block device path that is mountable, e.g. /dev/sda1
     *
     * @return string The label, or an empty string if none exists
     */
    public function getPartitionLabel(string $mountable): string
    {
        return $this->throwOnFalse(guestfs_vfs_label($this->getHandle(), $mountable));
    }

    /**
     * Get partition's filesystem UUID
     *
     * @param string $mountable A block device path that is mountable, e.g. /dev/sda1
     *
     * @return string If no UUID, empty string, false on error.
     */
    public function getFilesystemUuid(string $mountable): string
    {
        return $this->throwOnFalse(guestfs_vfs_uuid($this->getHandle(), $mountable));
    }

    /**
     * Get the GPT partition GUID.
     *
     * @param string $mountable A block device path that is mountable, e.g. /dev/sda1
     *
     * @return string The unique GPT partition GUID, not the GPT type GUID.
     */
    public function getGptPartitionGuid(string $mountable): string
    {
        $blkid = $this->getBlkId($mountable);
        return $blkid['PART_ENTRY_UUID'] ?? '';
    }

    /**
     * Get device and/or partition info using libblkid
     *
     * @link https://mirrors.edge.kernel.org/pub/linux/utils/util-linux/v2.25/libblkid-docs/libblkid-Partitions-probing.html#libblkid-Partitions-probing.description
     *
     * This method performs low-level device probing (the same kind performed when passing
     * the `-p` flag to the `blkid` command). It can be run on either a $device or a $mountable
     * partition (e.g. `/dev/sda` or `/dev/sda1`) but it will return different information
     * for each.
     *
     * @param string $deviceOrMountable A block device (/dev/sda) or mountable partition (/dev/sda1)
     *
     * @return array
     */
    public function getBlkId(string $deviceOrMountable): array
    {
        return $this->throwOnFalse(guestfs_blkid($this->getHandle(), $deviceOrMountable));
    }

    /**
     * Returns the size of the partition in bytes.
     *
     * @param string $mountable A block device path that is mountable, e.g. /dev/sda1
     *
     * @return int Partition size in bytes
     */
    public function getPartitionSize(string $mountable): int
    {
        return $this->throwOnFalse(guestfs_blockdev_getsize64($this->getHandle(), $mountable));
    }

    /**
     * Returns the partition's sector size in bytes.
     *
     * @param string $mountable A block device path that is mountable, e.g. /dev/sda1
     *
     * @return int Sector size in bytes
     */
    public function getPartitionSectorSize(string $mountable): int
    {
        return $this->throwOnFalse(guestfs_blockdev_getss($this->getHandle(), $mountable));
    }

    /**
     * Returns the partition's block size in bytes.
     *
     * @param string $mountable A block device path that is mountable, e.g. /dev/sda1
     *
     * @return int Block size in bytes
     */
    public function getPartitionBlockSize(string $mountable): int
    {
        return $this->throwOnFalse(guestfs_blockdev_getbsz($this->getHandle(), $mountable));
    }

    /**
     * Returns whether given partition is extended type.
     *
     * @param string $mountable A block device path that is mountable, e.g. /dev/sda1
     *
     * @return bool
     */
    public function isExtended(string $mountable): bool
    {
        $device = $this->getPartitionDevice($mountable);
        $partNum = $this->getPartitionNumber($mountable);
        $partType = $this->getPartitionType($device);

        if ($partType === self::PART_TYPE_MBR) {
            $mbrId = $this->throwOnFalse(guestfs_part_get_mbr_id($this->getHandle(), $device, $partNum));

            // this is the extended partition code
            if ($mbrId === self::MBR_EXTENDED_ID) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns whether partition is LVM Logical Volume.
     *
     * @param string $mountable A block device path that is mountable, e.g. /dev/sda1
     *
     * @return bool
     */
    public function isLvmLv(string $mountable): bool
    {
        return $this->throwOnNewError(function () use ($mountable) {
            return guestfs_is_lv($this->getHandle(), $mountable);
        });
    }

    /**
     * Returns whether partition is LVM Physical Volume.
     *
     * @param string $mountable A block device path that is mountable, e.g. /dev/sda1
     *
     * @return bool
     */
    public function isLvmPv(string $mountable): bool
    {
        $pvs = $this->throwOnFalse(guestfs_pvs($this->getHandle()));

        $mountable = $this->getCanonicalDeviceName($mountable);

        foreach ($pvs as $pvDev) {
            if ($this->getCanonicalDeviceName($pvDev) == $mountable) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns LVM Physical Volume(s) details.
     *
     * @return array
     */
    public function getLvmPvsFull(): array
    {
        return $this->throwOnFalse(guestfs_pvs_full($this->getHandle()));
    }

    /**
     * Returns list of LVM Logical Volumes
     *
     * @return array
     */
    public function getLvmLvs(): array
    {
        return $this->throwOnFalse(guestfs_lvs($this->getHandle()));
    }

    /**
     * Returns LVM Logical Volume(s) details.
     *
     * @return array
     */
    public function getLvmLvsFull(): array
    {
        return $this->throwOnFalse(guestfs_lvs_full($this->getHandle()));
    }

    /**
     * Returns a canonical partition device path.
     *
     * Since some APIs return device paths in different formats, this method
     * converts it to canonical format, e.g. to make string comparisons easy.
     *
     * @param string $mountable A block device path that is mountable, e.g. /dev/sda1
     *
     * @return string
     */
    public function getCanonicalDeviceName(string $mountable): string
    {
        if ($this->isLvmLv($mountable)) {
            return $this->throwOnFalse(guestfs_lvm_canonical_lv_name($this->getHandle(), $mountable));
        } else {
            return $this->throwOnFalse(guestfs_canonical_device_name($this->getHandle(), $mountable));
        }
    }

    /**
     * Return the UUID assigned to the actual partition table on a block device
     *
     * @param string $device The block device (e.g. /dev/sda)
     * @return string The UUID for the partition table
     */
    public function getPartitionTableUuid(string $device): string
    {
        $blkid = $this->getBlkId($device);
        return $blkid['PTUUID'] ?? '';
    }

    /**
     * Get the filesystem type of the partition.
     *
     * @param string $mountable A block device path that is mountable, e.g. /dev/sda1
     *
     * @return string Will return e.g. 'ntfs', 'ext4' etc.
     */
    public function getFilesystemType(string $mountable): string
    {
        return $this->throwOnFalse(guestfs_vfs_type($this->getHandle(), $mountable));
    }
}
