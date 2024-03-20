<?php

namespace Datto\Asset\Serializer;

use Datto\Asset\Retention;

/**
 * Serialize and unserialize the ".retention" and ".offsiteRetention" file into
 * a Retention object. The serialized object is a colon separated string of the
 * 4 values for daily, weekly, monthly and maximum.
 *
 * Unserializing:
 *   $retention = $serializer->unserialize('10:20:30:40');
 *
 * Serializing:
 *   $serializedRetention = $serializer->serialize(new Retention());
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class LegacyRetentionSerializer implements Serializer
{
    /** Number of elements separated by colon (.offsiteRetention file) */
    const RETENTION_VALUE_COUNT = 4;

    /**
     * Serializes a Retention object into a string
     *
     * @param Retention $retention
     * @return string Serialized string representing the retention object, e.g. 10:20:30:40
     */
    public function serialize($retention)
    {
        return implode(':', array(
            $retention->getDaily(),
            $retention->getWeekly(),
            $retention->getMonthly(),
            $retention->getMaximum()
        ));
    }

    /**
     * Serialize a string into a Retention object, or return a default retention
     * if an invalid string is passed.
     *
     * @param string $serializedRetention Serialized string representing the retention object, e.g. 10:20:30:40
     * @return Retention
     */
    public function unserialize($serializedRetention)
    {
        if ($serializedRetention && is_string($serializedRetention)) {
            $rawRetention = explode(':', $serializedRetention);

            if (count($rawRetention) === self::RETENTION_VALUE_COUNT) {
                return new Retention($rawRetention[0], $rawRetention[1], $rawRetention[2], $rawRetention[3]);
            }
        }

        return Retention::createDefault();
    }
}
