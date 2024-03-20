<?php

namespace Datto\Filesystem\Resize;

class CalcMinSizeProgress
{
    private bool $running;
    private string $stage;
    private int $percentComplete;
    private ?string $stdError;
    private int $currentVolumeSize;
    private int $minimumVolumeSize;
    private int $clusterSize;

    /**
     * ResizeProgress constructor.
     * @param bool $running
     * @param string $stage
     * @param int $percentComplete
     * @param ?string $stdError
     * @param int $currentVolumeSize
     * @param int $minimumVolumeSize
     * @param int $clusterSize
     */
    public function __construct(
        bool $running,
        string $stage,
        int $percentComplete,
        ?string $stdError,
        int $currentVolumeSize,
        int $minimumVolumeSize,
        int $clusterSize
    ) {
        $this->running = $running;
        $this->stage = $stage;
        $this->percentComplete = $percentComplete;
        $this->stdError = $stdError;
        $this->currentVolumeSize = $currentVolumeSize;
        $this->minimumVolumeSize = $minimumVolumeSize;
        $this->clusterSize = $clusterSize;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function getStage(): string
    {
        return $this->stage;
    }

    public function getPercentComplete(): int
    {
        return $this->percentComplete;
    }

    public function getStdError(): ?string
    {
        return $this->stdError;
    }

    public function getCurrentVolumeSize(): int
    {
        return $this->currentVolumeSize;
    }

    public function getMinimumVolumeSize(): int
    {
        return $this->minimumVolumeSize;
    }

    public function getClusterSize(): int
    {
        return $this->clusterSize;
    }

    public function toArray(): array
    {
        return array(
            'running' => $this->running,
            'stage' => $this->stage,
            'percentComplete' => $this->percentComplete,
            'stdErr' => $this->stdError,
            'currentVolumeSize' => $this->currentVolumeSize,
            'minimumVolumeSize' => $this->minimumVolumeSize,
            'clusterSize' => $this->clusterSize
        );
    }
}
