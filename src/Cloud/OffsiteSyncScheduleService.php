<?php

namespace Datto\Cloud;

use Datto\Config\DeviceConfig;
use Datto\Config\LocalConfig;
use Datto\Feature\FeatureService;
use Datto\Log\LoggerAwareTrait;
use Datto\Resource\DateTimeService;
use Exception;
use Psr\Log\LoggerAwareInterface;

/**
 * Service that deals with scheduled bandwidth limits.  Schedules are persisted locally by default, or can be retrieved
 * from device-web where they were stored previously.
 *
 * FIXME: After 10/01/2021, remove calls to device-web from this class, since all devices should be able to use local scheduling after upgrading
 * FIXME: When device-web calls are removed, create a BandwidthSchedule JsonConfigRecord object and refactor to use it
 *
 * @author Mario Rial <mrial@datto.com>
 * @author Mark Blakley <mblakley@datto.com>
 */
class OffsiteSyncScheduleService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var JsonRpcClient */
    private $client;

    /** @var LocalConfig */
    private $localConfig;

    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var FeatureService */
    private $featureService;

    public function __construct(
        JsonRpcClient $client,
        LocalConfig $localConfig,
        DeviceConfig $deviceConfig,
        FeatureService $featureService
    ) {
        $this->client = $client;
        $this->localConfig = $localConfig;
        $this->deviceConfig = $deviceConfig;
        $this->featureService = $featureService;
    }

    /**
     * Gets the device offsite schedule specified by schedule id.
     *
     * @param int $scheduleId
     * @return array
     */
    public function get(int $scheduleId): array
    {
        $this->logger->debug('OSS0001 Get schedule', ['scheduleId' => $scheduleId]);

        if ($this->isUsingLocalSchedule()) {
            $currentSchedule = json_decode($this->deviceConfig->get(DeviceConfig::KEY_BANDWIDTH_SCHEDULE), true);
            return $currentSchedule[$scheduleId];
        } else {
            $params = [
                'scheduleId' => $scheduleId
            ];
            return $this->queryWithIdConvertError('v1/device/offsiteSyncSchedule/get', $params, 'OSS0010');
        }
    }

    /**
     * List all offsite schedules related with the device.
     *
     * @return array
     */
    public function getAll(): array
    {
        $this->logger->debug('OSS0002 Get all schedules');

        if ($this->isUsingLocalSchedule()) {
            $offsiteSyncSchedule = json_decode($this->deviceConfig->get(DeviceConfig::KEY_BANDWIDTH_SCHEDULE), true);
        } else {
            $offsiteSyncSchedule = $this->client->queryWithId('v1/device/offsiteSyncSchedule/getAll');
            $this->deviceConfig->set('bandwidthSchedule', json_encode($offsiteSyncSchedule));
        }

        return $offsiteSyncSchedule;
    }

    /**
     * Return the ideal maximum amount of data that can be transferred each
     * day of the week depending on the configured schedules.
     *
     * @param array|null $schedules
     * @return array
     */
    public function getWeeklyAverages(array $schedules = null): array
    {
        $schedules = $schedules ?: $this->getAll();
        $offsiteSyncSpeed = $this->localConfig->get('txSpeed');

        $output = [];
        $output['days'] = [];
        for ($i = 0; $i < 7; $i++) {
            $output['days'][] = $this->getAverageDayTransfer($i, $schedules, $offsiteSyncSpeed);
        }

        $output['totalWeek'] = 0;
        foreach ($output['days'] as $transferred) {
            $output['totalWeek'] += $transferred;
        }
        $output['avgWeek'] = (int)($output['totalWeek']/7);

        return $output;
    }

    /**
     * Returns all the data of getAll and getWeeklyAverages combined in an array.
     * {
     * "schedules" => getAll,
     * "averages" => getWeeklyAverages
     * }
     *
     * @return array
     */
    public function getWeeklyAveragesAndSchedules(): array
    {
        $schedules = $this->getAll();
        return [
            'schedules' => $schedules,
            'averages' => $this->getWeeklyAverages($schedules)
        ];
    }

    /**
     * Adds an offsite sync schedule configuration for the device.
     * And returns the created entry on success.
     *
     * @param int $secondsWeekStart start point measured in number of seconds since Monday at 00:00:00
     * @param int $secondsWeekEnd end point measured in number of seconds since Monday at 00:00:00
     * @param int $speed in Kilobytes per second
     * @return array schedule entry just created.
     */
    public function add(int $secondsWeekStart, int $secondsWeekEnd, int $speed): array
    {
        $this->logger->info('OSS0006 Add schedule', ['start' => $secondsWeekStart, 'end' => $secondsWeekEnd, 'speed' => $speed]);

        if ($this->isUsingLocalSchedule()) {
            $this->validateSchedule($secondsWeekStart, $secondsWeekEnd);
            $currentSchedule = $this->getAll();
            $scheduleEntry['schedID'] = "$secondsWeekStart$secondsWeekEnd";
            $scheduleEntry['deviceID'] = $this->localConfig->get('deviceID');
            $scheduleEntry['start'] = $secondsWeekStart;
            $scheduleEntry['end'] = $secondsWeekEnd;
            $scheduleEntry['speed'] = $speed;
            $currentSchedule[] = $scheduleEntry;
            $this->deviceConfig->set('bandwidthSchedule', json_encode(array_values($currentSchedule)));
            return $scheduleEntry;
        } else {
            $params = [
                'secondsWeekStart' => $secondsWeekStart,
                'secondsWeekEnd' => $secondsWeekEnd,
                'speed' => $speed
            ];

            return $this->queryWithIdConvertError('v1/device/offsiteSyncSchedule/add', $params, 'OSS0011');
        }
    }

    /**
     * Deletes the device offsite schedule specified by schedule id.
     *
     * @param int $scheduleId
     */
    public function delete(int $scheduleId)
    {
        $this->logger->info('OSS0007 Delete schedule', ['scheduleId' => $scheduleId]);

        if ($this->isUsingLocalSchedule()) {
            $currentSchedule = $this->getAll();
            // Find the array entry to remove, and then unset it
            $schedIndexToRemove = array_search($scheduleId, array_column($currentSchedule, 'schedID'));
            if ($schedIndexToRemove !== false) {
                unset($currentSchedule[$schedIndexToRemove]);
                $this->deviceConfig->set('bandwidthSchedule', json_encode(array_values($currentSchedule)));
            } else {
                $this->logAndThrow('OSS0008', 'Unable to delete schedule entry');
            }
        } else {
            $params = [
                'scheduleId' => $scheduleId
            ];
            $this->queryWithIdConvertError('v1/device/offsiteSyncSchedule/delete', $params, 'OSS0012');
        }
    }

    /**
     * Returns the amount of data in Kilo Bytes that is transferred in a given day of the week
     * depending on the configured schedules and default transfer rate.
     *
     * @param int $weekDay
     * @param $schedules
     * @param int $defaultRate
     * @return int amount of data in Kilo Bytes
     */
    private function getAverageDayTransfer(int $weekDay, array $schedules, int $defaultRate): int
    {
        $schedulesDuration = 0;
        $dataTransferred = 0;
        foreach ($schedules as $schedule) {
            $start = $schedule['start'];
            $end = $schedule['end'];
            $speed = $schedule['speed'];
            $duration = $this->getNumberOfSecondsInDay($weekDay, $start, $end);
            if ($duration === 0) {
                continue;
            }
            $schedulesDuration += $duration;
            if ($speed > 0) {
                $dataTransferred += $duration*$speed;
            }
        }

        $restOfTheDay = DateTimeService::SECONDS_PER_DAY - $schedulesDuration;

        return ($restOfTheDay*$defaultRate) + $dataTransferred;
    }

    /**
     * Returns the amount of seconds that a given interval spans across a given day.
     *
     * @param int $weekDay
     * @param int $secondsWeekStart start of the interval
     * @param int $secondsWeekEnd end of the interval
     * @return int
     */
    private function getNumberOfSecondsInDay(int $weekDay, int $secondsWeekStart, int $secondsWeekEnd): int
    {
        $dayStart = $this->getSecondDayStart($weekDay);
        $dayEnd = $this->getSecondDayEnd($weekDay);

        if ($secondsWeekStart >= $dayEnd) {
            $this->logger->debug('OSS0003 The interval starts after/when day ends.', ['weekday' => $weekDay ]);
            return 0;
        }

        if ($secondsWeekEnd <= $dayStart) {
            $this->logger->debug('OSS0004 The interval ends before/when day starts.', ['weekday' => $weekDay ]);
            return 0;
        }

        $secondsEnd = $secondsWeekEnd > $dayEnd ? $dayEnd : $secondsWeekEnd;
        $secondsStart = $secondsWeekStart < $dayStart ? $dayStart : $secondsWeekStart;

        $intervalSeconds = $secondsEnd - $secondsStart;
        $this->logger->debug('OSS0005 Interval seconds', ['intervalSeconds' => $intervalSeconds]);
        return $secondsEnd - $secondsStart;
    }

    /**
     * Get the second in which the specified day of the week starts.
     * Starting from Monday at 00:00:00
     *
     * @param int $weekDay
     * @return int
     */
    private function getSecondDayStart(int $weekDay): int
    {
        return DateTimeService::SECONDS_PER_DAY * $weekDay;
    }

    /**
     * Get the second in which the specified day of the week ends.
     * Starting from Monday at 00:00:00
     *
     * @param int $weekDay
     * @return int
     */
    private function getSecondDayEnd(int $weekDay): int
    {
        return (DateTimeService::SECONDS_PER_DAY * $weekDay) + DateTimeService::SECONDS_PER_DAY;
    }

    /**
     * Returns true when the local bandwidth scheduling feature is turned on AND the schedule is actually stored locally
     * @return bool true is local scheduling can be used, false if it's still necessary to the schedule from the DB
     */
    private function isUsingLocalSchedule(): bool
    {
        return $this->featureService->isSupported(FeatureService::FEATURE_LOCAL_BANDWIDTH_SCHEDULING)
            && $this->deviceConfig->has('bandwidthSchedule');
    }

    /**
     * Makes a query to the cloud converting the CloudErrorException to a regular exception,
     * this way is serialized correctly with all the information and not just a generic error.
     *
     * @param string $method
     * @param array $params
     * @param string $logCode The log code to include in any exceptions that may be thrown as a result of this query
     * @return array|string
     */
    private function queryWithIdConvertError(string $method, array $params, string $logCode)
    {
        try {
            return $this->client->queryWithId($method, $params);
        } catch (CloudErrorException $exception) {
            $this->logAndThrow($logCode, $exception->getErrorObject()['message'], $exception->getErrorObject()['code']);
        }
    }

    /**
     * Make sure that the requested schedule is formatted properly, fits within the correct time range,
     * and does not overlap any existing schedule entries.
     *
     * @param int $secondsWeekStart The time offset from the beginning of the week that the schedule starts, in seconds
     * @param int $secondsWeekEnd The time offset from the beginning of the week that the schedule ends, in seconds
     */
    private function validateSchedule(int $secondsWeekStart, int $secondsWeekEnd)
    {
        if ($secondsWeekStart >= $secondsWeekEnd) {
            $this->logAndThrow('OSS0013', 'End time has to be greater than start time.');
        }

        if ($secondsWeekEnd > DateTimeService::SECONDS_PER_WEEK - 1) {
            $this->logAndThrow('OSS0014', 'Invalid time range, range cannot span two different weeks');
        }

        if ($secondsWeekStart < 0) {
            $this->logAndThrow('OSS0015', 'Start time has to be greater than or equal to 0 seconds from the beginning of the week');
        }

        $dayStart = (int)($secondsWeekStart / DateTimeService::SECONDS_PER_DAY);
        // Subtract one second to allow one schedule to span across the 86400 seconds of the day.
        $dayEnd = (int)(($secondsWeekEnd - 1) / DateTimeService::SECONDS_PER_DAY);

        if ($dayStart !== $dayEnd) {
            $this->logAndThrow('OSS0016', 'Invalid time range, range cannot be across two different days.');
        }

        foreach ($this->getAll() as $schedule) {
            if ($schedule['start'] < $secondsWeekEnd && $schedule['end'] > $secondsWeekStart) {
                $this->logAndThrow('OSS0017', 'An event already exists for:');
            }
        }
    }

    private function logAndThrow(string $logCode, string $message, int $code = 0)
    {
        $logContext = $code !== 0 ? ['errorCode' => $code] : [];
        $this->logger->error("$logCode $message", $logContext); // nosemgrep: utils.security.semgrep.log-context-in-message, utils.security.semgrep.log-no-log-code
        throw new Exception($message, $code);
    }
}
