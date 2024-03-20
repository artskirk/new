<?php

namespace Datto\Utility\Azure;

class InstanceMetadataStorageProfile
{
    private int $tempDiskSizeInBytes;

    /**
     * @param int $tempDiskSizeInBytes
     */
    public function __construct(int $tempDiskSizeInBytes)
    {
        $this->tempDiskSizeInBytes = $tempDiskSizeInBytes;
    }

    /**
     * @return int
     */
    public function getTempDiskSizeInBytes(): int
    {
        return $this->tempDiskSizeInBytes;
    }
}
