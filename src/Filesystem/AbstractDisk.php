<?php

namespace Datto\Filesystem;

use Exception;

/**
 * Simple class to represent a disk
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
abstract class AbstractDisk
{
    const LBA_SIZE_IN_BYTES = 512;  /* this does not change with sector size */
    const DEFAULT_SECTOR_SIZE_IN_BYTES = 512;

    /** @var string The block device that holds the disk */
    private $blockDevice;

    /** @var int Sector size in bytes */
    private $sectorSize;

    /** @var AbstractPartition[] Array of partition objects (index 0 = partition 1) */
    private $partitions;

    /**
     * @param string $blockDevice
     * @param int $sectorSize Number of bytes per sector
     */
    public function __construct(
        string $blockDevice,
        int $sectorSize = self::DEFAULT_SECTOR_SIZE_IN_BYTES
    ) {
        $this->blockDevice = $blockDevice;
        $this->sectorSize = $sectorSize;
        $this->partitions = [];
    }

    /**
     * Adds a new partition based on an existing partition.
     * Only the size and type will be used from the existing partition.
     *
     * @param AbstractPartition $partition
     */
    public function addPartition(AbstractPartition $partition)
    {
        $this->partitions[] = $partition;
    }

    /**
     * @return string Block device that holds the disk contents
     */
    public function getBlockDevice(): string
    {
        return $this->blockDevice;
    }

    /**
     * @return AbstractPartition[] Array of partition objects
     */
    public function getPartitions(): array
    {
        return $this->partitions;
    }

    /**
     * @return int The number of partitions
     */
    public function getPartitionCount(): int
    {
        return count($this->partitions);
    }

    /**
     * @return int Number of bytes per sector
     */
    public function getSectorSize(): int
    {
        return $this->sectorSize;
    }

    /**
     * Gets the specified partition object.
     *
     * @param int $partitionNumber Partition number, starting at 1
     * @return AbstractPartition
     */
    public function getPartition(int $partitionNumber): AbstractPartition
    {
        $index = $partitionNumber - 1;
        if ($index < 0 || $index >= count($this->partitions)) {
            throw new Exception("Invalid partition number ($partitionNumber)");
        }
        return $this->partitions[$index];
    }

    /**
     * Gets the size of the specified partition.
     * This does not include any padding added at the end to align with a
     * partition boundary.
     *
     * @param int $partitionNumber Partition number, starting at 1
     * @return int Size of the partition in bytes
     */
    public function getPartitionSize(int $partitionNumber): int
    {
        $partition = $this->getPartition($partitionNumber);
        $sizeInSectors = $partition->getLastSector() - $partition->getFirstSector() + 1;
        $sizeInBytes = $sizeInSectors * $partition->getSectorSize();
        return $sizeInBytes;
    }

    /**
     * Calculates and returns the offset of the specified partition.
     *
     * @param int $partitionNumber Partition number, starting at 1.
     *    Must be in the range 1 to getPartitionCount() + 1.
     * @return int Offset of the partition on the disk in bytes
     */
    public function getPartitionOffset(int $partitionNumber): int
    {
        $offset = $this->getFirstUsableOffset();
        for ($currentPartitionNumber = 1; $currentPartitionNumber < $partitionNumber; $currentPartitionNumber++) {
            $size = $this->getPartitionSize($currentPartitionNumber);
            $offset = $this->alignToNextPartition($offset + $size);
        }
        return $offset;
    }

    /**
     * Gets the size of the partition table including any padding at the
     * end to align the first partition.

     * @return int Size of the partition table and padding in bytes
     */
    public function getPartitionTableSizeWithPadding(): int
    {
        return $this->getFirstUsableOffset();
    }

    /**
     * Gets the size of the specified partition including any padding at the
     * end to align the next partition.
     *
     * @param int $partitionNumber Partition number, starting at 1
     * @return int Size of the partition and padding in bytes
     */
    public function getPartitionSizeWithPadding(int $partitionNumber): int
    {
        $offset1 = $this->getPartitionOffset($partitionNumber);
        $offset2 = $this->getPartitionOffset($partitionNumber + 1);
        return $offset2 - $offset1;
    }

    /**
     * Determine whether the disk contains a bootable partition
     *
     * @return bool whether the disk contains a bootable partition
     */
    public function hasBootablePartition(): bool
    {
        foreach ($this->partitions as $partition) {
            if ($partition->isBootable()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get the size of the partition table.
     * This does not include any padding on the end for sector alignment.
     *
     * @return int Size of the partition table in bytes
     */
    abstract public function getPartitionTableSize(): int;

    /**
     * Gets the partition alignment.
     *
     * @return int Partition alignment in bytes.
     */
    abstract public function getPartitionAlignment(): int;

    /**
     * Determines if the disk format has a trailing second copy of the
     * partition table (e.g. GPT).
     *
     * @return bool
     */
    abstract public function hasSecondaryPartitionTable(): bool;

    /**
     * Get the size of the secondary partition table.
     * This returns 0 if there is no secondary partition table.
     *
     * @return int Size of the secondary partition table in bytes
     */
    abstract public function getSecondaryPartitionTableSize(): int;

    /**
     * Get the offset of the secondary partition table.
     * This returns 0 if there is no secondary partition table.
     *
     * @return int Offset of the secondary partition table in bytes
     */
    abstract public function getSecondaryPartitionTableOffset(): int;

    /**
     * Get the total size of the disk with all partitions.
     * The disk must have at least 1 partition or this function will
     * generate an exception.
     *
     * @return int Size of the disk in bytes
     */
    abstract public function getTotalDiskSize(): int;

    /**
     * Aligns an offset to the next sector boundary.
     *
     * @param int $offset Byte offset from the beginning of the disk
     * @return int Byte offset aligned to the next sector boundary
     */
    protected function alignToNextSector(int $offset): int
    {
        return $this->align($offset, $this->getSectorSize());
    }

    /**
     * Aligns an offset to the next partition boundary.
     *
     * @param int $offset Byte offset from the beginning of the disk
     * @return int Byte offset aligned to the next partition boundary
     */
    protected function alignToNextPartition(int $offset): int
    {
        return $this->align($offset, $this->getPartitionAlignment());
    }

    /**
     * Aligns an offset to a boundary.
     *
     * @param int $offset Byte offset from the beginning of the disk
     * @param int $size Boundary size in bytes
     * @return int Byte offset aligned to the next boundary
     */
    protected function align(int $offset, int $size): int
    {
        return ceil($offset / $size) * $size;
    }

    /**
     * Get the offset of the first partition on the disk.
     *
     * @return int Offset from the beginning of the disk in bytes.
     */
    private function getFirstUsableOffset(): int
    {
        return $this->alignToNextPartition($this->getPartitionTableSize());
    }
}
