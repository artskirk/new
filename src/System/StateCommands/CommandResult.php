<?php

namespace Datto\System\StateCommands;

/**
 * A data object to return the result of running a shell command.
 *
 * @author Dawid Zamirski <dzamirski@datto.com>
 */
class CommandResult
{
    private bool $isSuccessful;
    private string $commandLine;
    private string $commandOutput;

    public function __construct(string $commandLine, string $commandOutput, bool $isSuccessful)
    {
        $this->commandLine = $commandLine;
        $this->commandOutput = $commandOutput;
        $this->isSuccessful = $isSuccessful;
    }

    /**
     * Get the shell command exactly as it was ran.
     *
     * @return string
     */
    public function getCommandLine(): string
    {
        return $this->commandLine;
    }

    /**
     * Get the shell command output (stdout or stderr)
     *
     * @return string
     */
    public function getCommandOutput(): string
    {
        return $this->commandOutput;
    }

    /**
     * Whether the command exited with error code.
     *
     * @return bool
     */
    public function isSuccessful(): bool
    {
        return $this->isSuccessful;
    }
}
