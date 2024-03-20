<?php

namespace Datto\Core\Storage;

use JsonSerializable;

/**
 * Context necessary for the creation of a clone
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class CloneCreationContext implements JsonSerializable
{
    /** @var string Short name of the storage, e.g. 47efea2e75cf4d4d8ad514eebedf0864-clone */
    private string $name;

    /** @var string Full name of the parent storage, e.g. homePool/home/agents */
    private string $parentId;

    private string $mountPoint;

    private ?bool $sync = null;

    public function __construct(
        string $name,
        string $parentId,
        string $mountPoint,
        ?bool $sync = null
    ) {
        $this->name = $name;
        $this->parentId = $parentId;
        $this->mountPoint = $mountPoint;
        $this->sync = $sync;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getParentId(): string
    {
        return $this->parentId;
    }

    public function getMountPoint(): string
    {
        return $this->mountPoint;
    }

    public function getSync(): ?bool
    {
        return $this->sync;
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            'name' => $this->getName(),
            'parentId' => $this->getParentId(),
            'mountPoint' => $this->getMountPoint(),
            'sync' => $this->getSync(),
        ];
    }
}
