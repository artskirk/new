<?php

namespace Datto\Asset\Agent\Agentless\Serializer;

use Datto\Asset\Agent\DriverSettings;
use Datto\Asset\Serializer\Serializer;

/**
 * Serializes driver part of the agentInfo file for agentless systems.
 *
 * Unserializing:
 *   $driverSettings = $serializer->unserialize(array(
 *       'apiVersion' => '3.3.0',
 *       'version' => '3.3.0',
 *       'agentVersion' => '5.0.1.23057',
 *       'agentSerialNumber' => 'F888-E976-8322-CFC5',
 *       'agentActivated' => 1,
 *       'stcDriverLoaded' => 1
 *   ));
 *
 * Serializing:
 *   $serializedLocalSettings = $serializer->serialize(new DriverSettings());
 *
 * @author John Roland <jroland@datto.com>
 */
class LegacyAgentlessDriverSettingsSerializer implements Serializer
{
    /**
     * @param DriverSettings $driver
     * @return array
     */
    public function serialize($driver)
    {
        if ($driver) {
            $array = array(
                'apiVersion' => $driver->getApiVersion(),
                'agentVersion' => $driver->getAgentVersion(),
                'version' => $driver->getDriverVersion()
            );

            if (!is_null($driver->getSerialNumber())) {
                $array['agentSerialNumber'] = $driver->getSerialNumber();
            }

            if (!is_null($driver->isActivated())) {
                // Agentless info files expect an int instead of bool
                $array['agentActivated'] = intval($driver->isActivated());
            }

            if (!is_null($driver->isStcDriverLoaded())) {
                // Agentless info files expect an int instead of bool
                $array['stcDriverLoaded'] = intval($driver->isStcDriverLoaded());
            }

            return $array;
        } else {
            return array();
        }
    }

    /**
     * @param array $agentInfo
     * @return DriverSettings
     */
    public function unserialize($agentInfo)
    {
        $apiVersion = isset($agentInfo['apiVersion']) ? $agentInfo['apiVersion'] : null;
        $agentVersion = isset($agentInfo['agentVersion']) ? $agentInfo['agentVersion'] : null;
        $driverVersion = isset($agentInfo['version']) ? $agentInfo['version'] : null;
        $agentSerialNumber = isset($agentInfo['agentSerialNumber']) ? $agentInfo['agentSerialNumber'] : null;
        $agentActivated = isset($agentInfo['agentActivated']) ? (bool)$agentInfo['agentActivated'] : null;
        $stcDriverLoaded = isset($agentInfo['stcDriverLoaded']) ? (bool)$agentInfo['stcDriverLoaded'] : null;

        return new DriverSettings(
            $apiVersion,
            $agentVersion,
            $driverVersion,
            $agentSerialNumber,
            $agentActivated,
            $stcDriverLoaded
        );
    }
}
