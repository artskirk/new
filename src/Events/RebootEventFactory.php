<?php

namespace Datto\Events;

use Datto\Config\DeviceConfig;
use Datto\Events\Common\CommonEventNodeFactory;
use Datto\Events\Reboot\RebootData;
use Datto\Resource\DateTimeService;

/**
 * Factory class to create RebootEvent from the data that is relevant to those Events
 *
 * @author Bartosz Neuman <bneuman@datto.com>
 */
class RebootEventFactory
{
    const IRIS_SOURCE_NAME = 'iris';
    const DEVICE_REBOOT_EVENT_NAME = 'device.reboot';

    /** @var CommonEventNodeFactory */
    private $nodeFactory;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var DeviceConfig */
    private $deviceConfig;

    public function __construct(
        CommonEventNodeFactory $nodeFactory,
        DateTimeService $dateTimeService,
        DeviceConfig $deviceConfig
    ) {
        $this->nodeFactory = $nodeFactory;
        $this->dateTimeService = $dateTimeService;
        $this->deviceConfig = $deviceConfig;
    }

    /**
     * Returns a new Event object created using the provided information and some information that is specific to
     * this event type.
     *
     * @param bool $wasClean
     * @param string $cause
     * @return Event
     */
    public function create(bool $wasClean, string $cause): Event
    {
        $timestamp = $this->dateTimeService->now();
        $data = new RebootData(
            $this->nodeFactory->createPlatformData(),
            $wasClean,
            $cause
        );

        return new Event(
            self::IRIS_SOURCE_NAME,
            self::DEVICE_REBOOT_EVENT_NAME,
            $data,
            null,
            'DRE-' . $timestamp->getTimestamp(),
            $this->deviceConfig->getResellerId(),
            $this->deviceConfig->getDeviceId(),
            null,
            $timestamp
        );
    }
}
