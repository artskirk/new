<?php

namespace Datto\Events;

use Datto\Common\Utility\Filesystem;
use Datto\Config\DeviceConfig;
use Datto\Log\DeviceLoggerInterface;

/**
 * Base class for any services that dispatch events.
 *
 * @author Chad Kosie <ckosie@datto.com>
 * @author Mark Blakley <mblakley@datto.com>
 */
class EventService
{
    private const EVENTS_FILE = '/var/log/datto/events.log';

    private Filesystem $filesystem;
    protected DeviceConfig $deviceConfig;

    public function __construct(
        Filesystem $filesystem,
        DeviceConfig $deviceConfig
    ) {
        $this->filesystem = $filesystem;
        $this->deviceConfig = $deviceConfig;
    }

    /**
     * Queues an event request to send the the device-web events endpoint.
     *
     * @param Event $event
     * @param DeviceLoggerInterface $logger
     */
    public function dispatch(Event $event, DeviceLoggerInterface $logger)
    {
        $logger->info("EVT0001 Dispatching event", [
            'type' => $event->getType(),
            'requestId' => $event->getRequestId()
        ]);
        $eventLine = json_encode($event->jsonSerialize()) . PHP_EOL;
        if ($this->filesystem->filePutContents(self::EVENTS_FILE, $eventLine, FILE_APPEND) === false) {
            $logger->critical("EVT0004 Could not dispatch event", [
                'type' => $event->getType(),
                'requestId' => $event->getRequestId()
            ]);
        }
    }
}
