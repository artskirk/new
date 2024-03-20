<?php

namespace Datto\Asset\Serializer;

use Datto\Asset\LocalSettings;
use Datto\Asset\RecoveryPoint\RecoveryPoints;

/**
 * Serialize and unserialize a LocalSettings object into the legacy
 * key files '.interval', '.snapTimeout', '.backupPause', '.schedule' and '.retention'.
 *
 * Unserializing:
 *   $localSettings = $serializer->unserialize(array(
 *       'interval' => '60',
 *       'timeout' => '900',
 *       'backupPause' => '',
 *       'schedule' => '...',  // See LegacyScheduleSerializer
 *       'retention' => '...', // See LegacyRetentionSerializer
 *   ));
 *
 * Serializing:
 *   $serializedLocalSettings = $serializer->serialize(new LocalSettings());
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class LegacyLocalSettingsSerializer implements Serializer
{
    /** Number of seconds in one minute */
    const MINUTE_IN_SECONDS = 60;

    /** Default backup interval in minutes */
    const DEFAULT_INTERVAL_IN_MINUTES = 60;

    /** Default backup timeout in seconds */
    const DEFAULT_TIMEOUT_IN_SECONDS = 900;

    /** @var LegacyScheduleSerializer */
    private $scheduleSerializer;

    /** @var LegacyRetentionSerializer */
    private $retentionSerializer;

    /** @var LegacyRecoveryPointsSerializer */
    private $recoveryPointsSerializer;

    /** @var LegacyRecoveryPointsMetaSerializer */
    private $recoveryPointsMetaSerializer;

    /**
     * Create a local settings serializer
     *
     * @param Serializer $scheduleSerializer Serializer to use for the backup schedule
     * @param Serializer $retentionSerializer Serializer to use for the retention settings
     * @param Serializer $recoveryPointsSerializer Serializer to use for the recovery points list
     * @param Serializer $recoveryPointsMetaSerializer Serializer to use for the recovery points list
     */
    public function __construct(
        Serializer $scheduleSerializer = null,
        Serializer $retentionSerializer = null,
        Serializer $recoveryPointsSerializer = null,
        Serializer $recoveryPointsMetaSerializer = null
    ) {
        $this->scheduleSerializer = ($scheduleSerializer) ?: new LegacyScheduleSerializer();
        $this->retentionSerializer = ($retentionSerializer) ?: new LegacyRetentionSerializer();
        $this->recoveryPointsSerializer = ($recoveryPointsSerializer) ?: new LegacyRecoveryPointsSerializer();
        $this->recoveryPointsMetaSerializer = ($recoveryPointsMetaSerializer) ?:
            new LegacyRecoveryPointsMetaSerializer();
    }

    /**
     * Serializes a LocalSettings object into an array of string representing the matching config files
     * '.interval', '.snapTimeout', '.backupPause', '.schedule', '.retention', and 'recoveryPoints'.
     *
     * @param LocalSettings $local
     * @return array
     */
    public function serialize($local)
    {
        $fileArray = [];
        $fileArray['archived'] = ($local->isArchived()) ? '1' : null;
        $fileArray['backupPause'] = ($local->isPaused()) ? '1' : null;
        $fileArray['interval'] = (string)$local->getInterval();
        $fileArray['snapTimeout'] = $local->getTimeout();
        $fileArray['ransomwareCheckEnabled'] = $local->isRansomwareCheckEnabled();
        $fileArray['ransomwareSuspensionEndTime'] = $local->getRansomwareSuspensionEndTime();
        $fileArray['integrityCheckEnabled'] = $local->isIntegrityCheckEnabled() ? '1' : null;
        $fileArray['schedule'] = $this->scheduleSerializer->serialize($local->getSchedule());
        $fileArray['retention'] = $this->retentionSerializer->serialize($local->getRetention());
        $fileArray['recoveryPoints'] = $this->recoveryPointsSerializer->serialize($local->getRecoveryPoints());
        $fileArray['recoveryPointsMeta'] = $this->recoveryPointsMetaSerializer->serialize($local->getRecoveryPoints());
        $fileArray['backupPauseUntil'] = ($local->isPaused()) ? $local->getPauseUntil() : LocalSettings::DEFAULT_PAUSE_UNTIL;
        $fileArray['backupPauseWhileMetered'] = $local->isPauseWhileMetered() ? '1' : null;
        $fileArray['backupMaximumBandwidthInBits'] = $local->getMaximumBandwidthInBits();
        $fileArray['backupMaximumThrottledBandwidthInBits'] = $local->getMaximumThrottledBandwidthInBits();
        $fileArray['lastCheckin'] = $local->getLastCheckin();
        $fileArray['expediteMigration'] = $local->isMigrationExpedited() ? '1' : null;
        $fileArray['migrationInProgress'] = $local->isMigrationInProgress() ? '1' : null;

        return $fileArray;
    }

    /**
     * Unserializes an array of the file contents of '.interval', '.snapTimeout', '.backupPause',
     * '.schedule', and '.retention' into a LocalSettings object.
     *
     * @param array $fileArray Array of strings containing the file contents of above listed files.
     * @return LocalSettings
     */
    public function unserialize($fileArray)
    {
        $keyName = $fileArray['keyName'];
        $interval = isset($fileArray['interval']) ?
            intval(trim($fileArray['interval'])) :
            self::DEFAULT_INTERVAL_IN_MINUTES;
        $timeout = isset($fileArray['snapTimeout']) ?
            intval(trim($fileArray['snapTimeout'])) :
            self::DEFAULT_TIMEOUT_IN_SECONDS;
        $paused = isset($fileArray['backupPause']);
        $pauseUntil = ($paused && isset($fileArray['backupPauseUntil'])) ? $fileArray['backupPauseUntil'] : null;
        $pauseWhileMetered = $fileArray['backupPauseWhileMetered'] ?? LocalSettings::DEFAULT_PAUSE_WHILE_METERED;
        $maximumBandwidthInBits = isset($fileArray['backupMaximumBandwidthInBits']) ?
            (int)$fileArray['backupMaximumBandwidthInBits'] : null;
        $maximumThrottledBandwidthInBits = isset($fileArray['backupMaximumThrottledBandwidthInBits']) ?
            (int)$fileArray['backupMaximumThrottledBandwidthInBits'] : null;
        $archived = isset($fileArray['archived']);
        $ransomwareCheckEnabled = isset($fileArray['ransomwareCheckEnabled']) ?
            boolval(trim($fileArray['ransomwareCheckEnabled'])) : LocalSettings::DEFAULT_RANSOMWARE_ENABLED;
        $ransomwareSuspensionEndTime = isset($fileArray['ransomwareSuspensionEndTime']) ?
            intval(trim($fileArray['ransomwareSuspensionEndTime'])) :
            LocalSettings::DEFAULT_RANSOMWARE_SUSPENSION_END_TIME;
        $integrityCheckEnabled = boolval($fileArray['integrityCheckEnabled'] ?? false);
        $lastCheckin = isset($fileArray['lastCheckin']) ? (int)$fileArray['lastCheckin'] : null;
        $expediteMigration = isset($fileArray['expediteMigration']);
        $isMigrationInProgress = isset($fileArray['migrationInProgress']);

        $schedule = $this->scheduleSerializer->unserialize(@$fileArray['schedule']);
        $retention = $this->retentionSerializer->unserialize(@$fileArray['retention']);
        $recoveryPointEpochs = $this->recoveryPointsSerializer->unserialize(@$fileArray['recoveryPoints']);
        $recoveryPointMetas = $this->recoveryPointsMetaSerializer->unserialize($fileArray);
        $recoveryPoints = $this->mergeRecoveryArrays(@$recoveryPointEpochs, @$recoveryPointMetas);

        return new LocalSettings(
            $keyName,
            $paused,
            $interval,
            $timeout,
            $ransomwareCheckEnabled,
            $ransomwareSuspensionEndTime,
            $schedule,
            $retention,
            $recoveryPoints,
            $archived,
            $integrityCheckEnabled,
            $pauseUntil,
            $pauseWhileMetered,
            $lastCheckin,
            $maximumBandwidthInBits,
            $maximumThrottledBandwidthInBits,
            $expediteMigration,
            $isMigrationInProgress
        );
    }

    /**
     * @param RecoveryPoints
     * @param RecoveryPoints
     * @return RecoveryPoints
     */
    private function mergeRecoveryArrays($recoveryPointEpochs, $recoveryPointMetas): RecoveryPoints
    {
        /** @var  RecoveryPoints $recoveryPoints */
        /** @var  \Datto\Asset\RecoveryPoint\RecoveryPoints $recoveryPointEpochs */
        /** @var  \Datto\Asset\RecoveryPoint\RecoveryPoints $recoveryPointMetas */
        $recoveryPoints = new RecoveryPoints();
        $pointsEpoch = $recoveryPointEpochs->getAll();
        $pointsMeta = $recoveryPointMetas->getAll();
        $pointsDeleted = $recoveryPointMetas->getDeleted();
        foreach ($pointsEpoch as $epoch => $recoveryPoint) {
            if (array_key_exists($epoch, $pointsMeta)) {
                $recoveryPoints->add($pointsMeta[$epoch]);
            } elseif (array_key_exists($epoch, $pointsDeleted)) {
                $recoveryPoints->add($pointsDeleted[$epoch]);
            } else {
                $recoveryPoints->add($recoveryPoint);
            }
        }

        foreach ($pointsDeleted as $deleted) {
            if (!$recoveryPoints->exists($deleted->getEpoch())) {
                $recoveryPoints->add($deleted);
            }
        }

        return $recoveryPoints;
    }
}
