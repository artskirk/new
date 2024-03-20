<?php

namespace Datto\Asset;

/**
 * Class VerificationScriptOutput
 * Represents a single script output from a verification script injection.
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class VerificationScriptOutput
{
    const SCRIPT_PENDING = 0;
    const SCRIPT_RUNNING = 1;
    const SCRIPT_COMPLETE = 2;
    const STATE_INVALID = 3;

    /** @var string */
    private $scriptName;

    /** @var int */
    private $state;

    /** @var string */
    private $output;

    /** @var int */
    private $exitCode;

    /**
     * VerificationScriptOutput constructor.
     * @param string $scriptName
     * @param int $state
     * @param string $output
     * @param int $exitCode
     */
    public function __construct($scriptName, $state, $output, $exitCode)
    {
        $this->scriptName = $scriptName;
        $this->exitCode = $exitCode;
        $this->validateState($state);
        $this->parseOutput($output);
    }

    /**
     * @return string
     */
    public function getScriptName()
    {
        return $this->scriptName;
    }

    /**
     * @return string
     */
    public function getScriptDisplayName(): string
    {
        $scriptComponents = explode('_', $this->scriptName, 3);

        if (isset($scriptComponents[2])) {
            return $scriptComponents[2];
        } else {
            return $this->scriptName;
        }
    }

    /**
     * @return int
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     * @return string
     */
    public function getOutput()
    {
        return $this->output;
    }

    /**
     * @return int
     */
    public function getExitCode()
    {
        return $this->exitCode;
    }

    /**
     * @return bool
     */
    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }

    /**
     * If the state we get back from lakitu is not 0, 1 or 2, something really bad happened.
     *
     * @param int $state
     */
    private function validateState($state): void
    {
        switch ($state) {
            case static::SCRIPT_PENDING:
            case static::SCRIPT_RUNNING:
            case static::SCRIPT_COMPLETE:
                $this->state = $state;
                break;
            default:
                $this->state = static::STATE_INVALID;
        }
    }

    /**
     * This strips \r from the output as Windows ends every line with \r\n.
     *
     * @param string $output
     */
    private function parseOutput($output): void
    {
        $this->output = $output ? preg_replace('/\\r/', '', $output) : '';
    }
}
