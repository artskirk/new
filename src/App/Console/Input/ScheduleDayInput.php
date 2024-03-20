<?php
namespace Datto\App\Console\Input;

use Datto\App\Console\Command\CommandValidator;
use Datto\Core\Asset\Configuration\WeeklySchedule;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Class ScheduleDayInput
 * Holds, parses and validates the input format for a day of a weekly schedule
 */
class ScheduleDayInput
{
    const SUNDAY = 'sun';
    const MONDAY = 'mon';
    const TUESDAY = 'tue';
    const WEDNESDAY = 'wed';
    const THURSDAY = 'thu';
    const FRIDAY = 'fri';
    const SATURDAY = 'sat';

    const FORMAT_DESCRIPTION = "a range (ex: 9-17) or comma delimitered list (ex: 9,11,13,15,17)";
    const INVALID_RANGE = "last hour must be greater than its' first hour";
    const INVALID_HOUR = "hours must be between 0 (12 AM) and 23 (11 PM)";

    private static $dayMap = array(
        self::SUNDAY => WeeklySchedule::SUNDAY,
        self::MONDAY => WeeklySchedule::MONDAY,
        self::TUESDAY => WeeklySchedule::TUESDAY,
        self::WEDNESDAY => WeeklySchedule::WEDNESDAY,
        self::THURSDAY => WeeklySchedule::THURSDAY,
        self::FRIDAY => WeeklySchedule::FRIDAY,
        self::SATURDAY => WeeklySchedule::SATURDAY,
    );

    /** @var string day name used by the console command */
    private $day;

    /** @var string input string (represents a range or list of hours) */
    private $hoursString;

    /**
     * @param string $day day name used by the console command
     * @param string $hoursString input string (represents a range or list of hours)
     */
    public function __construct($day, $hoursString)
    {
        $this->day = $day;
        $this->hoursString = $hoursString;
    }

    public function getScheduleDay()
    {
        return self::$dayMap[$this->day];
    }

    /**
     * @return bool[] schedule as an array of hours, consumable by WeeklySchedule
     */
    public function getHoursArray()
    {
        if (strpos($this->hoursString, '-')) {
            $minmax = explode('-', $this->hoursString);
            return $this->getRangeHours($minmax[0], $minmax[1]);
        }

        if (strpos($this->hoursString, ',') || is_numeric($this->hoursString)) {
            $hoursSet = explode(',', $this->hoursString);
            return $this->getListHours($hoursSet);
        }

        return array_fill(0, 24, false);
    }

    private function getRangeHours($min, $max): array
    {
        $hours = array();
        for ($i=0; $i<24; $i++) {
            $hours[] = ($i >= $min && $i <= $max);
        }
        return $hours;
    }

    private function getListHours($list)
    {
        $hours = array();
        for ($i=0; $i<24; $i++) {
            $hours[] = (in_array($i, $list));
        }
        return $hours;
    }

    /**
     * @param CommandValidator $validator validation object
     */
    public function validate(CommandValidator $validator): void
    {
        $hoursString = $this->hoursString;

        $charactersValid = strcspn($hoursString, '0123456789-, ') === 0;
        $isRange = strpos($hoursString, '-') && count(explode('-', $hoursString)) == 2;
        $isList = strpos($hoursString, ',') || is_numeric($hoursString);

        if ($charactersValid && $isRange) {
            $this->validateHoursRange($validator);
        } elseif ($charactersValid && $isList) {
            $this->validateHoursList($validator);
        } else {
            $validator->validateValue(
                false,
                new Assert\IsTrue(),
                "$this->day's hours must be ".self::FORMAT_DESCRIPTION
            );
        }
    }

    private function validateHoursRange(CommandValidator $validator): void
    {
        $range = explode('-', $this->hoursString);
        $first = @$range[0];
        $last = @$range[1];

        $this->validateHour($validator, $first);
        $this->validateHour($validator, $last);

        $validator->validateValue(
            intval($last) > intval($first),
            new Assert\IsTrue(),
            $this->day."'s ".self::INVALID_RANGE
        );
    }

    private function validateHoursList(CommandValidator $validator): void
    {
        $hours = explode(',', $this->hoursString);
        foreach ($hours as $hour) {
            $this->validateHour($validator, $hour);
        }
    }
    private function validateHour(CommandValidator $validator, $hour): void
    {
        $validator->validateValue(
            intval($hour),
            new Assert\Range(array('min' => 0, 'max' => 23)),
            $this->day."'s ".self::INVALID_HOUR
        );
    }
}
