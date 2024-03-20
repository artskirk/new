<?php

namespace Datto\Filesystem;

/**
 * Simple class to represent a GPT partitioned disk
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class GptDisk extends AbstractDisk
{
    const PROTECTIVE_MBR_SIZE_IN_BYTES = self::LBA_SIZE_IN_BYTES;
    const PARTITION_TABLE_HEADER_SIZE_IN_BYTES = self::LBA_SIZE_IN_BYTES;
    const PARTITION_TABLE_ENTRIES_SIZE_IN_BYTES = 32 * self::LBA_SIZE_IN_BYTES;
    const PARTITION_ALIGNMENT_IN_LBA_UNITS = 2048;  // 1 MiB boundaries

    /**
     * {@inheritdoc}
     */
    public function getPartitionTableSize(): int
    {
        return
            self::PROTECTIVE_MBR_SIZE_IN_BYTES +
            self::PARTITION_TABLE_HEADER_SIZE_IN_BYTES +
            self::PARTITION_TABLE_ENTRIES_SIZE_IN_BYTES;
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
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getSecondaryPartitionTableOffset(): int
    {
        return $this->getPartitionOffset($this->getPartitionCount() + 1);
    }

    /**
     * {@inheritdoc}
     */
    public function getSecondaryPartitionTableSize(): int
    {
        return
            self::PARTITION_TABLE_HEADER_SIZE_IN_BYTES +
            self::PARTITION_TABLE_ENTRIES_SIZE_IN_BYTES;
    }

    /**
     * {@inheritdoc}
     */
    public function getTotalDiskSize(): int
    {
        return $this->getSecondaryPartitionTableOffset() +
               $this->getSecondaryPartitionTableSize();
    }
}
