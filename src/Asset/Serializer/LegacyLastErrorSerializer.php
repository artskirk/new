<?php

namespace Datto\Asset\Serializer;

use Datto\Asset\LastErrorAlert;

/**
 * Serializer for .lastError file
 *
 * @author Rixhers Ajazi <rajazi@datto.com>
 */
class LegacyLastErrorSerializer implements Serializer
{
    private const EXPECTED_ELEMENTS = 10;

    /**
     * @param LastErrorAlert|mixed $lastErrorAlert
     */
    public function serialize($lastErrorAlert): ?string
    {
        if (!($lastErrorAlert instanceof LastErrorAlert)) {
            return null;
        }

        return serialize([
            'hostname' => $lastErrorAlert->getHostname(),
            'deviceID' => $lastErrorAlert->getDeviceId(),
            'time' => $lastErrorAlert->getTime(),
            'agentData' => $lastErrorAlert->getAgentData(),
            'code' => $lastErrorAlert->getCode(),
            'errorTime' => $lastErrorAlert->getErrorTime(),
            'message' => $lastErrorAlert->getMessage(),
            'type' => $lastErrorAlert->getType(),
            'log' => $lastErrorAlert->getLog(),
            'context' => $lastErrorAlert->getContext()
        ]);
    }

    public function unserialize($serializedLastAlert): ?LastErrorAlert
    {
        $data = unserialize($serializedLastAlert, ['allowed_classes' => false]);

        if (!$data || count($data) !== self::EXPECTED_ELEMENTS) {
            return null;
        }

        return new LastErrorAlert(
            $data['hostname'],
            $data['deviceID'],
            $data['time'],
            $data['agentData'],
            $data['code'],
            $data['errorTime'],
            $data['message'],
            $data['type'],
            $data['log'],
            $data['context']
        );
    }
}
