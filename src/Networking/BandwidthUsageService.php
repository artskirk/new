<?php

namespace Datto\Networking;

use Datto\Resource\DateTimeService;
use Datto\Utility\Bandwidth\Vnstat;

/**
 * Service for getting bandwidth usage information
 *
 * @author Mark Blakley <mblakley@datto.com>
 */
class BandwidthUsageService
{
    const HOUR = 'hour';

    /** @var Vnstat */
    private Vnstat $vnstat;

    /** @var DateTimeService */
    private DateTimeService $dateTimeService;

    public function __construct(Vnstat $vnstat, DateTimeService $dateTimeService)
    {
        $this->vnstat = $vnstat;
        $this->dateTimeService = $dateTimeService;
    }

    /**
     * Get bandwidth usage data per hour
     *
     * @return BandwidthUsageDataPoint[] array of bandwidth usage data by hour
     */
    public function getHourlyUsageData(): array
    {
        //output in json format, only show hours, and limit to past 24 hours
        $vnStatData = $this->vnstat->getJson(Vnstat::MODE_HOURS, DateTimeService::HOURS_PER_DAY);

        $usageData = $this->initializeHourlyDataPoints();

        foreach ($vnStatData['interfaces'] as $interfaceData) {
            foreach ($interfaceData['traffic'][self::HOUR] as $hourData) {
                $usageData[$hourData['time'][self::HOUR]]->addBandwidthUsage($hourData['rx'], $hourData['tx']);
            }
        }

        // Reorder the hours so that yesterday's stats come first
        $currentHour = $this->dateTimeService->format('H');
        $yesterdayHours = array_slice($usageData, $currentHour, DateTimeService::HOURS_PER_DAY - $currentHour, true);
        $todayHours = array_slice($usageData, 0, $currentHour, true);
        $usageData = $yesterdayHours + $todayHours;

        return $usageData;
    }

    /**
     * @return BandwidthUsageDataPoint[] An array of data points initialized to 0 rx/tx bandwidth
     */
    private function initializeHourlyDataPoints(): array
    {
        $usageData = [];
        // Initialize a day's worth of hourly data points - we may get less than a full day of data returned by the tool
        for ($hour = 0; $hour < DateTimeService::HOURS_PER_DAY; $hour++) {
            $usageData[$hour] = new BandwidthUsageDataPoint(
                self::HOUR,
                $hour,
                0,
                0
            );
        }

        return $usageData;
    }
}
