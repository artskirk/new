<?php

namespace Datto\Restore\Export\Serializers;

use Datto\Asset\Serializer\Serializer;
use Datto\ImageExport\Status;

/**
 * Export status serializer.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class StatusSerializer implements Serializer
{
    /**
     * Serialize the given object into an array (not a string!).
     *
     * @param Status $object object to convert into an array
     * @return array Serialized object
     */
    public function serialize($object)
    {
        return array(
            'exporting' => $object->isExporting()
        );
    }

    /**
     * Create an object from the given array.
     *
     * @param array $serializedObject Serialized object
     * @return Status object built with the array's data
     */
    public function unserialize($serializedObject)
    {
        $exporting = $serializedObject['exporting'];

        return new Status($exporting);
    }
}
