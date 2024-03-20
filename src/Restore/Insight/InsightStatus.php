<?php

namespace Datto\Restore\Insight;

use Datto\Config\JsonConfigRecord;

/**
 * Represents backup insights status.
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class InsightStatus extends JsonConfigRecord
{
    const LOG_PATH_FORMAT = "/dev/shm/%s.mftDiffLog";

    const STATUS_COMPLETE = 'complete';
    const STATUS_CLONING = 'cloning';
    const STATUS_MOUNTING = 'mounting';
    const STATUS_CALCULATING = 'calculating';
    const STATUS_FAILED = 'failed';

    /** @var bool */
    private $completed = false;

    /** @var string */
    private $message;

    /** @var string */
    private $agentKey;

    /** @var bool */
    private $failed = false;

    /**
     * @return boolean
     */
    public function isFailed()
    {
        return $this->failed;
    }

    /**
     * @param boolean $failed
     */
    public function setFailed($failed)
    {
        $this->failed = $failed;
    }

    /**
     * @param boolean $completed
     */
    public function setCompleted($completed)
    {
        $this->completed = $completed;
    }

    /**
     * @param string $message
     */
    public function setMessage($message)
    {
        $this->message = $message;
    }

    /**
     * @param string $agentKey
     */
    public function setAgentKey($agentKey)
    {
        $this->agentKey = $agentKey;
    }

    /**
     * @return string
     */
    public function getAgentKey(): string
    {
        return $this->agentKey;
    }

    /**
     * @return bool
     */
    public function isCompleted(): bool
    {
        return $this->completed;
    }

    /**
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @return mixed[]
     */
    public function toArray(): array
    {
        return [
            'message' => $this->message,
            'agentKey' => $this->agentKey,
            'completed' => $this->completed,
            'failed' => $this->failed
        ];
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    /**
     * @return string
     */
    public function getKeyName(): string
    {
        return 'mftDiffLog';
    }

    /**
     * {@inheritdoc}
     */
    protected function load(array $vals)
    {
        $necessaryFieldsPresent = isset($vals['message'])
            && isset($vals['agentKey'])
            && isset($vals['completed'])
            && isset($vals['failed']);

        if (!$necessaryFieldsPresent) {
            throw new \Exception("Unable to create insight status, improper array format. Need message, agentKey, and completed");
        }

        $this->message = $vals['message'];
        $this->agentKey = $vals['agentKey'];
        $this->completed = $vals['completed'];
        $this->failed = $vals['failed'];
    }
}
