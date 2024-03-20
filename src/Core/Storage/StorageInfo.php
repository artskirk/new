<?php

namespace Datto\Core\Storage;

/**
 * Data object class that contains information about a storage
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class StorageInfo
{
    public const STORAGE_SPACE_UNKNOWN = -1;
    public const STORAGE_LOCATION_UNKNOWN = '';
    public const STORAGE_PARENT_NONE = '';
    public const STORAGE_COMPRESS_RATIO_UNKNOWN = 0.0;
    public const STORAGE_PROPERTY_NOT_APPLICABLE = '-';

    /** @var string Short name of the storage, e.g. 47efea2e75cf4d4d8ad514eebedf0864 */
    private string $name;

    /** @var string Full name of the storage, e.g. homePool/home/agents/47efea2e75cf4d4d8ad514eebedf0864 */
    private string $id;

    private string $type;

    /** @var string File path for file storage. Block device for block storage. Object location for object storage */
    private string $location;

    private int $quotaSizeInBytes;
    private int $allocatedSizeInBytes;
    private int $allocatedSizeByStorageInBytes;
    private int $allocatedSizeBySnapshotsInBytes;
    private int $freeSpaceInBytes;
    private float $compressRatio;

    private bool $isMounted;

    private int $creationTime;
    private string $parent;

    public function __construct(
        string $name,
        string $id,
        string $type,
        string $location,
        int $quotaSizeInBytes,
        int $allocatedSizeInBytes,
        int $allocatedSizeByStorageInBytes,
        int $allocatedSizeBySnapshotsInBytes,
        int $freeSpaceInBytes,
        float $compressRatio,
        bool $isMounted,
        int $creationTime,
        string $parent
    ) {
        $this->name = $name;
        $this->id = $id;
        $this->type = $type;
        $this->location = $location;
        $this->quotaSizeInBytes = $quotaSizeInBytes;
        $this->allocatedSizeInBytes = $allocatedSizeInBytes;
        $this->allocatedSizeByStorageInBytes = $allocatedSizeByStorageInBytes;
        $this->allocatedSizeBySnapshotsInBytes = $allocatedSizeBySnapshotsInBytes;
        $this->freeSpaceInBytes = $freeSpaceInBytes;
        $this->compressRatio = $compressRatio;
        $this->isMounted = $isMounted;
        $this->creationTime = $creationTime;
        $this->parent = $parent;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getFilePath(): string
    {
        if ($this->type !== StorageType::STORAGE_TYPE_FILE) {
            throw new StorageException(
                'Cannot retrieve file path for a non-file type storage',
                $this->id,
                'retrieving file path',
                $this->type
            );
        }
        return $this->location;
    }

    public function getBlockDevice(): string
    {
        if ($this->type !== StorageType::STORAGE_TYPE_BLOCK) {
            throw new StorageException(
                'Cannot retrieve block device for a non-block type storage',
                $this->id,
                'retrieving block device',
                $this->type
            );
        }
        return $this->location;
    }

    public function getObjectLocation(): string
    {
        if ($this->type !== StorageType::STORAGE_TYPE_OBJECT) {
            throw new StorageException(
                'Cannot retrieve object location for a non-object type storage',
                $this->id,
                'retrieving object location',
                $this->type
            );
        }
        return $this->location;
    }

    public function getQuotaSizeInBytes(): int
    {
        return $this->quotaSizeInBytes;
    }

    public function getAllocatedSizeInBytes(): int
    {
        return $this->allocatedSizeInBytes;
    }

    public function getAllocatedSizeByStorageInBytes(): int
    {
        return $this->allocatedSizeByStorageInBytes;
    }

    public function getAllocatedSizeBySnapshotsInBytes(): int
    {
        return $this->allocatedSizeBySnapshotsInBytes;
    }

    public function getFreeSpaceInBytes(): int
    {
        return $this->freeSpaceInBytes;
    }

    public function getCompressRatio(): float
    {
        return $this->compressRatio;
    }

    public function isMounted(): bool
    {
        return $this->isMounted;
    }

    public function getCreationTime(): int
    {
        return $this->creationTime;
    }

    /**
     * Retrieve the storage parent. Some storage backends have a parent-child hierarchical system.
     * For example, with zfs, this represents the name of the originating storage for cloned datasets.
     *
     * @return string Name of the parent storage
     */
    public function getParent(): string
    {
        return $this->parent;
    }

    public function hasValidMountpoint(): bool
    {
        return $this->isMounted() && $this->getFilePath() !== self::STORAGE_LOCATION_UNKNOWN;
    }
}
