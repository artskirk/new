<?php

namespace Datto\Asset\Agent\Job\Serializer;

use Datto\Asset\Agent\Job\BackupJobVolumeDetails;
use Datto\Asset\Serializer\Serializer;
use Exception;

/**
 * Serializer class for job BackupJobVolumeDetails.
 * @author Mario Rial <mrial@datto.com>
 */
class BackupJobVolumeDetailsSerializer implements Serializer
{

    /**
     * Serialize the given object into an array (not a string!).
     *
     * @param BackupJobVolumeDetails $object object to convert into an array
     * @return array Serialized object
     */
    public function serialize($object)
    {
        return array(
            "dateTime" => $object->getDateTime(),
            "status" => $object->getStatus(),
            "volumeGuid" => $object->getVolumeGuid(),
            "volumeType" => $object->getVolumeType(),
            "volumeMountPoint" => $object->getVolumeMountPoint(),
            "filesystemType" => $object->getFilesystemType(),
            "bytesTotal" => $object->getBytesTotal(),
            "bytesSent" => $object->getBytesSent(),
            "spaceTotal" => $object->getSpaceTotal(),
            "spaceFree" => $object->getSpaceFree(),
            "spaceUsed" => $object->getSpaceUsed()
        );
    }

    /**
     * Create an object from the given array.
     *
     * @param array $serializedObject Serialized object
     * @return BackupJobVolumeDetails object built with the array's data
     */
    public function unserialize($serializedObject)
    {
        throw new Exception('Not implemented');
    }
}
