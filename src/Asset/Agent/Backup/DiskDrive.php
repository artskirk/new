<?php

namespace Datto\Asset\Agent\Backup;

/**
 * Represents a disk device of an agent/system.
 *
 * Currently, this is used to support full-disk backups for "generic agentless"
 * but the intent is to lay foundation for support of such backups for all agent
 * types. Ideally we should be able to remove 'Volumes' and 'volumes' keys from
 * .agentInfo as well as .include key files and end up with a structure along
 * the lines of:
 *
 * <code>
 * $disks = $agent->getDiskDrives();
 * foreach ($disks as $disk) {
 *     $isGpt = $disk->isGpt();
 *     $isIncluded = $disk->isIncluded(); // or "protected" - no more need for the .include key file
 *     $volumes = $disk->getVolumes();
 *     foreach ($volumes as $volume) {
 *         $filesystem = $volume->getFilesystem();
 *         $mountpoint = $volume->getMountpoint();
 *         $isIncluded = $volume->isIncluded();
 *         ...
 *     }
 *  }
 * </code>
 *
 * @author Peter Geer <pgeer@datto.com>
 * @author Dawid Zamirski <dzamirski@datto.com>
 */
class DiskDrive
{
    /** @var string */
    private $uuid;

    /** @var string */
    private $path;

    /** @var int */
    private $capacityInBytes;

    /** @var bool */
    private $hasBootablePartition;

    /** @var bool */
    private $isGpt;

    /**
     * @param string $uuid
     * @param string $path
     * @param int $capacityInBytes
     * @param bool $hasBootablePartition
     * @param bool $isGpt
     */
    public function __construct(
        string $uuid,
        string $path,
        int $capacityInBytes,
        bool $hasBootablePartition,
        bool $isGpt
    ) {
        $this->uuid = $uuid;
        $this->path = $path;
        $this->capacityInBytes = $capacityInBytes;
        $this->hasBootablePartition = $hasBootablePartition;
        $this->isGpt = $isGpt;
    }

    /**
     * @return string
     */
    public function getUuid(): string
    {
        return $this->uuid;
    }

    /**
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @return int
     */
    public function getCapacityInBytes(): int
    {
        return $this->capacityInBytes;
    }

    /**
     * @return bool
     */
    public function hasBootablePartition(): bool
    {
        return $this->hasBootablePartition;
    }

    /**
     * @return bool
     */
    public function isGpt(): bool
    {
        return $this->isGpt;
    }
}
