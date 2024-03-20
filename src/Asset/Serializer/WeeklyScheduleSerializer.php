<?php
namespace Datto\Asset\Serializer;

use Datto\Core\Asset\Configuration\WeeklySchedule;

/**
 * Convert a WeeklySchedule to an array, or an array to a WeeklySchedule
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class WeeklyScheduleSerializer implements Serializer
{
    /**
     * Get data from the model and save it in the repository
     * @param WeeklySchedule $object
     * @return array[]
     */
    public function serialize($object)
    {
        /** @var WeeklySchedule $schedule */
        $schedule = $object;

        return array(
            'sun' => self::dayToString($schedule->getDay(WeeklySchedule::SUNDAY)),
            'mon' => self::dayToString($schedule->getDay(WeeklySchedule::MONDAY)),
            'tue' => self::dayToString($schedule->getDay(WeeklySchedule::TUESDAY)),
            'wed' => self::dayToString($schedule->getDay(WeeklySchedule::WEDNESDAY)),
            'thu' => self::dayToString($schedule->getDay(WeeklySchedule::THURSDAY)),
            'fri' => self::dayToString($schedule->getDay(WeeklySchedule::FRIDAY)),
            'sat' => self::dayToString($schedule->getDay(WeeklySchedule::SATURDAY))
        );
    }

    /**
     * Load data from the repository and set the model's attributes with it
     * @param array $fileArray
     * @return WeeklySchedule
     */
    public function unserialize($fileArray)
    {
        $schedule = new WeeklySchedule();

        $schedule->setDay(WeeklySchedule::SUNDAY, self::dayFromString($fileArray['sun']));
        $schedule->setDay(WeeklySchedule::MONDAY, self::dayFromString($fileArray['mon']));
        $schedule->setDay(WeeklySchedule::TUESDAY, self::dayFromString($fileArray['tue']));
        $schedule->setDay(WeeklySchedule::WEDNESDAY, self::dayFromString($fileArray['wed']));
        $schedule->setDay(WeeklySchedule::THURSDAY, self::dayFromString($fileArray['thu']));
        $schedule->setDay(WeeklySchedule::FRIDAY, self::dayFromString($fileArray['fri']));
        $schedule->setDay(WeeklySchedule::SATURDAY, self::dayFromString($fileArray['sat']));

        return $schedule;
    }
    
    private static function dayToString($hours): string
    {
        foreach ($hours as $key => $hour) {
            $hours[$key] = ($hour === true || $hour === 1) ? 1 : 0;
        }

        return join(' ', $hours);
    }

    private static function dayFromString($string): array
    {
        $hours = explode(' ', $string);

        foreach ($hours as $key => $hour) {
            $hours[$key] = (bool)$hour;
        }

        return $hours;
    }
}
