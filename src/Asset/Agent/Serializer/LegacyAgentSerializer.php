<?php

namespace Datto\Asset\Agent\Serializer;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentException;
use Datto\Asset\Agent\Agentless;
use Datto\Asset\Agent\Agentless\Generic\GenericAgentless;
use Datto\Asset\Agent\Agentless\Generic\Serializer\LegacyAgentlessGenericSerializer;
use Datto\Asset\Agent\Agentless\Linux\Serializer\LegacyAgentlessLinuxSerializer;
use Datto\Asset\Agent\Agentless\Windows\Serializer\LegacyAgentlessWindowsSerializer;
use Datto\Asset\Agent\AgentRepository;
use Datto\Asset\AssetType;
use Datto\Asset\Agent\Linux\LinuxAgent;
use Datto\Asset\Agent\Linux\Serializer\LegacyLinuxAgentSerializer;
use Datto\Asset\Agent\Mac\Serializer\LegacyMacAgentSerializer;
use Datto\Asset\Agent\Windows\Serializer\LegacyWindowsAgentSerializer;
use Datto\Asset\Serializer\Serializer;
use Datto\Asset\Agent\Mac\MacAgent;
use Datto\Asset\Agent\Windows\WindowsAgent;

/**
 * Convert an Agent to an array (to be saved in a text file), and vice vera.
 *
 * This class is a generic Agent serializer.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class LegacyAgentSerializer implements Serializer
{
    /** @var Serializer */
    private $linuxSerializer;

    /** @var Serializer */
    private $windowsSerializer;

    /** @var Serializer */
    private $macSerializer;

    /** @var Serializer */
    private $agentlessLinuxSerializer;

    /** @var Serializer */
    private $agentlessWindowsSerializer;

    /** @var Serializer */
    private $agentlessGenericSerializer;

    public function __construct(
        LegacyLinuxAgentSerializer $linuxSerializer = null,
        LegacyWindowsAgentSerializer $windowsSerializer = null,
        LegacyMacAgentSerializer $macSerializer = null,
        LegacyAgentlessLinuxSerializer $agentlessLinuxSerializer = null,
        LegacyAgentlessWindowsSerializer $agentlessWindowsSerializer = null,
        LegacyAgentlessGenericSerializer $agentlessGenericSerializer = null
    ) {
        $this->linuxSerializer = $linuxSerializer ?? new LegacyLinuxAgentSerializer();
        $this->windowsSerializer = $windowsSerializer ?? new LegacyWindowsAgentSerializer();
        $this->macSerializer = $macSerializer ?? new LegacyMacAgentSerializer();
        $this->agentlessLinuxSerializer = $agentlessLinuxSerializer ?? new LegacyAgentlessLinuxSerializer();
        $this->agentlessWindowsSerializer = $agentlessWindowsSerializer ?? new LegacyAgentlessWindowsSerializer();
        $this->agentlessGenericSerializer = $agentlessGenericSerializer ?? new LegacyAgentlessGenericSerializer();
    }

    /**
     * @param Agent $agent Agent to convert to an array
     * @return array Serialized array, containing agents's data
     */
    public function serialize($agent)
    {
        if ($agent instanceof WindowsAgent) {
            return $this->windowsSerializer->serialize($agent);
        }

        if ($agent instanceof LinuxAgent) {
            return $this->linuxSerializer->serialize($agent);
        }

        if ($agent instanceof MacAgent) {
            return $this->macSerializer->serialize($agent);
        }

        if ($agent instanceof Agentless\Windows\WindowsAgent) {
            return $this->agentlessWindowsSerializer->serialize($agent);
        }

        if ($agent instanceof Agentless\Linux\LinuxAgent) {
            return $this->agentlessLinuxSerializer->serialize($agent);
        }

        if ($agent instanceof GenericAgentless) {
            return $this->agentlessGenericSerializer->serialize($agent);
        }

        throw new AgentException('Cannot create serializer for object of type ' . get_class($agent) . '.');
    }

    /**
     * @param array $fileArray Serialized array, containing agents's data
     * @return Agent Agent object based on the array
     */
    public function unserialize($fileArray)
    {
        if (!isset($fileArray[AgentRepository::FILE_EXTENSION])) {
            throw new AgentException('Unable to load agent. Invalid contents.');
        }

        $agentInfo = unserialize($fileArray[AgentRepository::FILE_EXTENSION], ['allowed_classes' => false]);

        if (AssetType::isType(AssetType::AGENTLESS_GENERIC, $agentInfo)) {
            return $this->agentlessGenericSerializer->unserialize($fileArray);
        }

        if (AssetType::isType(AssetType::AGENTLESS_LINUX, $agentInfo)) {
            return $this->agentlessLinuxSerializer->unserialize($fileArray);
        }

        if (AssetType::isType(AssetType::AGENTLESS_WINDOWS, $agentInfo)) {
            return $this->agentlessWindowsSerializer->unserialize($fileArray);
        }

        if (AssetType::isType(AssetType::LINUX_AGENT, $agentInfo)) {
            return $this->linuxSerializer->unserialize($fileArray);
        }

        if (AssetType::isType(AssetType::MAC_AGENT, $agentInfo)) {
            return $this->macSerializer->unserialize($fileArray);
        }

        if (AssetType::isType(AssetType::WINDOWS_AGENT, $agentInfo)) {
            return $this->windowsSerializer->unserialize($fileArray);
        }

        throw new AgentException('Only agents can be unserialized.');
    }
}
