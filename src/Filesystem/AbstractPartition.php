<?php

namespace Datto\Filesystem;

/**
 * Simple class to represent a disk partition, used for fdisk commands
 *
 * @author Andrew Cope <acope@datto.com>
 */
abstract class AbstractPartition
{
    const SIZE_IDENTIFIER_KB = 'K';
    const SIZE_IDENTIFIER_MB = 'M';
    const SIZE_IDENTIFIER_GB = 'G';
    const SIZE_IDENTIFIER_TB = 'T';
    const SIZE_IDENTIFIER_PB = 'P';

    const DEFAULT_PARTITION_NUMBER = 1;

    /**
     * The block device that holds the partition
     *
     * @var string
     */
    private $blockDevice;

    /**
     * The partition number
     *
     * @var int
     */
    private $number;

    /**
     * The partition size
     *
     * @var int
     */
    private $size;

    /**
     * The partition size identifier (e.g. G for GB)
     * @var string
     */
    private $sizeIdentifier;

    /** @var string */
    private $partitionType;

    /** @var int|null */
    private $firstSector;

    /** @var int|null */
    private $lastSector;

    /** @var int|null */
    private $sectorSize;

    /** @var bool true if the partition is bootable */
    private $bootable;

    /**
     * @param string $blockDevice
     * @param int $partitionNumber
     * @param string $partitionType
     * @param bool $bootable
     */
    protected function __construct(
        string $blockDevice,
        int $partitionNumber,
        string $partitionType,
        bool $bootable
    ) {
        $this->blockDevice = $blockDevice;
        $this->number = $partitionNumber;
        $this->sizeIdentifier = self::SIZE_IDENTIFIER_GB;
        $this->partitionType = $partitionType;
        $this->bootable = $bootable;
    }

    /**
     * @return string
     */
    public function getBlockDevice(): string
    {
        return $this->blockDevice;
    }

    /**
     * @return string
     */
    public function getPartitionType(): string
    {
        return $this->partitionType;
    }

    /**
     * Partition Type code used by disk format utility
     * (ex: for fdisk, 'b' is used for FAT32)
     *
     * @param string $type
     */
    public function setPartitionType(string $type)
    {
        $this->partitionType = $type;
    }

    /**
     * @return int
     */
    public function getPartitionNumber(): int
    {
        return $this->number;
    }

    /**
     * @return int|null
     */
    public function getSize()
    {
        return $this->size;
    }

    /**
     * @return string|null
     */
    public function getSizeIdentifier()
    {
        return $this->sizeIdentifier;
    }

    /**
     * Get the desired size formatted with identifier
     * NOTE: this may be empty string if size is not set,
     * in which case the size will be selected by the disk format utility. (Use all available space)
     * Also see getFirstSector.
     *
     * @return string
     */
    public function getFormattedSize(): string
    {
        if (empty($this->size)) {
            return '';
        }

        return '+' . $this->size . $this->sizeIdentifier;
    }

    /**
     * Set the partition size and size identifier
     * @param int $size
     * @param string $identifier
     */
    public function setSize(int $size, string $identifier)
    {
        $this->size = $size;
        $this->sizeIdentifier = $identifier;
    }

    /**
     * The the first sector of the partition.
     * This value may be null, in which case value will be selected by the disk format utility
     *
     * @return int|null
     */
    public function getFirstSector()
    {
        return $this->firstSector;
    }

    /**
     * Set the first sector of the partition
     *
     * @param int|null $firstSector
     */
    public function setFirstSector($firstSector)
    {
        $this->firstSector = $firstSector;
    }

    /**
     * Get the location of the last sector of the partition.
     * Used by the PartitionService to set the size of the partition.
     * The last sector is inclusive to the range.
     * If this value is null, the PartitionService will attempt to use getFormattedSize
     *
     * @return int|null
     */
    public function getLastSector()
    {
        return $this->lastSector;
    }

    /**
     * @param int|null $lastSector
     */
    public function setLastSector($lastSector)
    {
        $this->lastSector = $lastSector;
    }

    /**
     * @return int|null number of bytes per sector
     */
    public function getSectorSize()
    {
        return $this->sectorSize;
    }

    /**
     * @param int $sectorSize number of bytes per sector
     */
    public function setSectorSize(int $sectorSize)
    {
        $this->sectorSize = $sectorSize;
    }

    /**
     * @return bool
     */
    public function isBootable(): bool
    {
        return $this->bootable;
    }

    /**
     * @param bool $bootable
     */
    public function setIsBootable(bool $bootable)
    {
        $this->bootable = $bootable;
    }

    /**
     * @return string
     */
    public function toString(): string
    {
        return $this->blockDevice . $this->number;
    }
}
