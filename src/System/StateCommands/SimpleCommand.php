<?php

namespace Datto\System\StateCommands;

use Datto\Common\Resource\ProcessFactory;

/**
 * Runs any command as specified by $command array arugument.
 *
 * Allows to run simple commands that do not require any special handling,
 * e.g change arguments based on device type etc.
 *
 * @author Dawid Zamirski <dzamirski@datto.com>
 */
class SimpleCommand extends AbstractStateCommand
{
    private string $identifier;
    private array $command;

    /**
     * @param string $identifier unique identifier for a command
     * @param array $command first element is the command remaining arguments
     * @param ProcessFactory $processFactory
     */
    public function __construct(
        $identifier,
        array $command,
        ProcessFactory $processFactory
    ) {
        $this->command = $command;
        $this->identifier = $identifier;

        parent::__construct($processFactory);
    }

    protected function getCommand(): array
    {
        return $this->command;
    }

    public function getCommandIdentifier(): string
    {
        return $this->identifier;
    }
}
