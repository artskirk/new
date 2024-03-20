<?php

namespace Datto\Core\Storage;

/**
 * Context necessary in order to import a storage pool
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class StoragePoolImportContext
{
    private string $name;
    private bool $force;
    private bool $byId;

    /** string[] List of paths to search for pool devices. */
    private array $devicePaths;

    public function __construct(string $name, bool $force, bool $byId, array $devicePaths = [])
    {
        $this->name = $name;
        $this->force = $force;
        $this->byId = $byId;
        $this->devicePaths = $devicePaths;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function force(): bool
    {
        return $this->force;
    }

    public function byId(): bool
    {
        return $this->byId;
    }

    public function getDevicePaths(): array
    {
        return $this->devicePaths;
    }
}
