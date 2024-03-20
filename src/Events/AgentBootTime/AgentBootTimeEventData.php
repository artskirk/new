<?php

namespace Datto\Events\AgentBootTime;

use DateTimeInterface;
use Datto\Events\AbstractEventNode;
use Datto\Events\EventDataInterface;

/**
 * Class to implement the data node included in AgentBootTimeEvents
 *
 * @author Mark Blakley <mblakley@datto.com>
 */
class AgentBootTimeEventData extends AbstractEventNode implements EventDataInterface
{
    /** @var string The agent key name from the agent that we requested the last boot time from */
    protected $agentKeyName;

    /** @var DateTimeInterface The last system boot time of the agent system */
    protected $bootTime;

    /**
     * AgentBootTimeEventData contains the indexed data from an agent boot time event
     *
     * @param string $agentKeyName
     * @param DateTimeInterface $bootTime
     */
    public function __construct(
        string $agentKeyName,
        DateTimeInterface $bootTime
    ) {
        $this->agentKeyName = $agentKeyName;
        $this->bootTime = $bootTime;
    }

    /**
     * @inheritDoc
     */
    public function getSchemaVersion(): int
    {
        return 2;
    }
}
