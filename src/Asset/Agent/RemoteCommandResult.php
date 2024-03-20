<?php

namespace Datto\Asset\Agent;

/**
 * A data object to return the result of running a remote shell command.
 *
 * @author Mark Blakley <mblakley@datto.com>
 */
class RemoteCommandResult
{
    /** @var int */
    private $exitCode;

    /** @var mixed */
    private $response;

    /** @var string */
    private $output;

    /** @var string */
    private $errorOutput;

    /**
     * @param $response
     * @param string $output
     * @param string $errorOutput
     * @param int $exitCode
     */
    public function __construct($response, string $output, string $errorOutput = '', int $exitCode = 0)
    {
        $this->response = $response;
        $this->output = $output;
        $this->errorOutput = $errorOutput;
        $this->exitCode = $exitCode;
    }

    /**
     * Get the unmodified response object
     *
     * @return mixed
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * Get the output string that was a result of running a command
     *
     * @return string
     */
    public function getOutput(): string
    {
        return $this->output;
    }

    /**
     * Get the output string that was a result of running a command
     *
     * @return string
     */
    public function getErrorOutput(): string
    {
        return $this->errorOutput;
    }

    /**
     * Get the exit code that was a result of running a command
     *
     * @return int
     */
    public function getExitCode(): int
    {
        return $this->exitCode;
    }
}
