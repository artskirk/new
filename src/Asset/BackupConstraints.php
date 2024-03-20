<?php

namespace Datto\Asset;

/**
 * Class to represent constraints on an asset backup
 */
class BackupConstraints
{
    // 10/13/2022 Current Cloud Continuity max is 1.5 TiB
    const DEFAULT_MAX_TOTAL_VOLUME_SIZE_IN_BYTES = 1649267441664;

    /** @var int */
    private $maxTotalVolumeSize;
    /** @var bool*/
    private $shouldBackupAllVolumes;

    /**
     * @param $maxTotalVolumeSize
     * @param  $shouldBackupAllVolumes
     */
    public function __construct(int $maxTotalVolumeSize = null, bool $shouldBackupAllVolumes = true)
    {
        $this->maxTotalVolumeSize = $maxTotalVolumeSize;
        $this->shouldBackupAllVolumes = $shouldBackupAllVolumes;
    }

    /**
     * @return int|null
     */
    public function getMaxTotalVolumeSize()
    {
        return $this->maxTotalVolumeSize;
    }

    /**
     * @param int $maxTotalVolumeSize
     */
    public function setMaxTotalVolumeSize(int $maxTotalVolumeSize): void
    {
        $this->maxTotalVolumeSize = $maxTotalVolumeSize;
    }

    public function shouldBackupAllVolumes(): bool
    {
        return $this->shouldBackupAllVolumes;
    }
}
