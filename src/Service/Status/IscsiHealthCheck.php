<?php

namespace Datto\Service\Status;

use DateTimeImmutable;
use Datto\Common\Resource\ProcessFactory;
use Datto\Events\EventService;
use Datto\Events\IscsiHealthCheckEventFactory;
use Datto\Log\LoggerFactory;
use Datto\Resource\DateTimeService;
use Datto\Log\DeviceLoggerInterface;

/**
 * Class IscsiHealthCheck
 *
 * @author Matt Coleman <mcoleman@datto.com>
 * @author Mark Blakley <mblakley@datto.com>
 */
class IscsiHealthCheck
{
    const TARGETCLI_D_STATE_TIMEOUT_SECONDS = 30;

    /** @var ProcessFactory */
    private $processFactory;

    /** @var IscsiHealthCheckEventFactory */
    private $eventFactory;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var EventService */
    private $eventService;

    /** @var DeviceLoggerInterface */
    private $logger;

    public function __construct(
        ProcessFactory $processFactory,
        IscsiHealthCheckEventFactory $eventFactory,
        DateTimeService $dateTimeService,
        EventService $eventService,
        LoggerFactory $loggerFactory
    ) {
        $this->processFactory = $processFactory;
        $this->eventFactory = $eventFactory;
        $this->dateTimeService = $dateTimeService;
        $this->eventService = $eventService;
        $this->logger = $loggerFactory->getDevice();
    }

    /**
     * Execute a command to find targetcli commands stuck in a D state
     *
     * @param bool $sendEvent Only sends an event if the health check fails (stuck processes found)
     */
    public function performHealthCheck(bool $sendEvent)
    {
        $hungTargetCliProcessList = $this->processFactory
            ->getFromShellCommandLine('ps axo etimes,lstart,pid,stat,wchan:32,command'
                . ' | awk \'$11=="/usr/bin/targetcli" && $8~/D/ && $1>' . self::TARGETCLI_D_STATE_TIMEOUT_SECONDS
                . ' {print}\' | sort -k1nr,1')
            ->mustRun()
            ->getOutput();

        if (!empty(trim($hungTargetCliProcessList))) {
            $processes = explode("\n", trim($hungTargetCliProcessList));
            $firstProcess = preg_split('/\s+/', $processes[0]);
            $age = $firstProcess[0];
            $pid = $firstProcess[6];
            $wchan = $firstProcess[8];
            $hungProcessStartDate = DateTimeImmutable::createFromFormat(
                'Y M j H:i:s',
                sprintf('%d %s %d %s', $firstProcess[5], $firstProcess[2], $firstProcess[3], $firstProcess[4])
            );

            $this->logger->critical(
                'IHC0001 System is affected by BCDR-16945: targetcli PID has been stuck for AGE seconds',
                ['targetcliPID' => $pid, 'wchan' => $wchan, 'age' => $age, 'totalHungProcesses' => count($processes)]
            );

            if ($sendEvent) {
                $healthCheckEvent = $this->eventFactory->create(
                    $this->dateTimeService->fromTimestamp($this->dateTimeService->getTime()),
                    $pid,
                    $wchan,
                    $hungProcessStartDate,
                    count($processes),
                    $hungTargetCliProcessList
                );
                $this->eventService->dispatch($healthCheckEvent, $this->logger);
            }
        } else {
            $this->logger->info('IHC0002 System currently is not experiencing any targetcli PIDs stuck in a D state.');
        }
    }
}
