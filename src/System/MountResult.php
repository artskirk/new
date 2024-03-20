<?php

namespace Datto\System;

use Symfony\Component\Process\Process;

/**
 * Wraps output of a `mount` call
 *
 * @author Michael Meyer (mmeyer@datto.com)
 */
class MountResult
{
    /** @var Process */
    protected $process;

    /**
     * MountResult constructor
     * The only parameter is the Process object
     * from an *already executed* call to `mount`.
     *
     * @param Process $process
     */
    public function __construct(Process $process)
    {
        $this->process = $process;
    }

    /**
     * Check if the mount was successful
     *
     * @return bool
     */
    public function mountSuccessful()
    {
        return $this->process->isSuccessful();
    }

    /**
     * Check if the mount was unsuccessful.
     *
     * I know this seems redundant, but it makes
     * the code calling it look much nicer.
     *
     * @return bool
     */
    public function mountFailed()
    {
        return !$this->mountSuccessful();
    }

    /**
     * Returns the output of the mount call
     *
     * Note that stderr will be appended to stdout.
     * This means the returned output might not exactly
     * match what you would see if you called `mount` by hand.
     *
     * @return string
     */
    public function getMountOutput()
    {
        return trim($this->process->getOutput() . PHP_EOL . $this->process->getErrorOutput());
    }

    /**
     * Returns the exit code of the mount process
     *
     * @return null|int The exit status code, null if the Process is not terminated
     */
    public function getExitCode()
    {
        return $this->process->getExitCode();
    }

    /**
     * Returns the command that was run for mount
     *
     * @return string
     */
    public function getExecutedCommand()
    {
        return $this->process->getCommandLine();
    }
}
