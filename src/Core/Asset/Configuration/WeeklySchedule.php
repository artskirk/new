<?php

namespace Datto\Core\Asset\Configuration;

use Datto\Resource\DateTimeService;
use Exception;

/**
 * A schedule that can hold a boolean for each hour of each day of the week
 * If an hour is set to true, the thing being scheduled will happen in that hour
 * Used for setting a "custom schedule" for backups, offsiting, etc.
 *
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class WeeklySchedule
{
    const SUNDAY = 0;
    const MONDAY = 1;
    const TUESDAY = 2;
    const WEDNESDAY = 3;
    const THURSDAY = 4;
    const FRIDAY = 5;
    const SATURDAY = 6;
    const HOURS_PER_WEEK = 168;
    const HOURS_PER_DAY = 24;
    const MINUTES_PER_HOUR = 60;
    const MIDNIGHT = 0;
    const FIRST_WEEKDAY_HOUR_DEFAULT = 8;
    const LAST_WEEKDAY_HOUR_DEFAULT = 18;

    const NO_BACKUP_HOUR_FOUND = 1;
    const IN_FIRST_BACKUP_BLOCK = 2;
    const PAST_FIRST_BACKUP_BLOCK = 3;
    const IN_SECOND_BACKUP_BLOCK = 4;
    const PAST_SECOND_BACKUP_BLOCK = 5;
    const NO_SCHEDULE_GAP = 6;
    const IN_SCHEDULE_GAP = 7;

    /** @var int[] All the possible days */
    private static $days = [self::SUNDAY, self::MONDAY, self::TUESDAY, self::WEDNESDAY, self::THURSDAY,
        self::FRIDAY, self::SATURDAY];

    /** @var array Valid weekend hours for standard schedules using a separate weekend schedule */
    public static $standardWeekendHours = [7, 11, 15, 19, 23];

    /** @var array[] array of days - each day is an array of hours */
    protected $schedule;

    /** @var DateTimeService */
    private $dateTimeService;

    /**
     * Instantiate a custom schedule
     *
     * CustomScheduleDay(s) are instantiated with new; it's easier to
     * test both classes together than to mock days.
     *
     * @param DateTimeService|null $dateTimeService
     */
    public function __construct(DateTimeService $dateTimeService = null)
    {
        $this->dateTimeService = $dateTimeService ?? new DateTimeService();

        $this->setToDefault();
    }

    /**
     * @return array Get the names of the days of the week (use this like a constant)
     */
    public static function getDays(): array
    {
        return self::$days;
    }

    /**
     * @return array[] schedule
     */
    public function getSchedule(): array
    {
        return $this->schedule;
    }

    /**
     * @param array[] $schedule
     */
    public function setSchedule(array $schedule)
    {
        $this->validateSchedule($schedule);
        $schedule = $this->convertToBool($schedule);
        $this->schedule = $schedule;
    }

    /**
     * Sets this WeeklySchedule's schedule from an old-style schedule array.
     *
     * @param string[] $oldStyleSchedule Old-style weekly schedule array containing integers as strings from "0" to "167"
     */
    public function setFromOldStyleSchedule(array $oldStyleSchedule)
    {
        $newSchedule = array_fill(0, 7, array_fill(0, self::HOURS_PER_DAY, false));
        foreach (array_map('intval', $oldStyleSchedule) as $hour) {
            $newSchedule[intdiv($hour, self::HOURS_PER_DAY)][$hour % self::HOURS_PER_DAY] = true;
        }
        $this->setSchedule($newSchedule);
    }

    /**
     * Get this WeeklySchedule's schedule as an old-style weekly schedule array containing integers as strings from "0"
     * to "167".
     *
     * @return string[] This weekly schedule as an old-style weekly schedule array
     */
    public function getOldStyleSchedule(): array
    {
        $oldStyleSchedule = [];
        foreach (range(0, self::HOURS_PER_WEEK - 1) as $i) {
            if ($this->schedule[intdiv($i, self::HOURS_PER_DAY)][$i % self::HOURS_PER_DAY]) {
                $oldStyleSchedule[] = strval($i);
            }
        }
        return $oldStyleSchedule;
    }

    /**
     * @param int $day day of the schedule to get
     * @return int[]|bool[] array of hours for the schedule day
     */
    public function getDay(int $day): array
    {
        $this->validateDay($day);
        return $this->schedule[$day];
    }

    /**
     * @param int $day day of the schedule to get
     * @param int[]|bool[] array of hours for the schedule day
     */
    public function setDay(int $day, array $hours)
    {
        $this->validateDay($day);
        $this->validateHours($hours);
        $hours = $this->convertToBool($hours);
        $this->schedule[$day] = $hours;
    }

    /**
     * Unset (set to false) every entry in this schedule, that is false in another schedule (passed as an argument)
     * @param WeeklySchedule $filterSchedule schedule to filter by
     */
    public function filter(WeeklySchedule $filterSchedule)
    {
        $this->setSchedule($this->getFilteredSchedule($filterSchedule));
    }

    /**
     * Given a backup interval in minutes, calculate the number of backups that would be performed in a week
     * using this weekly schedule.
     * @param int $interval backup interval in minutes
     * @return int
     */
    public function calculateBackupCount(int $interval): int
    {
        $hours = count($this->getOldStyleSchedule());
        return (intdiv(self::MINUTES_PER_HOUR, intval($interval))) * $hours;
    }

    /**
     * Is this schedule valid when restricted by the given filter?
     * @param WeeklySchedule $filterSchedule
     * @return bool
     */
    public function isValidWithFilter(WeeklySchedule $filterSchedule): bool
    {
        return $this->getSchedule() === $this->getFilteredSchedule($filterSchedule);
    }

    /**
     * Whether or not this schedule is a standard backup schedule.
     * @return bool true if this schedule is a "standard" backup schedule, false if it is a "custom" backup schedule
     */
    public function isStandardSchedule(): bool
    {
        $mondaySchedule = $this->getDay(self::MONDAY);
        $weekdaysTheSame = in_array(true, $mondaySchedule)          // empty schedules are considered non-standard
            && !$this->dailyScheduleHasGaps(self::MONDAY)
            && $mondaySchedule == $this->getDay(self::TUESDAY)
            && $mondaySchedule == $this->getDay(self::WEDNESDAY)
            && $mondaySchedule == $this->getDay(self::THURSDAY)
            && $mondaySchedule == $this->getDay(self::FRIDAY);
        return $weekdaysTheSame && ($this->isWeekendSame(self::MONDAY) || $this->hasStandardWeekendSchedule());
    }

    /**
     * Whether or not both Saturday and Sunday have the same schedule as the input day.
     * @param int $day A day of the week as defined in the class constants
     * @return bool
     */
    public function isWeekendSame(int $day): bool
    {
        return $this->getDay($day) == $this->getDay(self::SATURDAY)
            && $this->getDay($day) == $this->getDay(self::SUNDAY);
    }

    /**
     * Is the given hour of the week set for this WeeklySchedule?
     *
     * @param int $hour
     * @return bool
     */
    public function checkWeekHour(int $hour): bool
    {
        $oldStyleSchedule = $this->getOldStyleSchedule();
        return in_array((string)$hour, $oldStyleSchedule);
    }

    /**
     * Get the backup range for a given standard schedule. Only returns meaningful results if the target schedule
     * is a "standard" schedule.
     * @param int $day A day of the week as defined in the class constants
     * @return string[] with keys 'firstHour' => the first hour in the range, 'lastHour' => the last hour in the range
     */
    public function getBackupRange(int $day): array
    {
        $dailySchedule = $this->getDay($day);

        $hasMidnightBackup = $dailySchedule[0];

        // If there is a midnight backup, this is potentially a wraparound backup schedule
        if ($hasMidnightBackup) {
            $state = self::NO_SCHEDULE_GAP;
            $firstHour = self::MIDNIGHT;
            $lastHour = self::MIDNIGHT;

            foreach (range(self::MIDNIGHT + 1, count($dailySchedule) - 1) as $hour) {
                if ($state == self::NO_SCHEDULE_GAP) {
                    if ($dailySchedule[$hour]) {
                        $lastHour = $hour;
                    } else {
                        $state = self::IN_SCHEDULE_GAP;
                    }
                } elseif ($state == self::IN_SCHEDULE_GAP) {
                    if ($dailySchedule[$hour]) {
                        $firstHour = $hour;
                        break;
                    }
                }
            }
        } else {
            $firstHour = $this->getFirstBackupHour($dailySchedule);
            $lastHour = $this->getLastBackupHour($dailySchedule);
        }
        return ['firstHour' => $firstHour, 'lastHour' => $lastHour];
    }

    /**
     * Given a day of the week, determine whether or not that day of the week has gaps. That is, determine whether or
     * not the hours that are set on this schedule are a continuous block of hours, or are staggered.
     * @param int $day A day of the week as defined in the class constants.
     * @return bool Whether or not the daily schedule has gaps
     */
    public function dailyScheduleHasGaps(int $day): bool
    {
        $dailySchedule = $this->getDay($day);
        $hasMidnightBackup = $dailySchedule[0];

        if (!$hasMidnightBackup) {
            $state = self::NO_BACKUP_HOUR_FOUND;
        } else {
            $state = self::IN_FIRST_BACKUP_BLOCK;
        }

        foreach (range(1, count($dailySchedule) - 1) as $hour) {
            $hourIsBackedUp = $dailySchedule[$hour];

            switch ($state) {
                case self::NO_BACKUP_HOUR_FOUND:
                    if ($hourIsBackedUp) {
                        $state = self::IN_FIRST_BACKUP_BLOCK;
                    }
                    break;
                case self::IN_FIRST_BACKUP_BLOCK:
                    if (!$hourIsBackedUp) {
                        $state = self::PAST_FIRST_BACKUP_BLOCK;
                    }
                    break;
                case self::PAST_FIRST_BACKUP_BLOCK:
                    if ($hourIsBackedUp) {
                        if ($hasMidnightBackup) {
                            $state = self::IN_SECOND_BACKUP_BLOCK;
                        } else {
                            return true;
                        }
                    }
                    break;
                case self::IN_SECOND_BACKUP_BLOCK:
                    if (!$hourIsBackedUp) {
                        $state = self::PAST_SECOND_BACKUP_BLOCK;
                    }
                    break;
                case self::PAST_SECOND_BACKUP_BLOCK:
                    if ($hourIsBackedUp) {
                        return true;
                    }
                    break;
            }
        }
        return false;
    }

    /**
     * Returns a new WeeklySchedule that has only the first scheduled point of the day set for each weekday
     *
     * @return WeeklySchedule
     */
    public function filterByFirstPointOfDay(): WeeklySchedule
    {
        return $this->filterByFirstOrLastPointOfDay('first');
    }

    /**
     * Returns a new WeeklySchedule that has only the last scheduled point of the day set for each weekday
     *
     * @return WeeklySchedule
     */
    public function filterByLastPointOfDay(): WeeklySchedule
    {
        return $this->filterByFirstOrLastPointOfDay('last');
    }

    /**
     * Is this an empty schedule?
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        foreach (static::getDays() as $day) {
            $dailySchedule = $this->getDay($day);
            if (in_array(true, $dailySchedule)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check if any points are scheduled between a start and end time.
     *
     * @param int $start
     * @param int $end
     * @param int $interval
     * @return bool
     */
    public function hasPointBetween(
        int $start,
        int $end,
        int $interval
    ): bool {
        $epochs = $this->getLastDailyEpochsBetween($start, $end, $interval);

        foreach ($epochs as $epoch) {
            if ($epoch >= $start && $epoch <= $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the last scheduled backup per-day between a start and end time.
     *
     * @param int $start
     * @param int $end
     * @param int $interval
     * @return int[]
     */
    public function getLastDailyEpochsBetween(
        int $start,
        int $end,
        int $interval
    ): array {
        $last = $this->filterByLastPointOfDay();

        $epochs = [];

        while ($start <= $end) {
            $weekStart = $this->dateTimeService->getStartOfWeek($start);
            $weekMinute = $this->dateTimeService->getMinuteOfWeek($start);
            $weekHour = $this->dateTimeService->getHourOfWeek($start);

            if ($last->checkWeekHour($weekHour)) {
                if ($weekMinute % $interval === 0) {
                    // Found a scheduled point
                    $epochs[$weekHour] = $weekStart + $weekMinute * 60;
                }
            }

            $start += 60;
        }

        return array_values($epochs);
    }

    /**
     * @param int $timestamp
     * @param int $intervalMinutes
     * @return bool
     */
    public function isLastPredictedScheduledBackupOfTheDay(int $timestamp, int $intervalMinutes): bool
    {
        $last = $this->filterByLastPointOfDay();

        $start = $timestamp + 60; // Add one minute to prevent matching self
        $end = $this->dateTimeService->fromTimestamp($timestamp);
        $end->setTime(23, 59, 0); // Round up to the end of the day
        $end = $end->getTimestamp();

        $isLast = !$last->hasPointBetween($start, $end, $intervalMinutes);

        return $isLast;
    }

    /**
     * Get the earliest hour set on the daily schedule.
     *
     * @param bool[] $dailySchedule
     * @return int|bool the earliest backup hour in the schedule, or false if the schedule is empty for that day
     */
    private function getFirstBackupHour(array $dailySchedule)
    {
        foreach (range(0, count($dailySchedule) - 1) as $hour) {
            if ($dailySchedule[$hour]) {
                return $hour;
            }
        }
        return false;
    }

    /**
     * Get the latest hour set on the daily schedule.
     *
     * @param bool[] $dailySchedule
     * @return int|bool the latest backup hour in the schedule, or false if the schedule is empty for that day
     */
    private function getLastBackupHour(array $dailySchedule)
    {
        $returnValue = false;
        foreach (range(0, count($dailySchedule) - 1) as $hour) {
            if ($dailySchedule[$hour]) {
                $returnValue = $hour;
            }
        }
        return $returnValue;
    }

    /**
     * Determine whether or not the weekend follows a "standard" schedule. This means that the only hours being backed
     * on the weekend are those that are defined in self::$standardWeekendHours.
     * @return bool Whether or not the weekend follows a "standard" schedule.
     */
    private function hasStandardWeekendSchedule(): bool
    {
        $saturdaySchedule = $this->getDay(self::SATURDAY);
        if ($saturdaySchedule != $this->getDay(self::SUNDAY)) {
            return false;
        }
        foreach (range(0, count($saturdaySchedule) - 1) as $hour) {
            if (!in_array($hour, self::$standardWeekendHours) && $saturdaySchedule[$hour]) {
                return false;
            }
        }
        return true;
    }

    private function getFilteredSchedule(WeeklySchedule $filterSchedule): array
    {
        $thisSchedule = $this->getSchedule();
        $filterSchedule = $filterSchedule->getSchedule();

        foreach (self::$days as $day) {
            foreach ($thisSchedule[$day] as $hour => $setting) {
                $thisSchedule[$day][$hour] = $thisSchedule[$day][$hour] && $filterSchedule[$day][$hour];
            }
        }

        return $thisSchedule;
    }

    private function setToDefault()
    {
        $weekendDefault = array_fill(0, self::HOURS_PER_DAY, false);
        $weekdayDefault = array_fill(0, 24, false);
        foreach (range(self::FIRST_WEEKDAY_HOUR_DEFAULT, self::LAST_WEEKDAY_HOUR_DEFAULT) as $hour) {
            $weekdayDefault[$hour] = true;
        }

        $this->setDay(self::SATURDAY, $weekendDefault);
        $this->setDay(self::MONDAY, $weekdayDefault);
        $this->setDay(self::TUESDAY, $weekdayDefault);
        $this->setDay(self::WEDNESDAY, $weekdayDefault);
        $this->setDay(self::THURSDAY, $weekdayDefault);
        $this->setDay(self::FRIDAY, $weekdayDefault);
        $this->setDay(self::SUNDAY, $weekendDefault);
    }

    private function validateDay(int $day): bool
    {
        if (!in_array($day, self::$days)) {
            throw new Exception("$day is not a valid day.");
        }
        return true;
    }

    private function validateSchedule(array $schedule)
    {
        if (array_keys($schedule) !== self::$days) {
            throw new Exception("Schedule has invalid days.");
        }
        foreach ($schedule as $hours) {
            $this->validateHours($hours);
        }
    }

    private function validateHours(array $hours)
    {
        if (count($hours) !== self::HOURS_PER_DAY) {
            throw new Exception("Each day of the schedule must be an array of " . self::HOURS_PER_DAY . " hours.");
        }
        foreach ($hours as $hour => $scheduled) {
            if ($scheduled !== true && $scheduled !== false && $scheduled !== 1 && $scheduled !== 0) {
                throw new Exception("Hours must all be set to a boolean");
            }
        }
    }

    private function convertToBool(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->convertToBool($value);
            } else {
                $values[$key] = (bool)$value;
            }
        }
        return $values;
    }

    /**
     * filterByLastPointOfDay and filterByFirstPointOfDay have a near identical implementation,
     * with only a single method call in the middle differing. Rather than duplicating the code, this is just an ugly
     * helper that lets us choose which method to use via a string passed in.
     *
     * If we need a more versatile filter function in the future, modify this to take in a callable and use that to
     * filter the schedule
     *
     * @param string $firstOrLast
     *      Choose between using the first or last point of the day. Valid values are "first" or "last"
     * @return WeeklySchedule
     *      Returns a new WeeklySchedule that has only the first or last point of day set
     */
    private function filterByFirstOrLastPointOfDay(string $firstOrLast): WeeklySchedule
    {
        if (!in_array($firstOrLast, ['first', 'last'])) {
            throw new Exception('Parameter must be either the string "first" or "last"');
        }

        $newSchedule = new WeeklySchedule();

        for ($day = 0; $day < 7; $day++) {
            $dayScheduleArray = array_fill(0, self::HOURS_PER_DAY, false);

            $scheduledHour = false;
            if ($firstOrLast === 'first') {
                $scheduledHour = $this->getFirstBackupHour($this->getDay($day));
            } elseif ($firstOrLast === 'last') {
                $scheduledHour = $this->getLastBackupHour($this->getDay($day));
            }

            if ($scheduledHour !== false) {
                $dayScheduleArray[$scheduledHour] = true;
            }

            $newSchedule->setDay($day, $dayScheduleArray);
        }

        return $newSchedule;
    }

    /**
     * @param WeeklySchedule $schedule
     */
    public function copyFrom(WeeklySchedule $schedule)
    {
        $this->setFromOldStyleSchedule($schedule->getOldStyleSchedule());
    }

    /**
     * @param DateTimeService|null $dateTimeService
     * @return WeeklySchedule
     */
    public static function createEmpty(DateTimeService $dateTimeService = null): WeeklySchedule
    {
        $empty = array_fill(0, self::HOURS_PER_DAY, false);

        $schedule = new self($dateTimeService);
        $schedule->setSchedule([
            self::SUNDAY => $empty,
            self::MONDAY => $empty,
            self::TUESDAY => $empty,
            self::WEDNESDAY => $empty,
            self::THURSDAY => $empty,
            self::FRIDAY => $empty,
            self::SATURDAY => $empty
        ]);

        return $schedule;
    }

    /**
     * Build a custom schedule that randomly selects an hour, and schedules a screenshot at that hour every day.
     *
     * Several Azure devices may offload their screenshots to a shared hypervisor. Scheduling each asset's screenshot
     * at a random hour helps stagger these screenshot operations.
     * @param DateTimeService|null $dateTimeService
     * @return WeeklySchedule
     */
    public static function createAzureRandomSchedule(DateTimeService $dateTimeService = null): WeeklySchedule
    {
        $day = array_fill(0, WeeklySchedule::HOURS_PER_DAY, false);
        $randomHour = rand(WeeklySchedule::MIDNIGHT, WeeklySchedule::HOURS_PER_DAY-1);
        $day[$randomHour] = true;

        $schedule = new self($dateTimeService);
        $schedule->setSchedule([
            WeeklySchedule::SUNDAY => $day,
            WeeklySchedule::MONDAY => $day,
            WeeklySchedule::TUESDAY => $day,
            WeeklySchedule::WEDNESDAY => $day,
            WeeklySchedule::THURSDAY => $day,
            WeeklySchedule::FRIDAY => $day,
            WeeklySchedule::SATURDAY => $day
        ]);
        return $schedule;
    }
}
