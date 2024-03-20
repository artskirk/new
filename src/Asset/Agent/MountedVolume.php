<?php

namespace Datto\Asset\Agent;

class MountedVolume
{
    private Volume $volume;
    private string $mouthPath; // eg. "/tmp/aaaa-1111-vhd/C"

    public function __construct(Volume $volume, string $mountPath)
    {
        $this->volume = $volume;
        $this->mouthPath = $mountPath;
    }

    /**
     * @return Volume
     */
    public function getVolume(): Volume
    {
        return $this->volume;
    }

    /**
     * @return string
     */
    public function getMountPath(): string
    {
        return $this->mouthPath;
    }
}
