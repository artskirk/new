<?php

namespace Datto\Core\Storage;

/**
 * Context necessary for the replacement of a storage pool
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class StoragePoolReplacementContext
{
    private string $name;
    private string $sourceDriveId;
    private string $targetDriveId;

    public function __construct(
        string $name,
        string $sourceDriveId,
        string $targetDriveId
    ) {
        $this->name = $name;
        $this->sourceDriveId = $sourceDriveId;
        $this->targetDriveId = $targetDriveId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSourceDriveId(): string
    {
        return $this->sourceDriveId;
    }

    public function getTargetDriveId(): string
    {
        return $this->targetDriveId;
    }
}
