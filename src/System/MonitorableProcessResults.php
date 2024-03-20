<?php

namespace Datto\System;

/**
 * Class MonitorableProcessResults Represents the results of a MonitorableProcess
 *
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
abstract class MonitorableProcessResults
{
    const EXIT_CODE_SUCCESS = 0;

    /** @var int */
    protected $exitCode;

    /** @var array */
    protected $errorOutput;

    /**
     * @param int $exitCode The exit code of the process
     * @param array $errorOutput The $error output by the process
     */
    public function __construct(
        $exitCode,
        $errorOutput = []
    ) {
        $this->exitCode = $exitCode;
        $this->errorOutput = $errorOutput;
    }

    /**
     * @return int The exit code of the process
     */
    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    /**
     * @return array The error output of the process
     */
    public function getErrorOutput(): array
    {
        return is_array($this->errorOutput) ? $this->errorOutput : [];
    }

    abstract public function getExitCodeText();
}
