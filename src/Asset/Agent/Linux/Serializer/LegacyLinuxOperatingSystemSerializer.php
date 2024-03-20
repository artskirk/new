<?php

namespace Datto\Asset\Agent\Linux\Serializer;

use Datto\Asset\Agent\OperatingSystem;
use Datto\Asset\Serializer\Serializer;
use Datto\Util\OsFamily;

/**
 * Serializes operating system part of the agentInfo file for agent based Linux systems.
 *
 * @author John Roland <jroland@datto.com>
 */
class LegacyLinuxOperatingSystemSerializer implements Serializer
{
    /**
     * @param OperatingSystem $operatingSystem
     * @return array
     */
    public function serialize($operatingSystem)
    {
        return array(
            'os' => $operatingSystem->getName(),
            'os_name' => $operatingSystem->getName(),
            'arch' => $operatingSystem->getBits(),
            'kernel' => $operatingSystem->getKernel()
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

        return new OperatingSystem(OsFamily::LINUX(), $name, $version, $architecture, $bits, $servicePack, $kernel);
    }
}
