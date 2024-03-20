<?php
namespace Datto\Asset\Serializer;

use Datto\Core\Asset\Configuration\WeeklySchedule;

/**
 * Serialize and unserialize the ".schedule" and ".offsiteSchedule" file into
 * a WeeklySchedule object. The serialized object is a PHP-serialized string
 * of 168 hours (7x24) indicating on/off by "0" or "1".
 *
 * Important Note:
 *   The file stores "on" as the /string/ value "0" [sic]. This serializer converts this
 *   internally to the boolean value 'true'.
 *
 * Unserializing:
 *   $schedule = $serializer->unserialize('a:168:{i:0;s:1:"1";i:1;s:1:"1";i:2;s:1:"1";i:3;s:1...');
 *
 * Serializing:
 *   $serializedSchedule = $serializer->serialize(new WeeklySchedule());
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class LegacyScheduleSerializer implements Serializer
{
    /** Number of hours in a week */
    const HOURS_IN_WEEK = 168;

    /** Number of hours in a day */
    const HOURS_IN_DAY = 24;

    /**
     * Serializes a WeeklySchedule object into a string.
     *
     * @param WeeklySchedule $schedule
     * @return string Serialized schedule object, e.g. a:168:{i:0;s:1:"1";i:1;s:1:"1";i:2;s:1:"1";i:3;s:1...
     */
    public function serialize($schedule)
    {
        /** @var WeeklySchedule $schedule */
        $week = array();

        foreach ($schedule->getDays() as $day) {
            $hours = $schedule->getDay($day);

            foreach ($hours as $hour) {
                $week[] = ($hour) ? "0" : "1"; // The config file stores 'true' as '0' !!
            }
        }

        return serialize($week);
    }

    /**
     * Create WeeklySchedule object from a serialized schedule object. If an
     * invalid string is given, a default weekly schedule is returned.
     *
     * @param string $serializedSchedule PHP-serialized schedule string
     * @return WeeklySchedule
     */
    public function unserialize($serializedSchedule)
    {
        $schedule = new WeeklySchedule();

        if (isset($serializedSchedule) && is_string($serializedSchedule)) {
            $rawSchedule = @unserialize($serializedSchedule, ['allowed_classes' => false]);
            $valid = is_array($rawSchedule) && count($rawSchedule) === self::HOURS_IN_WEEK;

            if ($valid) {
                $rawScheduleAsBool = array_map(function ($day) {
 // Convert to boolean values
                    return $day === 0 || $day === "0"; // The config file stores 'true' as '0' !!
                }, $rawSchedule);

                $rawScheduleByDays = array_chunk($rawScheduleAsBool, self::HOURS_IN_DAY, false); // Split in per-day arrays
                $schedule->setSchedule($rawScheduleByDays);
            }
        }

        return $schedule;
    }
}
