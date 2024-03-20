<?php

namespace Datto\Asset;

/**
 * Class for storing BackupConstraint results
 */
class BackupConstraintsResult
{
    const MAX_TOTAL_VOLUME_CONSTRAINT_SUCCESS = 'Success: Total volume size %d GiB';
    const MAX_TOTAL_VOLUME_CONSTRAINT_FAILURE = 'Failure: Total volume size %d GiB';

    /** @var bool */
    private $maxTotalVolumeResult;

    /** @var string */
    private $maxTotalVolumeMessage;

    public function getMaxTotalVolumeResult(): bool
    {
        return $this->maxTotalVolumeResult;
    }

    public function setMaxTotalVolumeResult(bool $maxTotalVolumeResult): void
    {
        $this->maxTotalVolumeResult = $maxTotalVolumeResult;
    }

    public function getMaxTotalVolumeMessage(): string
    {
        return $this->maxTotalVolumeMessage;
    }

    public function setMaxTotalVolumeMessage(string $maxTotalVolumeMessage): void
    {
        $this->maxTotalVolumeMessage = $maxTotalVolumeMessage;
    }
}
