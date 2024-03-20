<?php

namespace Datto\Core\Storage;

use JsonSerializable;

/**
 * Context necessary for the creation of a storage pool
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class StoragePoolCreationContext implements JsonSerializable
{
    public const DEFAULT_MOUNTPOINT = '';

    private string $name;

    /** @var string[] Drives included in the storage pool */
    private array $drives;

    private string $mountpoint;

    private array $fileSystemProperties;

    public function __construct(
        string $name,
        array $drives,
        string $mountpoint = self::DEFAULT_MOUNTPOINT,
        array $fileSystemProperties = []
    ) {
        $this->name = $name;
        $this->drives = $drives;
        $this->mountpoint = $mountpoint;
        $this->fileSystemProperties = $fileSystemProperties;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDrives(): array
    {
        return $this->drives;
    }

    public function getMountpoint(): string
    {
        return $this->mountpoint;
    }

    public function getFileSystemProperties(): array
    {
        return $this->fileSystemProperties;
    }

    /**
     * @param array $fileSystemProperties
     */
    public function setFileSystemProperties(array $fileSystemProperties): void
    {
        $this->fileSystemProperties = $fileSystemProperties;
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            'name' => $this->getName(),
            'drives' => $this->getDrives(),
            'mountpoint' => $this->getMountpoint(),
            'properties' => $this->getFileSystemProperties()
        ];
    }
}
