<?php

namespace Datto\System\StateCommands;

use Datto\Common\Resource\ProcessFactory;
use Exception;

/**
 * An abstract class used to build specific state checker commands.
 *
 * @author Dawid Zamirski <dzamirsk@datto.com>
 */
abstract class AbstractStateCommand
{
    const DEFAULT_COMMAND_TIMEOUT = 20;

    protected ProcessFactory $processFactory;

    public function __construct(
        ProcessFactory $processFactory
    ) {
        $this->processFactory = $processFactory;
    }

    /**
     * Returns an array with the desired command and arguments to run.
     *
     * @return string[]
     */
    abstract protected function getCommand(): array;

    /**
     * Get a unique identifier for the command.
     *
     * For example, to be used as a file name to dump the output to.
     *
     * @return string
     */
    abstract public function getCommandIdentifier(): string;

    /**
     * Executes a command and returns its output.
     *
     * If the command fails, a message with command line and stderr is returned.
     *
     * @return CommandResult
     */
    public function executeCommand(): CommandResult
    {
        $commandLine = implode(' ', $this->getCommand());

        // enable stderr > stdout so the output is exactly as it was ran in terminal
        $process = $this->processFactory->getFromShellCommandLine(
            $commandLine . ' 2>&1',
            null,
            null,
            null,
            self::DEFAULT_COMMAND_TIMEOUT
        );

        try {
            $process->run();
            $output = $process->getOutput();
        } catch (Exception $ex) { // run may throw if timed out or killed
            $output = $ex->getMessage();
        }

        return new CommandResult($commandLine, $output, $process->isSuccessful());
    }
}
