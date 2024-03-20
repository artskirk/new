<?php

namespace Datto\Alert;

/**
 * Holds information on a specific alert
 *
 * @author Rixhers Ajazi <rajazi@datto.com>
 */
class Alert
{
    /** @var string */
    private $code;

    /** @var string */
    private $message;

    /** @var int */
    private $firstSeen;

    /** @var int */
    private $lastSeen;

    /** @var int */
    private $numberSeen;

    /** @var string */
    private $user;

    /**
     * @param string $code
     * @param string $message
     * @param int $firstSeen
     * @param int $lastSeen
     * @param int $numberSeen
     * @param string $user
     */
    public function __construct($code, $message, $firstSeen, $lastSeen, $numberSeen, $user)
    {
        $this->code = $code;
        $this->message = $message;
        $this->firstSeen = $firstSeen;
        $this->lastSeen = $lastSeen;
        $this->numberSeen = $numberSeen;
        $this->user = $user;
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
    public function getFirstSeen()
    {
        return $this->firstSeen;
    }

    /**
     * @return int
     */
    public function getLastSeen()
    {
        return $this->lastSeen;
    }

    /**
     * @param int $firstSeen
     */
    public function setLastSeen($firstSeen): void
    {
        $this->lastSeen = $firstSeen;
    }

    /**
     * @return int
     */
    public function getNumberSeen()
    {
        return $this->numberSeen;
    }

    /**
     * @param $seen
     */
    public function setNumberSeen($seen): void
    {
        $this->numberSeen = $seen;
    }

    /**
     * @return string
     */
    public function getUser()
    {
        return $this->user;
    }
}
