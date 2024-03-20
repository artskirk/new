<?php

namespace Datto\Asset;

/**
 * Class RansomwareResults represents an aggregate of results from the various ransomware checks performed.
 *
 * @author Christopher R. Wicks <cwicks@datto.com>
 * @author Chuck Roydhouse <cer@datto.com>
 */
class RansomwareResults
{
    /** @var string */
    private $agentKeyname;

    /** @var int */
    private $snapshotEpoch;

    /** @var boolean */
    private $hasRansomware;

    /** @var boolean */
    private $hasException;

    /** @var string|null */
    private $exceptionType;

    /** @var string|null */
    private $exceptionMessage;

    /** @var boolean|null */
    private $alertsSuspended;

    /** @var string|null */
    private $osVersionName;

    /**
     * RansomwareResults constructor.
     * @param string $agentKeyname The key name of the agent that was tested
     * @param int $snapshotEpoch The snapshot epoch for the dataset that was tested
     * @param boolean $hasRansomware Whether or not ransomware was detected
     * @param boolean $hasException whether or not an exception was thrown while running the tests
     * @param string|null $exceptionType the exception type that was thrown, if any
     * @param string|null $exceptionMessage the exception message, if any
     * @param boolean|null $hasAlertsSuspended
     * @param string|null $osVersionName The name of the Operating System
     */
    public function __construct(
        $agentKeyname,
        $snapshotEpoch,
        $hasRansomware,
        $hasException,
        $exceptionType = null,
        $exceptionMessage = null,
        $hasAlertsSuspended = null,
        $osVersionName = null
    ) {
        $this->agentKeyname = $agentKeyname;
        $this->snapshotEpoch = $snapshotEpoch;
        $this->hasRansomware = $hasRansomware;
        $this->hasException = $hasException;
        $this->exceptionType = $exceptionType;
        $this->exceptionMessage = $exceptionMessage;
        $this->alertsSuspended = $hasAlertsSuspended;
        $this->osVersionName = $osVersionName;
    }
    
    /**
     * @return string The key name of the agent that was tested
     */
    public function getAgentKeyname()
    {
        return $this->agentKeyname;
    }

    /**
     * @return int The snapshot epoch for the dataset that was tested
     */
    public function getSnapshotEpoch()
    {
        return $this->snapshotEpoch;
    }

    /**
     * @return boolean Whether or not ransomware was detected
     */
    public function hasRansomware()
    {
        return $this->hasRansomware;
    }

    /**
     * @return bool Whether or not an exception was thrown while performing the ransomware tests
     */
    public function hasException()
    {
        return $this->hasException;
    }

    /**
     * @return string|null The exception type, if any (returns null if no exception was thrown)
     */
    public function getExceptionType()
    {
        return $this->exceptionType;
    }

    /**
     * @return string|null The exception message, if any (returns null if no exception was thrown)
     */
    public function getExceptionMessage()
    {
        return $this->exceptionMessage;
    }

    /**
     * @return bool|null Were partner alerts suspended at the time the ransomware tests were run?
     */
    public function hasAlertsSuspended()
    {
        return $this->alertsSuspended;
    }

    /**
     * @return string|null The name of the operating system.
     */
    public function getOsVersionName()
    {
        return $this->osVersionName;
    }
}
