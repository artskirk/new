<?php

namespace Datto\Asset\Agent\Log;

/**
 * Agent Log information
 *
 * @author John Roland <jroland@datto.com>
 */
class AgentLog
{
    /** @var int */
    private $timestamp;

    /** @var string */
    private $code;

    /** @var string */
    private $message;

    /** @var int */
    private $severity;

    /**
     * @param int $timestamp
     * @param string $code
     * @param string $message
     * @param int $severity
     */
    public function __construct($timestamp, $code, $message, $severity)
    {
        $this->timestamp = $timestamp;
        $this->code = $code;
        $this->message = $message;
        $this->severity = $severity;
    }

    /**
     * @return int
     */
    public function getTimestamp()
    {
        return $this->timestamp;
    }

    /**
     * @return string
     */
    public function getCode()
    {
        return $this->code;
    }

    /**
     * @return string
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * @return int
     */
    public function getSeverity()
    {
        return $this->severity;
    }
}
