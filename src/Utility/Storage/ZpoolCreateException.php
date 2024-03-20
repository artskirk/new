<?php

namespace Datto\Utility\Storage;

use Exception;

/**
 * Exception class thrown for zpool creation errors.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class ZpoolCreateException extends Exception
{
    /** @var string */
    private $output;

    /** @var string */
    private $command;

    /**
     * @param string $output
     * @param string $command
     */
    public function __construct(string $output, string $command)
    {
        $this->output = $output;
        $this->command = $command;

        parent::__construct('Error creating storage pool');
    }

    /**
     * @return string
     */
    public function getOutput(): string
    {
        return $this->output;
    }

    /**
     * @return string
     */
    public function getCommand(): string
    {
        return $this->command;
    }
}
