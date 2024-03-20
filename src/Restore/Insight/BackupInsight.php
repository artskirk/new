<?php

namespace Datto\Restore\Insight;

use Datto\Asset\Agent\Agent;

/**
 * Represents a backup insight
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class BackupInsight
{
    /** @var Agent */
    private $agent;

    /** @var int */
    private $firstPoint;

    /** @var int */
    private $secondPoint;

    /** @var bool */
    private $complete;

    /**
     * @param Agent $agent
     * @param int $firstPoint
     * @param int $secondPoint
     * @param bool $complete
     */
    public function __construct(Agent $agent, int $firstPoint, int $secondPoint, bool $complete = false)
    {
        $this->agent = $agent;
        $this->firstPoint = $firstPoint;
        $this->secondPoint = $secondPoint;
        $this->complete = $complete;
    }

    /**
     * @return Agent
     */
    public function getAgent(): Agent
    {
        return $this->agent;
    }

    /**
     * @return int
     */
    public function getFirstPoint(): int
    {
        return $this->firstPoint;
    }

    /**
     * @return int
     */
    public function getSecondPoint(): int
    {
        return $this->secondPoint;
    }

    /**
     * @param bool $complete
     */
    public function setComplete(bool $complete)
    {
        $this->complete = $complete;
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        return [
            'agentKey' => $this->agent->getKeyName(),
            'firstPoint' => $this->firstPoint,
            'secondPoint' => $this->secondPoint,
            'complete' => $this->complete
        ];
    }
}
