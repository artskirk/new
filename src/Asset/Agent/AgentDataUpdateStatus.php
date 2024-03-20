<?php


namespace Datto\Asset\Agent;

use Datto\Config\JsonConfigRecord;

/**
 * Record representing the current status of an AgentDataUpdate job from AgentDataUpdateService.
 * @author Devon Welcheck <dwelcheck@datto.com>
 */
class AgentDataUpdateStatus extends JsonConfigRecord
{
    const STATUS_IN_PROGRESS = 'inProgress';
    const STATUS_FAILED = 'failed';
    const STATUS_SUCCESS = 'success';

    /** @var string */
    private $status = '';

    /**
     * @inheritDoc
     */
    public function getKeyName(): string
    {
        return 'agentDataUpdateStatus';
    }

    /**
     * @inheritDoc
     */
    protected function load(array $vals)
    {
        $this->status = $vals['status'] ?? '';
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            'status' => $this->status
        ];
    }

    /**
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @param string $status
     */
    public function setStatus(string $status): void
    {
        $this->status = $status;
    }
}
