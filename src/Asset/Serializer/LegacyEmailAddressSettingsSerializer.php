<?php
namespace Datto\Asset\Serializer;

use Datto\Asset\EmailAddressSettings;

/**
 * Serialize and unserialize an EmailAddressSettings object (stored in the <asset>.emails keyfile)
 *
 * The serialized data has a special version number added to it.
 * The version allows the system to support reading legacy keyfiles and to
 * perform migrations to the current format during unserialization.
 * The data is always migrated to the CURRENT_VERSION during unserialization
 * and is stored in that format during serialization.
 * See the VERSION_# constants below for a description of each version.
 *
 * @author Kim Desorcie <kdesorcie@datto.com>
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class LegacyEmailAddressSettingsSerializer implements Serializer
{
    /**
     * @var integer Serialized email lists are represented as follows:
     *    If the "screenshots_success" email list is missing or empty, then
     *      the "screenshots" email list receives BOTH success and failure
     *      notifications.
     *    If the "screenshots_success" email list exists and is not empty, then
     *      it receives success notifications, and the "screenshots" email list
     *      receives ONLY failure notifications.
     *    All other email lists are unaffected by this version number.
     */
    const VERSION_ONE = 1;

    /**
     * @var integer Serialized email lists are represented as follows:
     *    The "screenshots" email list receives ONLY failure notifications.
     *    The "screenshots_success" email list receives success notifications.
     *    All other email lists are unaffected by this version number.
     */
    const VERSION_TWO = 2;

    /** @var integer Current keyfile version number */
    const CURRENT_VERSION = 2;

    /** @var string Keyfile version key name */
    const VERSION_KEY = 'version';

    /**
     * Serialize the array of comma delimited mailing list strings for writing
     * to the "<asset>.emails" keyfile.
     * This adds a version key which is used for unserialization only.
     *
     * @param string[] $emailArray Array of comma delimited mailing lists
     * @return string Serialized email alert settings for <asset>.emails keyfile
     */
    public function serializeEmailArray($emailArray)
    {
        $emailArray[self::VERSION_KEY] = self::CURRENT_VERSION;
        return serialize($emailArray);
    }

    /**
     * Unserialize the array of comma delimited mailing list strings which was
     * read from the "<asset>.emails" keyfile.
     * This performs any necessary version-dependant migrations and then
     * removes the version key which is used during unserialization only.
     *
     * @param string $emailArraySerialized Serialized email alert settings from <asset>.emails keyfile.
     * @param int $defaultVersion If the file does not contain a version key, assume it is this version.
     * @return string[]|false Array of comma delimited mailing lists or FALSE if an error occurred.
     */
    public function unserializeEmailArray($emailArraySerialized, $defaultVersion = self::VERSION_ONE)
    {
        $emailArray = unserialize($emailArraySerialized, ['allowed_classes' => false]);
        if (is_array($emailArray)) {
            $version = isset($emailArray[self::VERSION_KEY]) ? $emailArray[self::VERSION_KEY] : $defaultVersion;
            // Perform migration for JIRA ticket CP-10946.
            if ($version == self::VERSION_ONE && empty($emailArray['screenshots_success']) && !empty($emailArray['screenshots'])) {
                $emailArray['screenshots_success'] = $emailArray['screenshots'];
            }
            // The version is used for serialization only, and is not part of the returned data.
            unset($emailArray[self::VERSION_KEY]);
        }
        return $emailArray;
    }

    /**
     * @param EmailAddressSettings $emailAddresses object to convert into an array
     * @return string serialized email alert settings (array of comma delimitered mailing lists)
     */
    public function serialize($emailAddresses)
    {
        $emailArrays = array(
            'screenshots' => $emailAddresses->getScreenshotFailed(),
            'weeklys' => $emailAddresses->getWeekly(),
            'critical' => $emailAddresses->getCritical(),
            'missed' => $emailAddresses->getWarning(),
            'notice' => $emailAddresses->getNotice(),
            'logs' => $emailAddresses->getLog(),
            'screenshots_success' => $emailAddresses->getScreenshotSuccess(),
        );

        $serializedEmailAddresses = array_map(function ($mailingList) {
            return implode(',', $mailingList);
        }, $emailArrays);

        return $this->serializeEmailArray($serializedEmailAddresses);
    }

    /**
     * @param string[] $fileArray array of all the asset's serialized settings files
     * @return EmailAddressSettings
     */
    public function unserialize($fileArray)
    {
        if (isset($fileArray['emails'])) {
            $settings = $this->unserializeEmailArray($fileArray['emails']);
            $screenshotsSuccess = isset($settings['screenshots_success']) ? $this->unserializeMailingList($settings['screenshots_success']) : array();

            $emailAddressSettings = new EmailAddressSettings(
                $this->unserializeMailingList($settings['critical']),
                $this->unserializeMailingList($settings['logs']),
                $this->unserializeMailingList($settings['screenshots']), // screenshot failed
                $screenshotsSuccess,
                $this->unserializeMailingList($settings['missed']), // warning
                $this->unserializeMailingList($settings['weeklys']),
                $this->unserializeMailingList($settings['notice'])
            );
        } else {
            $emailAddressSettings = new EmailAddressSettings();
        }

        return $emailAddressSettings;
    }

    private function unserializeMailingList($serializedMailingList): array
    {
        $rawMailingList = explode(",", trim($serializedMailingList));
        $mailingListArray = array();

        foreach ($rawMailingList as $entry) {
            $entry = trim($entry);
            if ($entry !== "") {
                $mailingListArray[] = $entry;
            }
        }

        return $mailingListArray;
    }
}
