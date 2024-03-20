<?php

namespace Datto\Asset\Serializer;

use Datto\Asset\VerificationSchedule;
use Datto\Core\Asset\Configuration\WeeklySchedule;

/**
 * Class VerificationScheduleSerializer
 *
 * Unserialize:
 *  $verificationSchedule = $serializer->unserialize(array(
 *      VerificationScheduleSerializer::SCHEDULE_OPTION => 1
 *      VerificationScheduleSerializer::CUSTOM_SCHEDULE => $customScheduleArray
 *  ));
 *
 * Serialize:
 *  $serializedVerificationSchedule = $serializer->serialize(new VerificationSchedule(...));
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class VerificationScheduleSerializer implements Serializer
{
    const SCHEDULE_OPTION = 'scheduleOption';
    const CUSTOM_SCHEDULE = 'customSchedule';
    const FILE_KEY = 'screenshotVerification';

    /**
     * @param VerificationSchedule $verificationSchedule
     * @return array
     */
    public function serialize($verificationSchedule)
    {
        return array(static::FILE_KEY =>
            json_encode(array(
                static::SCHEDULE_OPTION => $verificationSchedule->getScheduleOption(),
                static::CUSTOM_SCHEDULE => $verificationSchedule->getCustomSchedule()->getOldStyleSchedule())));
    }

    /**
     * @param mixed $fileArray
     * @return VerificationSchedule
     */
    public function unserialize($fileArray)
    {
        $schedule = @json_decode($fileArray[static::FILE_KEY], true);
        $scheduleOption = isset($schedule[static::SCHEDULE_OPTION]) ? $schedule[static::SCHEDULE_OPTION] : null;
        $oldStyleSchedule = isset($schedule[static::CUSTOM_SCHEDULE]) ? $schedule[static::CUSTOM_SCHEDULE] : array();
        $customSchedule = new WeeklySchedule();
        $customSchedule->setFromOldStyleSchedule($oldStyleSchedule);

        return new VerificationSchedule($scheduleOption, $customSchedule);
    }
}
