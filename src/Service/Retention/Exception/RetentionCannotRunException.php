<?php

namespace Datto\Service\Retention\Exception;

use Exception;
use Psr\Log\LogLevel;

/**
 * Thrown when device/agent has some setting/state that disallows running the
 * retention process. Intended to be caught and logged with appropiate severity
 * level.
 *
 * @author Dawid Zarmiski <dzamirski@datto.com>
 */
class RetentionCannotRunException extends Exception
{
    /** @var string */
    private $logLevel;

    /**
     * __construct
     *
     * @param string $message
     * @param string $logLevel
     */
    public function __construct(string $message, string $logLevel = LogLevel::ERROR)
    {
        $this->logLevel = $logLevel;
        parent::__construct($message);
    }

    /**
     * Get the log level to use to log this exception.
     *
     * @return string One of the Psr\Log\LogLevel constants
     */
    public function getLogLevel(): string
    {
        return $this->logLevel;
    }
}
