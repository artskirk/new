<?php

namespace Datto\App\Console\Command;

use Datto\Core\Asset\Configuration\WeeklySchedule;
use Datto\Utility\ByteUnit;

class AssetFormatter
{
    public function formatSchedule(WeeklySchedule $schedule): string
    {
        $names = array(
            WeeklySchedule::SUNDAY => 'Sun',
            WeeklySchedule::MONDAY => 'Mon',
            WeeklySchedule::TUESDAY => 'Tue',
            WeeklySchedule::WEDNESDAY => 'Wed',
            WeeklySchedule::THURSDAY => 'Thu',
            WeeklySchedule::FRIDAY => 'Fri',
            WeeklySchedule::SATURDAY => 'Sat',
        );

        $out = "     1  2  3  4  5  6  7  8  9  10 11 12 13 14 15 16 17 18 19 20 21 22 23 24\n";

        foreach ($schedule->getDays() as $day) {
            $dayName = $names[$day];
            $hours = $schedule->getDay($day);

            foreach ($hours as $index => $hour) {
                $hours[$index] = ($hour) ? 'X' : '-';
            }

            $out .= "$dayName  " . implode('  ', $hours)."\n";
        }

        return $out;
    }

    public function formatBool($bool): string
    {
        return ($bool) ? 'yes' : 'no';
    }

    public function formatBytes($bytes): string
    {
        $sizeInGib = ByteUnit::BYTE()->toGiB($bytes);
        return sprintf('%2.2f GB (%d bytes)', $sizeInGib, $bytes);
    }
}
