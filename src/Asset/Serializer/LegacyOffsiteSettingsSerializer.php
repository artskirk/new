<?php

namespace Datto\Asset\Serializer;

use Datto\Asset\OffsiteSettings;

/**
 * Serialize and unserialize a OffsiteSettings object into the legacy
 * key files '.offsiteControl', '.offsiteSchedule', '.offsiteRetention'
 * and '.offsiteRetentionLimits'.
 *
 * Unserializing:
 *   $offsiteSettings = $serializer->unserialize(array(
 *       'offsiteControl' => '{"interval":"-2","latestSnapshot":"0","latestOffsiteSnapshot":1451379337}',
 *       'offsiteRetentionLimits' => '20:200',
 *       'offsiteRetention' => '...', // See LegacyRetentionSerializer
 *       'offsiteSchedule' => '...'   // See LegacyScheduleSerializer
 *   ));
 *
 * Serializing:
 *   $serializedLocalSettings = $serializer->serialize(new OffsiteSettings());
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class LegacyOffsiteSettingsSerializer implements Serializer
{
    /** Number of elements separated by colon (.offsiteRetentionLimits file) */
    const RETENTION_LIMIT_VALUE_COUNT = 2;

    /** Value representing the priority low (using the legacy serialization method) */
    const LEGACY_PRIORITY_LOW = 3;

    /** Value representing the priority normal (using the legacy serialization method) */
    const LEGACY_PRIORITY_NORMAL = 2;

    /** Value representing the priority high (using the legacy serialization method) */
    const LEGACY_PRIORITY_HIGH = 1;

    /** offsite interval code for 'custom' */
    const REPLICATION_CUSTOM = -2;

    /** offsite interval code for 'never' */
    const REPLICATION_NEVER = -1;

    /** offsite interval code for 'always' */
    const REPLICATION_ALWAYS = 0;

    /** @var LegacyScheduleSerializer */
    private $scheduleSerializer;

    /** @var LegacyRetentionSerializer */
    private $retentionSerializer;

    /** @var LegacyRecoveryPointsSerializer */
    private $recoveryPointsSerializer;

    /** @var OffsiteMetricsSerializer */
    private $offsiteMetricsSerializer;

    /**
     * Create a offsite settings serializer
     *
     * @param Serializer $scheduleSerializer Serializer to use for the offsite schedule
     * @param Serializer $retentionSerializer Serializer to use for the offsite retention settings
     * @param Serializer $recoveryPointsSerializer Serializer to use for the recovery points list
     * @param Serializer $offsiteMetricsSerializer Serializer to use for offsite metrics
     */
    public function __construct(
        Serializer $scheduleSerializer = null,
        Serializer $retentionSerializer = null,
        Serializer $recoveryPointsSerializer = null,
        Serializer $offsiteMetricsSerializer = null
    ) {
        $this->scheduleSerializer = ($scheduleSerializer) ?: new LegacyScheduleSerializer();
        $this->retentionSerializer = ($retentionSerializer) ?: new LegacyRetentionSerializer();
        $this->recoveryPointsSerializer = ($recoveryPointsSerializer) ?: new LegacyRecoveryPointsSerializer();
        $this->offsiteMetricsSerializer = ($offsiteMetricsSerializer) ?: new OffsiteMetricsSerializer();
    }

    /**
     * Serializes a OffsiteSettings object into an array of string representing the matching config files
     * '.offsiteControl', '.offsiteRetentionLimits', '.offsiteSchedule', '.offSitePoints', and '.offsiteRetention'.
     * Typo in offSitePoints is intentional!
     *
     * @param OffsiteSettings $offsite
     * @return array
     */
    public function serialize($offsite)
    {
        $fileArray = array();

        $fileArray['offsiteControl'] = json_encode(array(
            'interval' => strval($this->serializeReplication($offsite)), // Old key file store this as string!
            'priority' => strval($this->serializePriority($offsite))
        ));

        $fileArray['offsiteRetentionLimits'] = implode(':', array(
            $offsite->getOnDemandRetentionLimit(),
            $offsite->getNightlyRetentionLimit()
        ));

        $fileArray['offsiteSchedule'] = $this->scheduleSerializer->serialize($offsite->getSchedule());
        $fileArray['offsiteRetention'] = $this->retentionSerializer->serialize($offsite->getRetention());
        $fileArray['offSitePoints'] = $this->recoveryPointsSerializer->serialize($offsite->getRecoveryPoints());
        $fileArray['offSitePointsCache'] = $this->recoveryPointsSerializer->serialize($offsite->getRecoveryPointsCache());
        $fileArray['offsiteMetrics'] = $this->offsiteMetricsSerializer->serialize($offsite->getOffsiteMetrics());

        return $fileArray;
    }

    /**
     * @param OffsiteSettings $offsite
     * @return int
     */
    private function serializeReplication(OffsiteSettings $offsite)
    {
        $replication = $offsite->getReplication();

        switch ($replication) {
            case OffsiteSettings::REPLICATION_CUSTOM:
                return self::REPLICATION_CUSTOM;
            case OffsiteSettings::REPLICATION_NEVER:
                return self::REPLICATION_NEVER;
            case OffsiteSettings::REPLICATION_ALWAYS:
                return self::REPLICATION_ALWAYS;
            default:
                return intval($replication);
        }
    }

    /**
     * @param OffsiteSettings $priority
     * @return int
     */
    private function serializePriority(OffsiteSettings $offsite)
    {
        $priority = $offsite->getPriority();

        if ($priority) {
            if ($priority === OffsiteSettings::LOW_PRIORITY) {
                return self::LEGACY_PRIORITY_LOW;
            } elseif ($priority === OffsiteSettings::HIGH_PRIORITY) {
                return self::LEGACY_PRIORITY_HIGH;
            }
        }

        return self::LEGACY_PRIORITY_NORMAL;
    }

    /**
     * Unserializes an array of the file contents of '.offsiteControl', '.offsiteRetentionLimits',
     * '.offsiteSchedule', and '.offsiteRetention' into an OffsiteSettings object.
     *
     * @param array $fileArray Array of strings containing the file contents of above listed files.
     * @return OffsiteSettings
     */
    public function unserialize($fileArray)
    {
        $offsiteControlArr = isset($fileArray['offsiteControl']) ? @json_decode($fileArray['offsiteControl'], true) : array();
        $retentionLimitsArr = isset($fileArray['offsiteRetentionLimits']) ? explode(':', $fileArray['offsiteRetentionLimits']) : array();

        $priority = $this->unserializePriority($offsiteControlArr);
        $onDemandRetentionLimit = $this->unserializeOnDemandRetentionLimit($retentionLimitsArr);
        $nightlyRetentionLimit = $this->unserializeNightlyRetentionLimit($retentionLimitsArr);
        $replication = $this->unserializeReplication($offsiteControlArr);

        $retention = $this->retentionSerializer->unserialize(@$fileArray['offsiteRetention']);
        $schedule = $this->scheduleSerializer->unserialize(@$fileArray['offsiteSchedule']);
        $recoveryPoints = $this->recoveryPointsSerializer->unserialize(@$fileArray['offSitePoints']);
        $recoveryPointsCache = $this->recoveryPointsSerializer->unserialize(@$fileArray['offSitePointsCache']);
        $offsiteMetrics = $this->offsiteMetricsSerializer->unserialize(@$fileArray['offsiteMetrics']);

        return new OffsiteSettings(
            $priority,
            $onDemandRetentionLimit,
            $nightlyRetentionLimit,
            $replication,
            $retention,
            $schedule,
            $recoveryPoints,
            $recoveryPointsCache,
            $offsiteMetrics
        );
    }

    /**
     * Read the priority value from the .offsiteControl file
     *
     * Note:
     *    The logic is slightly more complicated than it should be,
     *    because we stored the offsite priority for the SIRIS NAS shares
     *    as the strings 'low', 'normal', 'high' instead of using the
     *    same values as the old code did. So here, we're mapping both
     *    old and new values.
     */
    private function unserializePriority($offsiteControlArr): string
    {
        $priority = OffsiteSettings::MEDIUM_PRIORITY;

        if (isset($offsiteControlArr['priority'])) {
            if (is_numeric($offsiteControlArr['priority'])) {
                $intPriority = intval($offsiteControlArr['priority']);
                if ($intPriority === self::LEGACY_PRIORITY_LOW) {
                    $priority = OffsiteSettings::LOW_PRIORITY;
                } elseif ($intPriority === self::LEGACY_PRIORITY_HIGH) {
                    $priority = OffsiteSettings::HIGH_PRIORITY;
                }
            } else {
                $strPriority = strval($offsiteControlArr['priority']);
                $validPriority = in_array($strPriority, array(OffsiteSettings::LOW_PRIORITY, OffsiteSettings::MEDIUM_PRIORITY, OffsiteSettings::HIGH_PRIORITY));

                if ($validPriority) {
                    $priority = $strPriority;
                }
            }
        }

        return $priority;
    }

    /**
     * Read the replication interval value from the .offsiteControl file
     */
    private function unserializeReplication($offsiteControlArr)
    {
        if (isset($offsiteControlArr['interval'])) {
            $replication = $offsiteControlArr['interval'];
            switch ($replication) {
                case self::REPLICATION_CUSTOM:
                    return OffsiteSettings::REPLICATION_CUSTOM;
                case self::REPLICATION_NEVER:
                    return OffsiteSettings::REPLICATION_NEVER;
                case self::REPLICATION_ALWAYS:
                    return OffsiteSettings::REPLICATION_ALWAYS;
                default:
                    return intval($replication);
            }
        }

        return OffsiteSettings::DEFAULT_REPLICATION;
    }

    /**
     * Read the on demand retention limit value from the .offsiteRetentionLimits file
     */
    private function unserializeOnDemandRetentionLimit($retentionLimitsArr): int
    {
        $onDemandRetentionLimit = OffsiteSettings::DEFAULT_ON_DEMAND_RETENTION_LIMIT;

        if (is_array($retentionLimitsArr) && count($retentionLimitsArr) === self::RETENTION_LIMIT_VALUE_COUNT) {
            $onDemandRetentionLimit = intval($retentionLimitsArr[0]);
        }

        return $onDemandRetentionLimit;
    }

    /**
     * Read the nightly retention limit value from the .offsiteRetentionLimits file
     */
    private function unserializeNightlyRetentionLimit($retentionLimitsArr): int
    {
        $nightlyRetentionLimit = OffsiteSettings::DEFAULT_NIGHTLY_RETENTION_LIMIT;

        if (is_array($retentionLimitsArr) && count($retentionLimitsArr) === self::RETENTION_LIMIT_VALUE_COUNT) {
            $nightlyRetentionLimit = intval($retentionLimitsArr[1]);
        }

        return $nightlyRetentionLimit;
    }
}
