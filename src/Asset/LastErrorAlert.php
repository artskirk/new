<?php

namespace Datto\Asset;

use Datto\Log\AssetRecord;
use Datto\Log\Formatter\AssetFormatter;

/**
 * Holds information about the last error reported
 *
 * @author Rixhers Ajazi <rajazi@datto.com>
 */
class LastErrorAlert
{
    /** @var string */
    protected $hostname;

    /** @var int */
    protected $deviceId;

    /** @var int */
    protected $time;

    /** @var array */
    protected $agentData;

    /** @var string */
    protected $code;

    /** @var int */
    protected $errorTime;

    /** @var string */
    protected $message;

    /** @var string */
    protected $type;

    /** @var string */
    protected $log;

    /** @var array|null */
    protected $context;

    public function __construct(
        string $hostname,
        int $deviceId,
        int $time,
        array $agentData,
        string $code,
        int $errorTime,
        string $message,
        string $type,
        string $log,
        $context = null
    ) {
        $this->hostname = $hostname;
        $this->deviceId = $deviceId;
        $this->time = $time;
        $this->agentData = $agentData;
        $this->code = $code;
        $this->errorTime = $errorTime;
        $this->message = $message;
        $this->type = $type;
        $this->log = $log;
        $this->context = $context;
    }

    /**
     * @return string
     */
    public function getHostname()
    {
        return $this->hostname;
    }

    /**
     * @return int
     */
    public function getDeviceId()
    {
        return $this->deviceId;
    }

    /**
     * @return int
     */
    public function getTime()
    {
        return $this->time;
    }

    /**
     * @return array
     */
    public function getAgentData()
    {
        return $this->agentData;
    }

    /**
     * @return string
     */
    public function getCode()
    {
        return $this->code;
    }

    /**
     * @return int
     */
    public function getErrorTime()
    {
        return $this->errorTime;
    }

    /**
     * @return string
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @return string
     */
    public function getLog()
    {
        return $this->log;
    }

    /**
     * @return array|null
     */
    public function getContext()
    {
        return $this->context;
    }

    /**
     * @return AssetRecord[]
     */
    public function getRecords(): array
    {
        return AssetFormatter::parse((string)$this->log);
    }

    /**
     * @return mixed[]
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'timestamp' => $this->time,
            'errorTimestamp' => $this->errorTime,
            'message' => $this->message,
            'agentInfo' => $this->agentData,
            'records' => $this->getRecordsAsArray(),
            'context' => $this->context
        ];
    }

    /**
     * @return mixed[]
     */
    private function getRecordsAsArray(): array
    {
        $recordArrays = [];
        $records = $this->getRecords();

        foreach ($records as $record) {
            $recordArrays[] = $record->toArray();
        }

        return $recordArrays;
    }
}
