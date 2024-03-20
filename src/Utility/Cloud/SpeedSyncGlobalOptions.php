<?php

namespace Datto\Utility\Cloud;

/**
 * Represents a call to "speedsync options" to get global options.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class SpeedSyncGlobalOptions
{
    private int $maxZfs;
    private int $maxTransfer;
    private int $pauseZfs;
    private int $pauseTransfer;
    private string $compression;

    public function __construct(
        int $maxZfs,
        int $maxTransfer,
        int $pauseZfs,
        int $pauseTransfer,
        string $compression
    ) {
        $this->maxZfs = $maxZfs;
        $this->maxTransfer = $maxTransfer;
        $this->pauseZfs = $pauseZfs;
        $this->pauseTransfer = $pauseTransfer;
        $this->compression = $compression;
    }

    public function getMaxZfs(): int
    {
        return $this->maxZfs;
    }

    public function getMaxTransfer(): int
    {
        return $this->maxTransfer;
    }

    public function isZfsPaused(): bool
    {
        return $this->pauseZfs > 0;
    }

    public function isTransferPaused(): bool
    {
        return $this->pauseTransfer > 0;
    }

    public function getCompression(): string
    {
        return $this->compression;
    }
}
