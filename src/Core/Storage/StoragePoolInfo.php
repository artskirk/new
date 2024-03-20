<?php

namespace Datto\Core\Storage;

/**
 * Data object class that contains information about a storage pool
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class StoragePoolInfo
{
    public const POOL_SPACE_UNKNOWN = -1;
    public const ALLOCATED_PERCENTAGE_UNKNOWN = -1;
    public const DEDUP_RATIO_UNKNOWN = -1.0;
    public const FRAGMENTATION_UNKNOWN = -1;
    public const NUMBER_DISKS_UNKNOWN = -1;

    private string $name;
    private string $id;

    /** @var string[] Supported types of storage */
    private array $supportedStorageTypes;

    private int $totalSizeInBytes;
    private int $allocatedSizeInBytes;
    private int $freeSpaceInBytes;
    private int $errorCount;
    private int $allocatedPercent;
    private float $dedupRatio;
    private int $fragmentation;
    private int $numberOfDisksInPool;

    public function __construct(
        string $name,
        string $id,
        array $supportedStorageTypes,
        int $totalSizeInBytes,
        int $allocatedSizeInBytes,
        int $freeSpaceInBytes,
        int $errorCount,
        int $allocatedPercent,
        float $dedupRatio,
        int $fragmentation,
        int $numberOfDisksInPool = self::NUMBER_DISKS_UNKNOWN
    ) {
        $this->name = $name;
        $this->id = $id;
        $this->supportedStorageTypes = $supportedStorageTypes;
        $this->totalSizeInBytes = $totalSizeInBytes;
        $this->allocatedSizeInBytes = $allocatedSizeInBytes;
        $this->freeSpaceInBytes = $freeSpaceInBytes;
        $this->errorCount = $errorCount;
        $this->allocatedPercent = $allocatedPercent;
        $this->dedupRatio = $dedupRatio;
        $this->fragmentation = $fragmentation;
        $this->numberOfDisksInPool = $numberOfDisksInPool;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSupportedStorageTypes(): array
    {
        return $this->supportedStorageTypes;
    }

    public function getTotalSizeInBytes(): int
    {
        return $this->totalSizeInBytes;
    }

    public function getAllocatedSizeInBytes(): int
    {
        return $this->allocatedSizeInBytes;
    }

    public function getFreeSpaceInBytes(): int
    {
        return $this->freeSpaceInBytes;
    }

    public function getErrorCount(): int
    {
        return $this->errorCount;
    }

    public function getAllocatedPercent(): int
    {
        return $this->allocatedPercent;
    }

    public function getDedupRatio(): float
    {
        return $this->dedupRatio;
    }

    public function getFragmentation(): int
    {
        return $this->fragmentation;
    }

    public function getNumberOfDisksInPool(): int
    {
        return $this->numberOfDisksInPool;
    }
}
