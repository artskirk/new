<?php

namespace Datto\Asset;

use Datto\Core\Asset\Configuration\WeeklySchedule;

/**
 * Class VerificationSchedule
 *
 * @author Christopher R. Wicks <cwicks@datto.com>
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class VerificationSchedule
{
    const NEVER = 0;
    const LAST_POINT = 1;
    const FIRST_POINT = 2;
    const CUSTOM_SCHEDULE = 3;
    const OFFSITE = 4;

    const DEFAULT_SCHEDULE_OPTION = self::OFFSITE;

    const SCHEDULE_OPTIONS = [
        self::NEVER,
        self::LAST_POINT,
        self::FIRST_POINT,
        self::CUSTOM_SCHEDULE,
        self::OFFSITE
    ];

    private int $scheduleType;

    /** @var WeeklySchedule */
    private $customSchedule;

    /**
     * VerificationSchedule constructor.
     *
     * @param int $scheduleOption The type of schedule to use for verification. Use defined class constants:
     * NEVER, LAST_POINT, FIRST_POINT, CUSTOM_SCHEDULE
     * @param WeeklySchedule $customSchedule The custom schedule (if any) to use for screenshots
     */
    public function __construct(
        $scheduleOption = null,
        $customSchedule = null
    ) {
        $this->customSchedule = $customSchedule ?: new WeeklySchedule();
        $this->setScheduleOption(($scheduleOption !== null) ? $scheduleOption : self::DEFAULT_SCHEDULE_OPTION);
    }

    /**
     * @return int The screenshot verification schedule type of this agent
     */
    public function getScheduleOption(): int
    {
        return $this->scheduleType;
    }

    /**
     * @param int $option The type of schedule to use for screenshots. Use defined class constants:
     * NEVER, LAST_POINT, FIRST_POINT, CUSTOM_SCHEDULE
     */
    public function setScheduleOption($option): void
    {
        $this->scheduleType = $option;
        if ($option != self::CUSTOM_SCHEDULE) {
            // clear the schedule
            $this->customSchedule->setFromOldStyleSchedule(array());
        }
    }

    /**
     * @return WeeklySchedule the custom schedule.
     */
    public function getCustomSchedule()
    {
        return $this->customSchedule;
    }

    /**
     * @param WeeklySchedule $schedule The custom schedule to use for screenshots.
     */
    public function setCustomSchedule($schedule): void
    {
        $this->customSchedule = $schedule;
    }

    /**
     * @param VerificationSchedule $source
     */
    public function copyFrom(VerificationSchedule $source): void
    {
        $this->customSchedule->copyFrom($source->getCustomSchedule());
        $this->setScheduleOption($source->getScheduleOption());
    }
}
