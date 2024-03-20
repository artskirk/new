<?php

namespace Datto\Asset\Agent\Job\Serializer;

use Datto\Asset\Agent\Job\JobList;
use Datto\Asset\Serializer\Serializer;
use Exception;

/**
 * Serializer class for JobList.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class AgentJobListSerializer implements Serializer
{

    /** @var BackupJobStatusSerializer */
    private $agentJobSerializer;

    /**
     * AgentJobListSerializer constructor.
     * @param $agentJobSerializer
     */
    public function __construct($agentJobSerializer = null)
    {
        $this->agentJobSerializer = $agentJobSerializer ?: new BackupJobStatusSerializer();
    }

    /**
     * Serialize the given object into an array (not a string!).
     *
     * @param JobList $object object to convert into an array
     * @return array Serialized object
     */
    public function serialize($object)
    {
        $serializedJobs = array();

        foreach ($object->getJobs() as $job) {
            $serializedJobs[] = $this->agentJobSerializer->serialize($job);
        }

        return array(
            "type" => $object->getType(),
            "jobs" => $serializedJobs
        );
    }

    /**
     * Create an object from the given array.
     *
     * @param array $serializedObject Serialized object
     * @return JobList object built with the array's data
     */
    public function unserialize($serializedObject)
    {
        throw new Exception('Not implemented');
    }
}
