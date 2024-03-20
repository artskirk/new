<?php

namespace Datto\Events;

use DateTimeInterface;
use Datto\Asset\Agent\Agent;
use Datto\Events\AgentBootTime\AgentBootTimeEventData;
use Datto\Resource\DateTimeService;

/**
 * Factory class to create AgentBootTimeEvents from the data that is relevant to those Events
 *
 * @author Mark Blakley <mblakley@datto.com>
 */
class AgentBootTimeEventFactory
{
    const IRIS_SOURCE_NAME = 'iris';
    const AGENT_BOOT_TIME_EVENT_NAME = 'device.agent.boottime';

    /** @var DateTimeService */
    private $dateTimeService;

    /**
     * AgentBootTimeEventFactory creates an instance of an Event that contains information about the last boot time of
     * a specific agent system.
     *
     * @param DateTimeService $dateTimeService
     */
    public function __construct(DateTimeService $dateTimeService)
    {
        $this->dateTimeService = $dateTimeService;
    }

    /**
     * Returns a new Event object created using the provided information and some information that is specific to
     * this event type.
     *
     * @param Agent $agent
     * @param DateTimeInterface $bootTime
     * @return Event
     */
    public function create(Agent $agent, DateTimeInterface $bootTime): Event
    {
        $timestamp = $this->dateTimeService->now();
        $data = new AgentBootTimeEventData(
            $agent->getKeyName(),
            $bootTime
        );

        return new Event(
            self::IRIS_SOURCE_NAME,
            self::AGENT_BOOT_TIME_EVENT_NAME,
            $data,
            null,
            'ABTE-' . $timestamp->getTimestamp(),
            $agent->getOriginDevice()->getResellerId(),
            $agent->getOriginDevice()->getDeviceId(),
            null,
            $timestamp
        );
    }
}
