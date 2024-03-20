<?php

namespace Datto\Resource;

use DateTime;
use DateTimeImmutable;

/**
 * Class DateTimeService
 *
 * Provides a wrapper around time-based PHP functions.
 *
 * @author Mike Micatka <mmicatka@datto.com>
 * @codeCoverageIgnore
 */
class DateTimeService
{
    const SECONDS_PER_MINUTE = 60;
    const SECONDS_PER_HOUR = 3600;
    const SECONDS_PER_DAY = 86400;
    const SECONDS_PER_WEEK = 604800;
    const MINUTES_PER_HOUR = 60;
    const HOURS_PER_WEEK = 168;
    const HOURS_PER_DAY = 24;

    const PAST_HOUR = '-1 hour';
    const PAST_DAY = '-1 day';
    const PAST_WEEK = '-1 week';
    const PAST_MONTH = '-1 month';
    const PAST_QUARTER = '-3 month';
    const PAST_YEAR = '-1 year';

    /**
     * @return int the current epoch time
     */
    public function getTime(): int
    {
        return time();
    }

    /**
     * Get timestamp on days instead of seconds.
     *
     * @param int|null $timestamp
     * @return int
     */
    public function getTimeInDays(int $timestamp = null): int
    {
        $time = $timestamp ?? $this->getTime();

        return intdiv($time, self::SECONDS_PER_DAY);
    }

    /**
     * @return string of the form "0.03033400 1534365749" where the first is <1 and the second is a unix timestamp
     */
    public function getMicroTime(): string
    {
        return microtime();
    }

    /**
     * @param int $startTime
     *
     * @return int elapsed time since start time.
     */
    public function getElapsedTime(int $startTime)
    {
        return $this->getTime() - $startTime;
    }

    /**
     * @param string $format
     * @param int $time
     * @return string|false
     */
    public function getDate($format, int $time = null)
    {
        return date($format, $time ?? time());
    }

    /**
     * @param string $relativeString
     * @return DateTime
     */
    public function getRelative(string $relativeString): DateTime
    {
        $date = new DateTime();
        $date->modify($relativeString);
        return $date;
    }

    /**
     * @param string $format
     * @param int|null $time
     * @return false|string
     */
    public function format(string $format, int $time = null)
    {
        return date($format, $time ?? $this->getTime());
    }

    /**
     * Get the hour of the week that corresponds to the given epoch time.
     *
     * @param int $timestamp an epoch timestamp
     * @return int
     */
    public function getHourOfWeek(int $timestamp): int
    {
        list($weekStart, $weekMinute, $weekHour) = $this->getWeekRelativeComponents($timestamp);

        return (int)$weekHour;
    }

    /**
     * Get the minute of the week that corresponds to the given epoch time.
     *
     * @param int $timestamp
     * @return int
     */
    public function getMinuteOfWeek(int $timestamp): int
    {
        list($weekStart, $weekMinute, $weekHour) = $this->getWeekRelativeComponents($timestamp);

        return (int)$weekMinute;
    }

    /**
     * Get the start of the week that corresponds to the given epoch time.
     *
     * @param int $timestamp
     * @return int
     */
    public function getStartOfWeek(int $timestamp): int
    {
        list($weekStart, $weekMinute, $weekHour) = $this->getWeekRelativeComponents($timestamp);

        return (int)$weekStart;
    }

    /**
     * Get a numeric representation of the day of the week of the given timestamp. 0 => Sunday, 6 => Saturday.
     *
     * @param int $timestamp
     * @return int
     */
    public function getDayOfWeek(int $timestamp): int
    {
        return date('w', $timestamp);
    }

    /**
     * PHP's builtin \strtotime().
     *
     * @param string $datetime
     * @param ?int $baseTimestamp The timestamp which is used as a base for the calculation of relative dates. If not
     * included, defaults to now().
     * @return false|int
     */
    public function stringToTime(string $datetime, ?int $baseTimestamp = null)
    {
        if ($baseTimestamp === null) {
            // Passing null as $baseTimestamp causes it to be interpreted as '1970-01-01' whereas not including that
            // argument causes it to default to 'now()'. In PHP 8.0 however, passing null as $baseTimestamp will default
            // to 'now()'.
            return strtotime($datetime);
        }
        return strtotime($datetime, $baseTimestamp);
    }

    /**
     * @param int $timestamp
     * @return DateTime
     */
    public function fromTimestamp(int $timestamp): DateTime
    {
        $date = new DateTime();
        $date->setTimestamp($timestamp);
        return $date;
    }

    /**
     * Timezone aware day comparison
     *
     * @param int $timestamp1
     * @param int $timestamp2
     * @return int < 0 if $timestamp1 is on a prior day than $timestamp2;
     * int > 0 if $timestamp1 is on a later day than $timestamp2;
     * int = 0 if they are on the same day.
     */
    public function dayCompare(int $timestamp1, int $timestamp2): int
    {
        $date1 = getdate($timestamp1);
        $date2 = getdate($timestamp2);

        if ($date1['year'] < $date2['year']) {
            return -1;
        } elseif ($date1['year'] > $date2['year']) {
            return 1;
        } elseif ($date1['mon'] < $date2['mon']) {
            return -1;
        } elseif ($date1['mon'] > $date2['mon']) {
            return 1;
        } elseif ($date1['mday'] < $date2['mday']) {
            return -1;
        } elseif ($date1['mday'] > $date2['mday']) {
            return 1;
        } else {
            return 0;
        }
    }

    /**
     * Returns whether or not $timestamp1 is at least on one day later, than $timestamp2
     * @param int $timestamp1
     * @param int $timestamp2
     * @return bool
     */
    public function isAtLeastNextDay(int $timestamp1, int $timestamp2): bool
    {
        return $this->dayCompare($timestamp1, $timestamp2) > 0;
    }

    /**
     * @param int $timestamp1
     * @param int $timestamp2
     * @return bool
     */
    public function isSameDay(int $timestamp1, int $timestamp2): bool
    {
        return $this->dayCompare($timestamp1, $timestamp2) === 0;
    }

    /**
     * Create an immutable representation of the current time
     * @return DateTimeImmutable
     */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    /**
     * @param int $timestamp
     * @return array
     */
    private function getWeekRelativeComponents(int $timestamp): array
    {
        $now = getdate($timestamp);
        $weekStart = $timestamp
            - $now['seconds']
            - $now['minutes'] * self::SECONDS_PER_MINUTE
            - $now['hours'] * self::SECONDS_PER_HOUR
            - $now['wday'] * self::SECONDS_PER_HOUR * self::HOURS_PER_DAY;
        $weekMinute = (int)floor(($timestamp - $weekStart) / self::SECONDS_PER_MINUTE);
        $weekHour = (int)floor($weekMinute / self::SECONDS_PER_MINUTE);

        return [$weekStart, $weekMinute, $weekHour];
    }
}
