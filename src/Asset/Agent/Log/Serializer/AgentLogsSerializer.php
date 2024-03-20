<?php

namespace Datto\Asset\Agent\Log\Serializer;

use Datto\Asset\Agent\Log\AgentLog;
use Datto\Asset\Serializer\Serializer;
use Exception;

/**
 * Serializer class for AgentLogs
 *
 * @author Mario Rial <mrial@datto.com>
 */
class AgentLogsSerializer implements Serializer
{

    /**
     * Serialize the given object into an array (not a string!).
     *
     * @param AgentLog $object object to convert into an array
     * @return array Serialized object
     */
    public function serialize($object)
    {
        if (!$object instanceof AgentLog) {
            throw new Exception('Not implemented');
        }

        return array(
            "timestamp" => $object->getTimestamp(),
            "code" => $object->getCode(),
            "message" => $object->getMessage(),
            "severity" => $object->getSeverity()
        );
    }

    /**
     * @param mixed $serializedObject
     * @return mixed|void
     */
    public function unserialize($serializedObject)
    {
        throw new Exception('Not implemented');
    }
}
