<?php

namespace Datto\Asset\Agent\Backup\Serializer;

use Datto\Asset\Agent\Agentless\Generic\Serializer\LegacyAgentlessGenericOperatingSystemSerializer;
use Datto\Asset\Agent\Agentless\Linux\Serializer\LegacyAgentlessLinuxOperatingSystemSerializer;
use Datto\Asset\Agent\Agentless\Windows\Serializer\LegacyAgentlessWindowsOperatingSystemSerializer;
use Datto\Asset\Agent\Linux\Serializer\LegacyLinuxOperatingSystemSerializer;
use Datto\Asset\Agent\Mac\Serializer\LegacyMacOperatingSystemSerializer;
use Datto\Asset\Agent\Windows\Serializer\LegacyWindowsOperatingSystemSerializer;
use Datto\Asset\AssetType;
use Datto\Asset\Serializer\Serializer;
use Exception;

/**
 * Leverages serializers to translate from file format to object
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class OperatingSystemSerializer implements Serializer
{
    /** @var LegacyWindowsOperatingSystemSerializer */
    private $windowsSerializer;

    /** @var LegacyAgentlessWindowsOperatingSystemSerializer */
    private $agentlessWindowsSerializer;

    /** @var LegacyLinuxOperatingSystemSerializer */
    private $linuxSerializer;

    /** @var LegacyAgentlessLinuxOperatingSystemSerializer */
    private $agentlessLinuxSerializer;

    /** @var LegacyMacOperatingSystemSerializer */
    private $macSerializer;

    /** @var LegacyAgentlessGenericOperatingSystemSerializer */
    private $agentlessGenericSerializer;

    /**
     * @param LegacyWindowsOperatingSystemSerializer|null $windowsSerializer
     * @param LegacyAgentlessWindowsOperatingSystemSerializer|null $agentlessWindowsSerializer
     * @param LegacyLinuxOperatingSystemSerializer|null $linuxSerializer
     * @param LegacyAgentlessLinuxOperatingSystemSerializer|null $agentlessLinuxSerializer
     * @param LegacyMacOperatingSystemSerializer|null $macSerializer
     * @param LegacyAgentlessGenericOperatingSystemSerializer|null $agentlessGenericSerializer
     */
    public function __construct(
        LegacyWindowsOperatingSystemSerializer $windowsSerializer = null,
        LegacyAgentlessWindowsOperatingSystemSerializer $agentlessWindowsSerializer = null,
        LegacyLinuxOperatingSystemSerializer $linuxSerializer = null,
        LegacyAgentlessLinuxOperatingSystemSerializer $agentlessLinuxSerializer = null,
        LegacyMacOperatingSystemSerializer $macSerializer = null,
        LegacyAgentlessGenericOperatingSystemSerializer $agentlessGenericSerializer = null
    ) {
        $this->windowsSerializer = $windowsSerializer ?: new LegacyWindowsOperatingSystemSerializer();
        $this->agentlessWindowsSerializer = $agentlessWindowsSerializer ?: new LegacyAgentlessWindowsOperatingSystemSerializer();
        $this->linuxSerializer = $linuxSerializer ?: new LegacyLinuxOperatingSystemSerializer();
        $this->agentlessLinuxSerializer = $agentlessLinuxSerializer ?: new LegacyAgentlessLinuxOperatingSystemSerializer();
        $this->macSerializer = $macSerializer ?: new LegacyMacOperatingSystemSerializer();
        $this->agentlessGenericSerializer = $agentlessGenericSerializer ?: new LegacyAgentlessGenericOperatingSystemSerializer();
    }

    /**
     * @inheritdoc
     */
    public function serialize($operatingSystem)
    {
        return array(
            'os' => $operatingSystem->getName() . ' ' . $operatingSystem->getVersion(),
            'os_name' => $operatingSystem->getName(),
            'os_version' => $operatingSystem->getVersion(),
            'os_arch' => $operatingSystem->getArchitecture(),
            'os_servicepack' => $operatingSystem->getServicePack(),
            'arch' => strval($operatingSystem->getBits()),
            'archBits' => $operatingSystem->getBits() . 'bits',
            'kernel' => $operatingSystem->getKernel(),
        );
    }

    /**
     * @inheritdoc
     */
    public function unserialize($agentInfo)
    {
        $agentInfo = unserialize($agentInfo, ['allowed_classes' => false]);

        if (AssetType::isType(AssetType::WINDOWS_AGENT, $agentInfo)) {
            return $this->windowsSerializer->unserialize($agentInfo);
        }

        if (AssetType::isType(AssetType::AGENTLESS_WINDOWS, $agentInfo)) {
            return $this->agentlessWindowsSerializer->unserialize($agentInfo);
        }

        if (AssetType::isType(AssetType::LINUX_AGENT, $agentInfo)) {
            return $this->linuxSerializer->unserialize($agentInfo);
        }

        if (AssetType::isType(AssetType::AGENTLESS_LINUX, $agentInfo)) {
            return $this->agentlessLinuxSerializer->unserialize($agentInfo);
        }

        if (AssetType::isType(AssetType::MAC_AGENT, $agentInfo)) {
            return $this->macSerializer->unserialize($agentInfo);
        }

        if (AssetType::isType(AssetType::AGENTLESS_GENERIC, $agentInfo)) {
            return $this->agentlessGenericSerializer->unserialize($agentInfo);
        }

        throw new Exception('Cannot detect operating system');
    }
}
