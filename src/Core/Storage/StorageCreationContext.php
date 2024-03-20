<?php

namespace Datto\Core\Storage;

/**
 * Context necessary for the creation of a storage
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class StorageCreationContext
{
    public const MAX_SIZE_DEFAULT = -1;

    /** @var string Short name of the storage, e.g. 47efea2e75cf4d4d8ad514eebedf0864 */
    private string $name;

    /** @var string Full name of the parent storage, e.g. homePool/home/agents */
    private string $parentId;

    private string $type;
    private int $maxSizeInBytes;
    private bool $createParents;

    /** @var string[] List of key / value property pairs to set when the storage is created */
    private array $properties;

    public function __construct(
        string $name,
        string $parentId,
        string $type,
        int $maxSizeInBytes = self::MAX_SIZE_DEFAULT,
        bool $createParents = false,
        array $properties = []
    ) {
        $this->name = $name;
        $this->parentId = $parentId;
        $this->type = $type;
        $this->maxSizeInBytes = $maxSizeInBytes;
        $this->createParents = $createParents;
        $this->properties = $properties;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getParentId(): string
    {
        return $this->parentId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getMaxSizeInBytes(): int
    {
        return $this->maxSizeInBytes;
    }

    public function shouldCreateParents(): bool
    {
        return $this->createParents;
    }

    public function getProperties(): array
    {
        return $this->properties;
    }
}
