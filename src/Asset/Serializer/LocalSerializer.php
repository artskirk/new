<?php

namespace Datto\Asset\Serializer;

use Datto\Asset\LocalSettings;
use Datto\Asset\Retention;

/**
 * Serializer to convert between a LocalSettings object,
 * and a serializable array.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class LocalSerializer implements Serializer
{
    /** Version of this serializer */
    const SERIALIZER_VERSION = 1;

    /** @var WeeklyScheduleSerializer */
    private $scheduleSerializer;

    /**
     * Create a local settings serializer.
     * @param Serializer|WeeklyScheduleSerializer|null $scheduleSerializer
     */
    public function __construct(Serializer $scheduleSerializer = null)
    {
        $this->scheduleSerializer = $scheduleSerializer ?: new WeeklyScheduleSerializer();
    }

    /**
     * Serialize a LocalSettings object into an array.
     *
     * @param LocalSettings $local object to serialize
     * @return array Array representing the object
     */
    public function serialize($local)
    {
        return array(
            'version' => self::SERIALIZER_VERSION,
            'paused' => $local->isPaused(),
            'pauseUntil' => $local->getPauseUntil(),
            'pauseWhileMetered' => $local->isPauseWhileMetered(),
            'maximumBandwidthInBits' => $local->getMaximumBandwidthInBits(),
            'maximumThrottledBandwidthInBits' => $local->getMaximumThrottledBandwidthInBits(),
            'interval' => $local->getInterval(),
            'timeout' => $local->getTimeout(),
            'ransomwareCheckEnabled' => $local->isRansomwareCheckEnabled(),
            'ransomwareSuspensionEndTime' => $local->getRansomwareSuspensionEndTime(),
            'schedule' => $this->scheduleSerializer->serialize($local->getSchedule()),
            'retention' => array(
                'daily' => $local->getRetention()->getDaily(),
                'weekly' => $local->getRetention()->getWeekly(),
                'monthly' => $local->getRetention()->getMonthly(),
                'maximum' => $local->getRetention()->getMaximum(),
            ),
            'archived' => $local->isArchived(),
            'integrityCheckEnabled' => $local->isIntegrityCheckEnabled(),
            'expediteMigration' => $local->isMigrationExpedited()
        );
    }

    /**
     * Create a LocalSettings object from the given array.
     *
     * @param array $fileArray array to convert to an object
     * @return LocalSettings object loaded with data from the array
     */
    public function unserialize($fileArray)
    {
        $name = $this->getAssetName($fileArray['agentInfo']);
        $paused = (isset($fileArray['paused'])) ? $fileArray['paused'] : LocalSettings::DEFAULT_PAUSE;
        $pauseUntil = ($paused && isset($fileArray['pauseUntil'])) ? $fileArray['pauseUntil'] : LocalSettings::DEFAULT_PAUSE_UNTIL;
        $interval = (isset($fileArray['interval'])) ? intval(trim($fileArray['interval'])) : LocalSettings::DEFAULT_INTERVAL;
        $timeout = (isset($fileArray['timeout'])) ? intval(trim($fileArray['timeout'])) : LocalSettings::DEFAULT_TIMEOUT;
        $schedule = (isset($fileArray['schedule'])) ? $this->unserializeSchedule($fileArray['schedule']) : null;
        $retention = (isset($fileArray['retention'])) ? $this->unserializeRetention($fileArray['retention']) : null;
        $ransomwareCheckEnabled = (isset($fileArray['ransomwareCheckEnabled'])) ? $fileArray['ransomwareCheckEnabled'] : LocalSettings::DEFAULT_RANSOMWARE_ENABLED;
        $ransomwareSuspensionEndTime = (isset($fileArray['ransomwareSuspensionEndTime'])) ? $fileArray['ransomwareSuspensionEndTime'] : LocalSettings::DEFAULT_RANSOMWARE_SUSPENSION_END_TIME;
        $archived = (isset($fileArray['archived'])) ? $fileArray['archived'] : LocalSettings::DEFAULT_ARCHIVED;
        $integrityCheckEnabled = $fileArray['integrityCheckEnabled'] ?? LocalSettings::DEFAULT_INTEGRITY_CHECK_ENABLED;

        return new LocalSettings(
            $name,
            $paused,
            $interval,
            $timeout,
            $ransomwareCheckEnabled,
            $ransomwareSuspensionEndTime,
            $schedule,
            $retention,
            null,
            $archived,
            $integrityCheckEnabled,
            $pauseUntil
        );
    }

    private function getAssetName($agentInfoString)
    {
        $agentInfo = unserialize($agentInfoString, ['allowed_classes' => false]);
        return $agentInfo['name'];
    }

    /**
     * Convert a serialized retention array into an object.
     *
     * @param array $serializedRetention
     * @return \Datto\Asset\Retention
     */
    private function unserializeRetention(array $serializedRetention)
    {
        return new Retention(
            $serializedRetention['daily'],
            $serializedRetention['weekly'],
            $serializedRetention['monthly'],
            $serializedRetention['maximum']
        );
    }

    /**
     * Convert a serialized schedule array into an object.
     *
     * @param array $serializedSchedule
     * @return \Datto\Core\Asset\Configuration\WeeklySchedule
     */
    private function unserializeSchedule($serializedSchedule)
    {
        return $this->scheduleSerializer->unserialize($serializedSchedule);
    }
}
