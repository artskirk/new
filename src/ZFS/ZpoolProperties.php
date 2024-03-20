<?php

namespace Datto\ZFS;

/**
 * ZFS pool properties information
 *
 * @author Sri Ramanujam <sramanujam@datto.com>
 */
class ZpoolProperties
{
    private float $size;
    private float $allocated;
    private float $free;
    private float $capacity;
    private float $dedupratio;
    private float $fragmentation;
    private int $numDisks;

    public function __construct(
        float $size,
        float $allocated,
        float $free,
        float $capacity,
        float $dedupratio,
        float $fragmentation,
        int $numDisks
    ) {
        $this->size = $size;
        $this->allocated = $allocated;
        $this->free = $free;
        $this->capacity = $capacity;
        $this->dedupratio = $dedupratio;
        $this->fragmentation = $fragmentation;
        $this->numDisks = $numDisks;
    }

    /**
     * Get size of ZFS pool
     *
     * @return float
     */
    public function getSize(): float
    {
        return $this->size;
    }

    /**
     * Get number of allocated bytes of ZFS pool
     *
     * @return float
     */
    public function getAllocated(): float
    {
        return $this->allocated;
    }

    /**
     * Get number of free bytes of ZFS pool
     *
     * @return float
     */
    public function getFree(): float
    {
        return $this->free;
    }

    /**
     * Get capacity of ZFS pool
     *
     * @return float
     */
    public function getCapacity(): float
    {
        return $this->capacity;
    }

    /**
     * Get dedup ratio of ZFS pool
     *
     * @return float
     */
    public function getDedupRatio(): float
    {
        return $this->dedupratio;
    }

    /**
     * Get fragmentation of ZFS pool
     *
     * @return float
     */
    public function getFragmentation(): float
    {
        return $this->fragmentation;
    }

    /**
     * Get the number of data disks in the ZFS pool
     *
     * @return int
     */
    public function getNumDisks(): int
    {
        return $this->numDisks;
    }
}
