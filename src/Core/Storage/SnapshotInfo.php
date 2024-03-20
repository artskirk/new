<?php

namespace Datto\Core\Storage;

/**
 * Data object class that contains information about a snapshot
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class SnapshotInfo
{
    public const SNAPSHOT_SPACE_UNKNOWN = -1;
    public const SNAPSHOT_CREATION_UNKNOWN = 0;

    private string $id;
    private string $parentStorageId;
    private string $tag;
    private int $usedSizeInBytes;
    private int $writtenSizeInBytes;
    private int $creationEpochTime;

    /** string[] List of clone Ids */
    private array $cloneIds;

    public function __construct(
        string $id,
        string $parentStorageId,
        string $tag,
        int $usedSizeInBytes,
        int $writtenSizeInBytes,
        int $creationEpochTime,
        array $cloneIds
    ) {
        $this->id = $id;
        $this->parentStorageId = $parentStorageId;
        $this->tag = $tag;
        $this->usedSizeInBytes = $usedSizeInBytes;
        $this->writtenSizeInBytes = $writtenSizeInBytes;
        $this->creationEpochTime = $creationEpochTime;
        $this->cloneIds = $cloneIds;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getParentStorageId(): string
    {
        return $this->parentStorageId;
    }

    public function getTag(): string
    {
        return $this->tag;
    }

    public function getUsedSizeInBytes(): int
    {
        return $this->usedSizeInBytes;
    }

    public function getWrittenSizeInBytes(): int
    {
        return $this->writtenSizeInBytes;
    }

    public function getCreationEpochTime(): int
    {
        return $this->creationEpochTime;
    }

    public function getCloneIds(): array
    {
        return $this->cloneIds;
    }
}
