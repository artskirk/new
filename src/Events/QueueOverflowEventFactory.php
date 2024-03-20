<?php

namespace Datto\Events;

use Datto\Events\Common\CommonEventNodeFactory;
use Datto\Events\QueueOverflow\QueueOverflowData;
use Datto\Resource\DateTimeService;

/**
 * Factory class to create QueueOverflowEvent from the data that is relevant to
 * those Events
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class QueueOverflowEventFactory
{
    const IRIS_SOURCE_NAME = 'iris';
    const QUEUE_OVERFLOW_EVENT_NAME = 'event.queue.overflow';

    /** @var CommonEventNodeFactory */
    private $nodeFactory;

    /** @var DateTimeService */
    private $dateTimeService;

    public function __construct(
        CommonEventNodeFactory $nodeFactory,
        DateTimeService $dateTimeService
    ) {
        $this->nodeFactory = $nodeFactory;
        $this->dateTimeService = $dateTimeService;
    }

    /**
     * Returns a new Event object created using some information that is
     * specific to this event type.
     *
     * @return Event
     */
    public function create(): Event
    {
        $timestamp = $this->dateTimeService->now();
        $data = new QueueOverflowData(
            $this->nodeFactory->createPlatformData()
        );

        return new Event(
            self::IRIS_SOURCE_NAME,
            self::QUEUE_OVERFLOW_EVENT_NAME,
            $data,
            null,
            'EQO-' . $timestamp->getTimestamp(),
            $this->nodeFactory->getResellerId(),
            $this->nodeFactory->getDeviceId(),
            null,
            $timestamp
        );
    }
}
