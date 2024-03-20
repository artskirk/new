<?php

namespace Datto\Verification;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class InProgressVerification
{
    /** @var string */
    private $assetKey;

    /** @var int */
    private $snapshot;

    /** @var int */
    private $startedAt;

    /** @var int */
    private $delay;

    /** @var int */
    private $pid;

    /** @var int */
    private $timeout;

    public function __construct(
        string $assetKey,
        int $snapshot,
        int $startedAt,
        int $delay,
        int $pid,
        int $timeout
    ) {
        $this->assetKey = $assetKey;
        $this->snapshot = $snapshot;
        $this->startedAt = $startedAt;
        $this->delay = $delay;
        $this->pid = $pid;
        $this->timeout = $timeout;
    }

    public function getAssetKey(): string
    {
        return $this->assetKey;
    }

    public function getSnapshot(): int
    {
        return $this->snapshot;
    }

    public function getStartedAt(): int
    {
        return $this->startedAt;
    }

    public function getDelay(): int
    {
        return $this->delay;
    }

    public function getPid(): int
    {
        return $this->pid;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }
}
