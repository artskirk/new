<?php

namespace Datto\Process;

use Datto\Common\Resource\ProcessFactory;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * A class to use for running expect scripts.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class Expect
{
    const PROCESS_TIMEOUT = 10;

    private ProcessFactory $processFactory;

    public function __construct(
        ProcessFactory $processFactory = null
    ) {
        $this->processFactory = $processFactory ?: new ProcessFactory();
    }

    /**
     * Execute the Expect script with the provided parameters. If the script takes longer than Expect::PROCESS_TIMEOUT
     * seconds to run, the process will be terminated and the function will return false.
     *
     * @param string $expectFile Full path of expect script file
     * @param array ...$parameters A variable number of parameters that are then passed to the expect script
     * @return bool true if the process ran successfully, false otherwise
     */
    public function run($expectFile, ...$parameters)
    {
        $command = ['expect', $expectFile];

        foreach ($parameters as $parameter) {
            $command[] = $parameter;
        }

        $process = $this->processFactory
            ->get($command)
            ->setTimeout(static::PROCESS_TIMEOUT);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            return false;
        }
        return $process->isSuccessful();
    }
}
