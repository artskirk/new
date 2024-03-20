<?php

namespace Datto\Asset\Agent\Serializer;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Volume;
use Datto\Asset\AssetType;
use Datto\Asset\ScriptSettings;
use Datto\Asset\Serializer\LocalSerializer;
use Datto\Asset\Serializer\OffsiteSerializer;
use Datto\Asset\Serializer\ScriptSettingsSerializer;
use Datto\Asset\Serializer\Serializer;
use Exception;

/**
 * Serialize and unserialize an Agent object into an array.
 *
 * Unserializing:
 *   $agent = $serializer->unserialize(array(
 *       'name' => 'DATTO-PC',
 *       'hostname' => 'datto-pc.datto.lan',
 *       // A lot more ...
 *   ));
 *
 * Serializing:
 *   $serializedAgent = $serializer->serialize(new WindowsAgent(..));
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class AgentSerializer implements Serializer
{
    /** @var Serializer LocalSettings serializer */
    private $localSerializer;

    /** @var Serializer OffsiteSettings serializer */
    private $offsiteSerializer;

    /** @var LegacyDriverSettingsSerializer */
    private $driverSettingsSerializer;

    /**
     * @param Serializer|null $localSerializer
     * @param Serializer|null $offsiteSerializer
     * @param LegacyDriverSettingsSerializer|null $driverSettingsSerializer
     */
    public function __construct(
        Serializer $localSerializer = null,
        Serializer $offsiteSerializer = null,
        LegacyDriverSettingsSerializer $driverSettingsSerializer = null
    ) {
        $this->localSerializer = $localSerializer ?: new LocalSerializer();
        $this->offsiteSerializer = $offsiteSerializer ?: new OffsiteSerializer();
        $this->driverSettingsSerializer = $driverSettingsSerializer ?: new LegacyDriverSettingsSerializer();
    }

    /**
     * Serialize an agent into an array of file contents.
     *
     * @param Agent $agent
     * @return array
     */
    public function serialize($agent)
    {
        return [
            'name' => $agent->getName(),
            'uuid' => $agent->getUuid(),
            'fqdn' => $agent->getFullyQualifiedDomainName(),
            'keyName' => $agent->getKeyName(),
            'displayName' => $agent->getDisplayName(),
            'type' => $agent->getType(),
            'generated' => $agent->getDateAdded(),
            'cpuCount' => $agent->getCpuCount(),
            'memory' => $agent->getMemory(),
            'isRescueAgent' => $agent->isRescueAgent(),
            'isDirectToCloudAgent' => $agent->isDirectToCloudAgent(),
            'operatingSystem' => [
                'name' => $agent->getOperatingSystem()->getName(),
                'version' => $agent->getOperatingSystem()->getVersion(),
                'architecture' => $agent->getOperatingSystem()->getArchitecture(),
                'bits' => $agent->getOperatingSystem()->getBits(),
                'servicePack' => $agent->getOperatingSystem()->getServicePack(),
            ],
            'version' => $agent->getDriver()->getAgentVersion(),
            'serial' => $agent->getDriver()->getSerialNumber(),
            'hostname' => $agent->getHostname(),
            'local' => $this->localSerializer->serialize($agent->getLocal()),
            'offsite' => $this->offsiteSerializer->serialize($agent->getOffsite()),
            'scriptSettings' => [
                ScriptSettingsSerializer::SCRIPTS => $agent->getScriptSettings()->getScriptFilePaths()
            ],
            'driver' => $this->driverSettingsSerializer->serialize($agent->getDriver()),
            'encrypted' => $agent->getEncryption()->isEnabled(),
            'localUsed' => $agent->getUsedLocally()
        ];
    }

    /**
     * Unserializes the agent from an array.
     *
     * @param array $serializedAgent
     * @return Agent Instance of a WindowsAgent or LinuxAgent object
     */
    public function unserialize($serializedAgent)
    {
        throw new Exception('Not implemented');
    }
}
