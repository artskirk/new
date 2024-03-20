<?php

namespace Datto\Events;

use DateTime;
use DateTimeInterface;
use Datto\Events\Common\CommonEventNodeFactory;
use Datto\Events\IscsiHealthCheck\IscsiHealthCheckContext;
use Datto\Events\IscsiHealthCheck\IscsiHealthCheckData;

/**
 * Factory class to create IscsiHealthCheckEvents from the data that is relevant to those Events
 *
 * @author Mark Blakley <mblakley@datto.com>
 * @author Matt Coleman <matt@datto.com>
 */
class IscsiHealthCheckEventFactory
{
    const IRIS_SOURCE_NAME = 'iris';
    const ISCSI_HEALTH_CHECK_EVENT_NAME = 'device.iscsi.healthcheck';

    /** @var CommonEventNodeFactory */
    private $nodeFactory;

    public function __construct(CommonEventNodeFactory $nodeFactory)
    {
        $this->nodeFactory = $nodeFactory;
    }

    /**
     * @param DateTimeInterface $timestamp
     * @param int $hungProcessPid
     * @param string $hungProcessWchan
     * @param DateTimeInterface $hungProcessStartDateTime
     * @param int $numHungProcesses
     * @param string $hungTargetCliProcessList
     * @return Event
     */
    public function create(
        DateTimeInterface $timestamp,
        int $hungProcessPid,
        string $hungProcessWchan,
        DateTimeInterface $hungProcessStartDateTime,
        int $numHungProcesses,
        string $hungTargetCliProcessList
    ): Event {
        $iscsiHealthCheckData = new IscsiHealthCheckData(
            $hungProcessPid,
            $hungProcessWchan,
            $hungProcessStartDateTime->format(DateTime::ISO8601),
            $numHungProcesses,
            $this->nodeFactory->createPlatformData()
        );

        $iscsiHealthCheckContext = new IscsiHealthCheckContext($hungTargetCliProcessList);

        return new Event(
            self::IRIS_SOURCE_NAME,
            self::ISCSI_HEALTH_CHECK_EVENT_NAME,
            $iscsiHealthCheckData,
            $iscsiHealthCheckContext,
            'IHC-' . $timestamp->getTimestamp(),
            $this->nodeFactory->getResellerId(),
            $this->nodeFactory->getDeviceId(),
            null,
            $timestamp
        );
    }
}
