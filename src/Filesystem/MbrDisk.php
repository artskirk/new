<?php

namespace Datto\Filesystem;

/**
 * Simple class to represent an MBR partitioned disk
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class MbrDisk extends AbstractDisk
{
    const MBR_SIZE_IN_BYTES = 512;
    // This is the default partition alignment for fdisk when DOS compatibility
    // mode is disabled.  This number should not be changed.
    const PARTITION_ALIGNMENT_IN_LBA_UNITS = 2048;

    /**
     * {@inheritdoc}
     */
    public function getPartitionTableSize(): int
    {
        return self::MBR_SIZE_IN_BYTES;
    }

    /**
     * {@inheritdoc}
     */
    public function getPartitionAlignment(): int
    {
        return self::PARTITION_ALIGNMENT_IN_LBA_UNITS * self::LBA_SIZE_IN_BYTES;
    }

    /**
     * {@inheritdoc}
     */
    public function hasSecondaryPartitionTable(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function getSecondaryPartitionTableSize(): int
    {
        return 0;
    }

    /**
     * {@inheritdoc}
     */
    public function getSecondaryPartitionTableOffset(): int
    {
        return 0;
    }

    /**
     * {@inheritdoc}
     */
    public function getTotalDiskSize(): int
    {
        $lastPartitionNumber = $this->getPartitionCount();
        return $this->getPartitionOffset($lastPartitionNumber) + $this->getPartitionSize($lastPartitionNumber);
    }
}
