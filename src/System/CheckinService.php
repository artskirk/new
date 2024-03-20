<?php

namespace Datto\System;

use Datto\Common\Resource\ProcessFactory;
use Datto\Config\DeviceConfig;
use Datto\Resource\DateTimeService;

/**
 * Kicks off checkin, and gets information about it.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class CheckinService
{
    const CHECKIN_BIN = '/datto/bin/checkin';

    private ProcessFactory $processFactory;

    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var DateTimeService */
    private $timeService;

    public function __construct(
        ProcessFactory $processFactory,
        DeviceConfig $deviceConfig,
        DateTimeService $timeService
    ) {
        $this->processFactory = $processFactory;
        $this->deviceConfig = $deviceConfig;
        $this->timeService = $timeService;
    }

    /**
     * Run checkin in the background
     */
    public function checkin()
    {
        $this->processFactory
            ->get(['/usr/bin/systemd-run', '--slice=system.slice', static::CHECKIN_BIN])
            ->mustRun();
    }

    /**
     * Return unix timestamp of last checkin
     *
     * @return int
     */
    public function getTimestamp(): int
    {
        return intval($this->deviceConfig->get('lastCheckinEpoch', 0));
    }

    /**
     * Return seconds since the last checkin
     *
     * @return int
     */
    public function getSecondsSince(): int
    {
        $seconds = $this->timeService->getTime() - $this->getTimestamp();

        if ($seconds >= 0) {
            return $seconds;
        } else {
            return 0;
        }
    }
}
