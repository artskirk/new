<?php

namespace Datto\System\Update\Serializer;

use Datto\Asset\Serializer\Serializer;
use Datto\System\Update\HourlyUpdateWindow;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class HourlyUpdateWindowSerializer implements Serializer
{
    const VERSION = 1;
    const DEFAULT_START_HOUR = 21; // 9 PM
    const DEFAULT_END_HOUR = 6; // 6 AM

    /**
     * Serialize the given object into an array (not a string!).
     *
     * @param mixed $object object to convert into an array
     * @return mixed Serialized object
     */
    public function serialize($object)
    {
        return array(
            'version' => self::VERSION,
            'start' => $object->getStartHour(),
            'end' => $object->getEndHour()
        );
    }

    /**
     * Create an object from the given array.
     *
     * @param mixed $serializedObject Serialized object
     * @return mixed object built with the array's data
     */
    public function unserialize($serializedObject)
    {
        return new HourlyUpdateWindow(
            $serializedObject['start'] ?? self::DEFAULT_START_HOUR,
            $serializedObject['end'] ?? self::DEFAULT_END_HOUR
        );
    }
}
