<?php

namespace Datto\Utility\Process;

/**
 * An entry that represents a row returns by the binary "ps".
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class PsEntry
{
    /** @var int */
    private $pid;

    /** @var int */
    private $runtimeInSeconds;

    /**
     * @param int $pid
     * @param int $runtimeInSeconds
     */
    public function __construct(int $pid, int $runtimeInSeconds)
    {
        $this->pid = $pid;
        $this->runtimeInSeconds = $runtimeInSeconds;
    }

    /**
     * @return int
     */
    public function getPid(): int
    {
        return $this->pid;
    }

    /**
     * @return int
     */
    public function getRuntimeInSeconds(): int
    {
        return $this->runtimeInSeconds;
    }
}
