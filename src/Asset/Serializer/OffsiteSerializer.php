<?php

namespace Datto\Asset\Serializer;

use Datto\Asset\OffsiteSettings;
use Datto\Asset\Retention;
use Datto\Core\Asset\Configuration\WeeklySchedule;

/**
 * Serializer to convert between a OffsiteSettings object,
 * and a serializable array.
 *
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class OffsiteSerializer implements Serializer
{
    /** Version of this serializer */
    const SERIALIZER_VERSION = 1;

    /** @var WeeklyScheduleSerializer */
    private $scheduleSerializer;

    /**
     * Create a offsite settings serializer.
     * @param WeeklyScheduleSerializer|null $scheduleSerializer
     */
    public function __construct(WeeklyScheduleSerializer $scheduleSerializer = null)
    {
        $this->scheduleSerializer = $scheduleSerializer ?: new WeeklyScheduleSerializer();
    }

    /**
     * Serialize an OffsiteSettings object into an array.
     *
     * @param OffsiteSettings $offsite object to serialize
     * @return array Array representing the object
     */
    public function serialize($offsite)
    {
        return array(
            'version' => self::SERIALIZER_VERSION,
            'nightlyRetentionLimit' => $offsite->getNightlyRetentionLimit(),
            'onDemandRetentionLimit' => $offsite->getOnDemandRetentionLimit(),
            'priority' => $offsite->getPriority(),
            'replication' => $offsite->getReplication(),
            'schedule' => $this->scheduleSerializer->serialize($offsite->getSchedule()),
            'retention' => array(
                'daily' => $offsite->getRetention()->getDaily(),
                'weekly' => $offsite->getRetention()->getWeekly(),
                'monthly' => $offsite->getRetention()->getMonthly(),
                'maximum' => $offsite->getRetention()->getMaximum(),
            ),
        );
    }

    /**
     * Create an OffsiteSettings object from the given array.
     *
     * @param array $fileArray array to convert to an object
     * @return OffsiteSettings object loaded with data from the array
     */
    public function unserialize($fileArray)
    {
        $priority = @$fileArray['priority'] ?: OffsiteSettings::DEFAULT_PRIORITY;
        $onDemandLimit = @$fileArray['onDemandRetentionLimit'] ?: OffsiteSettings::DEFAULT_ON_DEMAND_RETENTION_LIMIT;
        $nightlyLimit = @$fileArray['nightlyRetentionLimit'] ?: OffsiteSettings::DEFAULT_NIGHTLY_RETENTION_LIMIT;
        $replication = @$fileArray['replication'] ?: OffsiteSettings::DEFAULT_REPLICATION;
        $retention = $this->unserializeRetention(@$fileArray['retention']);
        $schedule = $this->unserializeSchedule(@$fileArray['schedule']);
        
        return new OffsiteSettings($priority, $onDemandLimit, $nightlyLimit, $replication, $retention, $schedule);
    }

    /**
     * Convert a serialized retention array into an object.
     *
     * @param array $serializedRetention
     * @return \Datto\Asset\Retention
     */
    private function unserializeRetention($serializedRetention)
    {
        if (is_array($serializedRetention) && isset($serializedRetention['daily']) && isset($serializedRetention['weekly'])
            && isset($serializedRetention['monthly']) && isset($serializedRetention['maximum'])) {
            return new Retention(
                $serializedRetention['daily'],
                $serializedRetention['weekly'],
                $serializedRetention['monthly'],
                $serializedRetention['maximum']
            );
        } else {
            return Retention::createDefault();
        }
    }

    /**
     * Convert a serialized schedule array into an object.
     *
     * @param array $serializedSchedule
     * @return \Datto\Core\Asset\Configuration\WeeklySchedule
     */
    private function unserializeSchedule($serializedSchedule)
    {
        if (isset($serializedSchedule)) {
            return $this->scheduleSerializer->unserialize($serializedSchedule);
        } else {
            return new WeeklySchedule();
        }
    }
}
