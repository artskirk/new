<?php

namespace Datto\Asset;

use Datto\Asset\Agent\ScreenshotVerificationSettings;
use Datto\Asset\Agent\Serializer\ScreenshotVerificationSettingsSerializer;
use Datto\Asset\Serializer\VerificationScheduleSerializer;

/**
 * Class VerificationSerializer
 * This class exists to solve a problem where two objects serialize data to the same file and must know about each
 * others data without knowing about each other as objects.
 *
 * VerificationSchedule and ScreenshotSettings both write to the screenshotVerificationfile, which reads as follows:
 *      [delay:5, scheduleOption:2, customSchedule:[]]
 *
 * VerificationSchedule cares about scheduleOption and customSchedule, while ScreenshotSettings cares only about delay.
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class VerificationSerializer
{
    const FILE_KEY_VERIFICATION = ScreenshotVerificationSettingsSerializer::FILE_KEY_VERIFICATION;
    const FILE_KEY_NOTIFICATION = ScreenshotVerificationSettingsSerializer::FILE_KEY_NOTIFICATION;


    /** @var ScreenshotVerificationSettingsSerializer */
    private $screenshotVerificationSerializer;

    /** @var VerificationScheduleSerializer */
    private $verificationScheduleSerializer;


    public function __construct(
        $screenshotVerificationSerializer = null,
        $verificationScheduleSerializer = null
    ) {
        $this->screenshotVerificationSerializer = $screenshotVerificationSerializer ?: new ScreenshotVerificationSettingsSerializer();
        $this->verificationScheduleSerializer = $verificationScheduleSerializer ?: new VerificationScheduleSerializer();
    }

    /**
     * @param VerificationSchedule $verificationSchedule
     * @param ScreenshotVerificationSettings $screenshotVerificationSettings
     * @return array Combined serialized array
     */
    public function serialize($verificationSchedule, $screenshotVerificationSettings)
    {
        $verificationScheduleFileArray = $this->verificationScheduleSerializer->serialize($verificationSchedule);
        $screenshotVerificationSettingsFileArray = $this->screenshotVerificationSerializer->serialize($screenshotVerificationSettings);

        $scheduleOptions = isset($verificationScheduleFileArray[self::FILE_KEY_VERIFICATION]) ? json_decode($verificationScheduleFileArray[self::FILE_KEY_VERIFICATION], true) : array(self::FILE_KEY_VERIFICATION => '');
        $screenshotSettings = isset($screenshotVerificationSettingsFileArray[self::FILE_KEY_VERIFICATION]) ? json_decode($screenshotVerificationSettingsFileArray[self::FILE_KEY_VERIFICATION], true) : array(self::FILE_KEY_VERIFICATION => '');

        $screenshotVerificationFileArray[self::FILE_KEY_VERIFICATION] = json_encode(
            $this->reorderArray(
                array_merge(
                    $screenshotSettings,
                    $scheduleOptions
                )
            )
        );

        $screenshotNotificationSettings = isset($screenshotVerificationSettingsFileArray[self::FILE_KEY_NOTIFICATION]) ? $screenshotVerificationSettingsFileArray[self::FILE_KEY_NOTIFICATION]
            : json_encode(array('errorTime' => ScreenshotVerificationSettings::DEFAULT_ERROR_TIME));

        $screenshotVerificationFileArray[self::FILE_KEY_NOTIFICATION] = $screenshotNotificationSettings;

        return $screenshotVerificationFileArray;
    }

    /**
     * Tests expect the array to be in a specific order.
     * Order array as such.
     *
     * @param string[] $array The array to order
     * @return array
     */
    private function reorderArray($array)
    {
        // Logic is a bit strange, but this means that the values have not ben set so just return the blank array.
        if (isset($array[self::FILE_KEY_VERIFICATION])) {
            return $array;
        }
        $newArray = array(
            'scheduleOption' => $array['scheduleOption'],
            'customSchedule' => $array['customSchedule'],
            'delay' => $array['delay'],
            'expectedApplications' => $array[ScreenshotVerificationSettingsSerializer::EXPECTED_APPLICATIONS],
            'expectedServices' => $array[ScreenshotVerificationSettingsSerializer::EXPECTED_SERVICES]
        );
        return $newArray;
    }
}
