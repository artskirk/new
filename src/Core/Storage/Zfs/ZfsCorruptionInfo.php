<?php

namespace Datto\Core\Storage\Zfs;

use JsonSerializable;

/**
 * Data object class that contains information about zfs corruption
 */
class ZfsCorruptionInfo implements JsonSerializable
{
    const STATUS_CORRUPTION_REGEX = "/(?<zfspath>^[^@]*)@(?<snapshot>[1-9][0-9]{9}):(?<file>.*$)/";
    const ASSET_KEY_REGEX = "/(?<assetKey>[^\/]+$)/";

    private string $name;
    private string $id;
    private int $snapshot;
    private string $corruptedFile;
    private string $rawErrorOutput;

    public function __construct(
        string $name,
        string $id,
        int $snapshot,
        string $corruptedFile,
        string $rawErrorOutput
    ) {
        $this->name = $name;
        $this->id = $id;
        $this->snapshot = $snapshot;
        $this->corruptedFile = $corruptedFile;
        $this->rawErrorOutput = $rawErrorOutput;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSnapshot(): int
    {
        return $this->snapshot;
    }

    public function getCorruptedFile(): string
    {
        return $this->corruptedFile;
    }

    public function getRawErrorOutput(): string
    {
        return $this->rawErrorOutput;
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            'name' => $this->getName(),
            'id' => $this->getId(),
            'snapshot' => $this->getSnapshot(),
            'corruptedFile' => $this->getCorruptedFile(),
            'rawErrorOutput' => $this->getRawErrorOutput()
        ];
    }
}
