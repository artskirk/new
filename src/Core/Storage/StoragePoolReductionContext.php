<?php

namespace Datto\Core\Storage;

/**
 * Context necessary for the reduction of a storage pool
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class StoragePoolReductionContext
{
    private string $name;
    private array $drives;

    public function __construct(
        string $name,
        array $drives
    ) {
        $this->name = $name;
        $this->drives = $drives;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDrives(): array
    {
        return $this->drives;
    }
}
