<?php

namespace Datto\Asset;

use Datto\Log\LoggerFactory;
use Datto\Log\DeviceLoggerInterface;

/**
 * Class VerificationScriptsResults
 * Represents the complete results from a verification script injection for a recovery point.
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class VerificationScriptsResults
{
    const SCRIPTS_COMPLETE = true;
    const SCRIPTS_INCOMPLETE = false;

    /** @var bool */
    private $complete;

    /** @var VerificationScriptOutput[] */
    private $output;

    /** @var DeviceLoggerInterface */
    private $logger;

    /**
     * VerificationScriptsResults constructor.
     *
     * @param bool $complete
     * @param VerificationScriptOutput[]|null $output
     * @param DeviceLoggerInterface $logger
     */
    public function __construct($complete, $output = null, DeviceLoggerInterface $logger = null)
    {
        $this->complete = $complete;
        $this->output = $output ?: [];
        $this->logger = $logger;
    }

    /**
     * @return bool
     */
    public function getComplete()
    {
        return $this->complete;
    }

    /**
     * @return VerificationScriptOutput[]
     */
    public function getOutput()
    {
        return $this->output;
    }

    /**
     * Returns whether the scripts indicate success
     *
     * @return bool True if all scripts succeeded, otherwise false
     */
    public function isSuccess(): bool
    {
        return $this->getSuccessfulScriptCount() === count($this->output);
    }

    /**
     * Gets the total number of successful scripts
     *
     * @return int
     */
    public function getSuccessfulScriptCount()
    {
        $count = 0;
        foreach ($this->output as $scriptOutput) {
            if ($scriptOutput->getExitCode() == 0) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param VerificationScriptOutput $output
     */
    public function appendOutput(VerificationScriptOutput $output): void
    {
        $this->output[] = $output;
    }
}
