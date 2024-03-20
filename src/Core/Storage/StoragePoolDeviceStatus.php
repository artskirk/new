<?php

namespace Datto\Core\Storage;

/**
 * Data object class that contains information about a storage pool device status.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class StoragePoolDeviceStatus
{
    private string $name;
    private string $state;
    private string $read;
    private string $write;
    private string $checksum;
    private string $note;

    /** @var StoragePoolDeviceStatus[] */
    private array $subDevices;

    public function __construct(
        string $name,
        string $state,
        string $read,
        string $write,
        string $checksum,
        string $note,
        array $subDevices
    ) {
        $this->name = $name;
        $this->state = $state;
        $this->read = $read;
        $this->write = $write;
        $this->checksum = $checksum;
        $this->note = $note;
        $this->subDevices = $subDevices;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getRead(): string
    {
        return $this->read;
    }

    public function getWrite(): string
    {
        return $this->write;
    }

    public function getChecksum(): string
    {
        return $this->checksum;
    }

    public function getNote(): string
    {
        return $this->note;
    }

    /**
     * @return StoragePoolDeviceStatus[]
     */
    public function getSubDevices(): array
    {
        return $this->subDevices;
    }
}
