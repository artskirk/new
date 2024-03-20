<?php

namespace Datto\Core\Storage;

/**
 * Context necessary for the creation of a snapshot
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class SnapshotCreationContext
{
    private const DEFAULT_TIMOUT_SECONDS = 3600;

    private string $tag;
    private int $timeout;

    public function __construct(string $tag, int $timeout = self::DEFAULT_TIMOUT_SECONDS)
    {
        $this->tag = $tag;
        $this->timeout = $timeout;
    }

    public function getTag(): string
    {
        return $this->tag;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }
}
