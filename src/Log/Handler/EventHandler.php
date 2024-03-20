<?php

namespace Datto\Log\Handler;

use Datto\Config\DeviceState;
use Datto\Events\EventService;
use Datto\Events\LogEventFactory;
use Datto\Log\DeviceNullLogger;
use Datto\Log\LoggerHelperTrait;
use Datto\Log\LogRecord;
use Datto\Utility\File\LockFactory;
use Datto\RemoteWeb\RemoteWebService;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;

/**
 * Sends a log message event.
 *
 * @author Jeffrey Knapp (jkanpp@datto.com)
 */
class EventHandler extends AbstractProcessingHandler
{
    use LoggerHelperTrait;

    const LOG_INDEX = 'logIndex';

    /** @var LogEventFactory */
    private $eventFactory;

    /** @var EventService */
    private $eventService;

    /** @var DeviceState */
    private $deviceState;

    /** @var LockFactory */
    private $lockFactory;

    /** @var RemoteWebService */
    private $remoteWebService;

    /** @var bool */
    private $enabled;

    public function __construct(
        LogEventFactory $eventFactory,
        EventService $eventService,
        DeviceState $deviceState,
        LockFactory $lockFactory,
        RemoteWebService $remoteWebService
    ) {
        parent::__construct(Logger::INFO, true);

        $this->eventFactory = $eventFactory;
        $this->eventService = $eventService;
        $this->deviceState = $deviceState;
        $this->lockFactory = $lockFactory;
        $this->remoteWebService = $remoteWebService;
        $this->enabled = true;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * Appends the formatted message to the appropriate log file.
     *
     * @param array $record
     */
    protected function write(array $record): void
    {
        $logRecord = new LogRecord($record);
        if (!$this->enabled || !$logRecord->shouldSendEvent()) {
            return;
        }

        $context = $this->getContextWithHiddenMetadataRemoved($logRecord->getContext());

        $event = $this->eventFactory->create(
            $this->getNextIndex(),
            $logRecord->getDateTime(),
            $logRecord->getLevel(),
            $logRecord->getAlertCode(),
            $logRecord->getMessage(),
            $logRecord->getContextId(),
            $logRecord->getUser(),
            $this->remoteWebService->getRemoteHost(),
            $logRecord->getAsset(),
            $context
        );

        // we do not want any logs to be sent to the device logger from the event service,
        // otherwise it will create a log -> event -> log loop.
        $nullLogger = new DeviceNullLogger();
        $this->eventService->dispatch($event, $nullLogger);
    }

    /**
     * Increments the log index by 1 and returns the new value.
     *
     * @return int
     */
    private function getNextIndex(): int
    {
        $lock = $this->lockFactory->create($this->deviceState->getKeyFilePath(self::LOG_INDEX));
        $lock->exclusive();
        $index = (int)trim($this->deviceState->getRaw(self::LOG_INDEX, '0')) + 1;
        $this->deviceState->setRaw(self::LOG_INDEX, $index);
        return $index;
    }
}
