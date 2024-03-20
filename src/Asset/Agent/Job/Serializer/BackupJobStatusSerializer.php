<?php

namespace Datto\Asset\Agent\Job\Serializer;

use Datto\Asset\Agent\Job\BackupJobStatus;
use Datto\Asset\Serializer\Serializer;
use Exception;

/**
 * Serializer class for AgentJob
 *
 * @author Mario Rial <mrial@datto.com>
 */
class BackupJobStatusSerializer implements Serializer
{
    /** @var BackupJobVolumeDetailsSerializer */
    private $agentJobDetailSerializer;

    public function __construct($agentJobDetailSerializer = null)
    {
        $this->agentJobDetailSerializer = $agentJobDetailSerializer ?: new BackupJobVolumeDetailsSerializer();
    }

    /**
     * Serialize the given object into an array (not a string!).
     *
     * @param BackupJobStatus $object object to convert into an array
     * @return mixed Serialized object
     */
    public function serialize($object)
    {
        $serializedJobDetails = array();

        foreach ($object->getVolumeGuids() as $volumeGuid) {
            $volumeDetails = $object->getVolumeDetails($volumeGuid);
            $serializedJobDetails[] = $this->agentJobDetailSerializer->serialize($volumeDetails);
        }

        return array(
            "id" => $object->getJobID(),
            "type" => $object->getTransferState(),
            "status" => $object->getTransferResult(),
            "details" => $serializedJobDetails
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
        throw new Exception('Not implemented');
    }
}
