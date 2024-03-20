<?php

namespace Datto\Asset\Agent\Serializer;

use Datto\Asset\Agent\ScreenshotVerificationSettings;
use Datto\Asset\Serializer\Serializer;

/**
 * Class ScreenshotVerificationSettingsSerializer
 *
 * Unserialize:
 *  $screenshotVerificationSettings = $serializer->unserialize(array(
 *      ScreenshotVerificationSettingsSerializer::FILE_KEY_VERIFICATION => array(
 *          ScreenshotVerificationSettingsSerializer::DELAY => 36,
 *      ),
 *      ScreenshotVerificationSettingsSerializer::FILE_KEY_NOTIFICATION => array(
 *          ScreenshotVerificationSettingsSerializer::USE_SUCCESS => true,
 *          ScreenshotVerificationSettingsSerializer::ERROR_TIME => 10
 *      )
 *  ));
 *
 * Serialize:
 *  $serializedScreenshotVerificationSettings = $serializer->serialize(new ScreenshotVerificationSettings(...));
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class ScreenshotVerificationSettingsSerializer implements Serializer
{
    const DELAY = 'delay';
    const ERROR_TIME = 'errorTime';
    const FILE_KEY_VERIFICATION = 'screenshotVerification';
    const FILE_KEY_NOTIFICATION = 'screenshotNotification';
    const EXPECTED_APPLICATIONS = 'expectedApplications';
    const EXPECTED_SERVICES = 'expectedServices';

    /**
     * @param mixed $screenshotSettings Object to convert to array
     * @return string[] serialized screenshot verification settings
     */
    public function serialize($screenshotSettings)
    {
        return [
            self::FILE_KEY_VERIFICATION => json_encode([
                self::DELAY => $screenshotSettings->getWaitTime(),
                self::EXPECTED_APPLICATIONS => $screenshotSettings->getExpectedApplications(),
                self::EXPECTED_SERVICES => $screenshotSettings->getExpectedServices()
            ]),
            self::FILE_KEY_NOTIFICATION => json_encode([
                self::ERROR_TIME => strval($screenshotSettings->getErrorTime())
            ])
        ];
    }

    /**
     * @param array $fileArray Array containing serialized objects
     * @return ScreenshotVerificationSettings The screenshot verification settings as read from the file array. If no
     * such object is present in the array, the default settings will be returned.
     */
    public function unserialize($fileArray)
    {
        $verificationSettings = @json_decode($fileArray[self::FILE_KEY_VERIFICATION], true);
        $notificationSettings = @json_decode($fileArray[self::FILE_KEY_NOTIFICATION], true);
        $delay = isset($verificationSettings[self::DELAY]) ? $verificationSettings[self::DELAY] : null;
        $errorTime = isset($notificationSettings[self::ERROR_TIME]) ? $notificationSettings[self::ERROR_TIME] : null;
        $expectedApplications = $verificationSettings[self::EXPECTED_APPLICATIONS] ?? [];
        $expectedServices = $verificationSettings[self::EXPECTED_SERVICES] ?? [];

        return new ScreenshotVerificationSettings(
            $delay,
            $errorTime,
            $expectedApplications,
            $expectedServices
        );
    }
}
