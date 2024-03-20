<?php

namespace Datto\Asset\Agent\Agentless\Windows\Serializer;

use Datto\Asset\Agent\OperatingSystem;
use Datto\Asset\Serializer\Serializer;
use Datto\Util\OsFamily;

/**
 * Serializes operating system part of the agentInfo file for agentless Windows systems.
 *
 * @author John Roland <jroland@datto.com>
 */
class LegacyAgentlessWindowsOperatingSystemSerializer implements Serializer
{
    /**
     * @param OperatingSystem $operatingSystem
     * @return array
     */
    public function serialize($operatingSystem)
    {
        return array(
            'os_name' => $operatingSystem->getName(),
            'os_version' => $operatingSystem->getVersion(),
            'os_arch' => $operatingSystem->getArchitecture(),
            'os_servicepack' => $operatingSystem->getServicePack(),
            'arch' => strval($operatingSystem->getBits()),
            'archBits' => $operatingSystem->getBits() . 'bits',
        );
    }

    /**
     * @param string[] $agentInfo
     * @return OperatingSystem
     */
    public function unserialize($agentInfo)
    {
        $name = isset($agentInfo['os_name']) ? $agentInfo['os_name'] : null;
        $version = isset($agentInfo['os_version']) ? $agentInfo['os_version'] : null;
        $architecture = isset($agentInfo['os_arch']) ? $agentInfo['os_arch'] : null;
        $servicePack = isset($agentInfo['os_servicepack']) ? $agentInfo['os_servicepack'] : null;
        $bits = isset($agentInfo['arch']) ? intval($agentInfo['arch']) : null;
        $kernel = isset($agentInfo['kernel']) ? $agentInfo['kernel'] : null;

        return new OperatingSystem(OsFamily::WINDOWS(), $name, $version, $architecture, $bits, $servicePack, $kernel);
    }
}
