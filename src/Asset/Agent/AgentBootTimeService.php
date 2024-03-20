<?php

namespace Datto\Asset\Agent;

use DateTimeInterface;
use Datto\Events\AgentBootTimeEventFactory;
use Datto\Events\EventService;
use Datto\Resource\DateTimeService;
use Datto\Util\OsFamily;
use Exception;
use Datto\Log\DeviceLoggerInterface;

/**
 * A service that makes a remote command to an agent to get information about the last boot time of that system
 * and then parses the returned data to get the DateTime of the last boot.
 * This service only works with Windows agents.
 *
 * @author Mark Blakley <mblakley@datto.com>
 */
class AgentBootTimeService
{
    const WINDOWS_BOOT_TIME_COMMAND = 'systeminfo';

    /** @var AgentBootTimeEventFactory */
    private $agentBootTimeEventFactory;

    /** @var RemoteCommandService */
    private $remoteCommandService;

    /** @var EventService */
    private $eventService;

    /** @var DateTimeService */
    private $dateTimeService;

    public function __construct(
        AgentBootTimeEventFactory $agentBootTimeEventFactory,
        RemoteCommandService $remoteCommandService,
        EventService $eventService,
        DateTimeService $dateTimeService
    ) {
        $this->agentBootTimeEventFactory = $agentBootTimeEventFactory;
        $this->remoteCommandService = $remoteCommandService;
        $this->eventService = $eventService;
        $this->dateTimeService = $dateTimeService;
    }

    /**
     * Retrieves the last system boot time from a Windows agent system and converts it into a DateTimeInterface object
     *
     * @param Agent $agent
     * @param bool $sendEvent
     * @return DateTimeInterface
     */
    public function retrieveLastSystemBootTime(Agent $agent): DateTimeInterface
    {
        $osBootTimeCommand = $this->getOsBootTimeCommand($agent->getOperatingSystem());
        $bootTimeResult = $this->remoteCommandService->runCommand($agent->getKeyName(), $osBootTimeCommand);

        $bootTime = $this->parseBootTime($bootTimeResult);

        return $bootTime;
    }

    /**
     * Sends a new AgentBootTimeEvent up to the device-web events endpoint so that it can be tracked
     *
     * @param Agent $agent
     * @param DateTimeInterface $bootTime
     * @param DeviceLoggerInterface $logger
     */
    public function sendBootTimeEvent(Agent $agent, DateTimeInterface $bootTime, DeviceLoggerInterface $logger): void
    {
        $bootTimeEvent = $this->agentBootTimeEventFactory->create($agent, $bootTime);
        $this->eventService->dispatch($bootTimeEvent, $logger);
    }

    /**
     * Returns the OS specific command that can be executed to retrieve the boot time
     *
     * @param OperatingSystem $os
     * @return string
     */
    private function getOsBootTimeCommand(OperatingSystem $os): string
    {
        switch ($os->getOsFamily()) {
            case OsFamily::WINDOWS():
                $bootTimeCommand = self::WINDOWS_BOOT_TIME_COMMAND;
                break;
            default:
                throw new Exception("ABT0002 Unable to run remote boot time commands on {$os->getName()}");
        }

        return $bootTimeCommand;
    }

    /**
     * Parse the command result and convert to a DateTime object with the boot time.
     * This is necessary because the command that we execute on the agent system returns a bunch of other stuff
     * along with the last boot time.
     *
     * @param RemoteCommandResult $bootTimeResult
     * @return DateTimeInterface
     */
    private function parseBootTime(RemoteCommandResult $bootTimeResult): DateTimeInterface
    {
        $lines = explode("\r\n", $bootTimeResult->getOutput());
        foreach ($lines as $line) {
            if (strpos($line, 'System Boot Time:') === 0) {
                $timeString = str_replace(',', '', trim(str_replace('System Boot Time:', '', $line)));
                //Format is "n/j/Y h:i:s A"
                $epoch = $this->dateTimeService->stringToTime($timeString);
                $bootTime = $this->dateTimeService->fromTimestamp($epoch);
                break;
            }
        }

        if (!isset($bootTime)) {
            throw new Exception("ABT0003 Unable to create DateTimeInterface object from {$bootTimeResult->getOutput()}");
        }

        return $bootTime;
    }
}
