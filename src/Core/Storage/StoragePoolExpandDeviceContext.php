<?php

namespace Datto\Core\Storage;

class StoragePoolExpandDeviceContext implements \JsonSerializable
{
    private string $name;
    private string $poolDevice;

    public function __construct(
        string $name,
        string $poolDevice
    ) {
        $this->name = $name;
        $this->poolDevice = $poolDevice;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getPoolDevice(): string
    {
        return $this->poolDevice;
    }

    public function jsonSerialize()
    {
        return [
            'name' => $this->getName(),
            'device' => $this->getPoolDevice()
        ];
    }
}
