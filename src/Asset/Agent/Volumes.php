<?php

namespace Datto\Asset\Agent;

use ArrayObject;

class Volumes extends ArrayObject
{
    /**
     * @param Volume[] $volumes
     */
    public function __construct(array $volumes = [])
    {
        parent::__construct($volumes, ArrayObject::STD_PROP_LIST);
    }

    public function addVolume(Volume $volume): void
    {
        $this[$volume->getGuid()] = $volume;
    }

    public function getVolumeByGuid(string $guid): ?Volume
    {
        if (isset($this[$guid])) {
            return $this[$guid];
        } else {
            return null;
        }
    }

    public function toArray(): array
    {
        $serialized = [];
        foreach ($this as $volume) {
            // agentInfo volumes array keyed by mountpoint
            $serialized[$volume->getMountpoint()] = $volume->toArray();
        }
        return $serialized;
    }
}
