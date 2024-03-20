<?php

namespace Datto\Config;

use Datto\Agent\PairHandler;
use Datto\Asset\Agent\Serializer\DirectToCloudAgentSettingsSerializer;
use Datto\Asset\Serializer\OriginDeviceSerializer;

/**
 * Access configuration settings of a specific agent
 */
class AgentConfig extends AgentFileConfig
{
    public const BASE_KEY_CONFIG_PATH = '/datto/config/keys';

    /**
     * @return bool True if the asset is a share, otherwise, false.
     */
    public function isShare(): bool
    {
        $agentInfo = unserialize($this->get('agentInfo'), ['allowed_classes' => false]);
        return isset($agentInfo['type']) && $agentInfo['type'] === 'snapnas';
    }

    /**
     * @return string the base of the zfs dataset name
     */
    public function getZfsBase(): string
    {
        return $this->isShare() ? 'homePool/home' : 'homePool/home/agents';
    }

    /**
     * @param string $key
     * @return string
     * // TODO: this function should go away once we eliminate direct access of key files
     */
    public function getConfigFilePath(string $key = null): string
    {
        return $this->getKeyFilePath($key);
    }

    /**
     * Get the agent's unique identifier.
     *
     * @return string|null
     */
    public function getUuid()
    {
        $agentInfo = $this->getAgentInfoOrPairingInfo();
        $uuid = isset($agentInfo['uuid']) ? $agentInfo['uuid'] : null;
        return $uuid;
    }

    /**
     * @return string|null
     */
    public function getName()
    {
        $agentInfo = $this->getAgentInfoOrPairingInfo();
        $name = $agentInfo['name'] ?? $this->agentKey;
        return $name;
    }

    /**
     * Get the FQDN to be used for agent communication.
     *
     * @return string|null
     */
    public function getFullyQualifiedDomainName()
    {
        $agentInfo = $this->getAgentInfoOrPairingInfo();
        if (!empty($agentInfo['fqdn'])) {
            return $agentInfo['fqdn'];
        } elseif (!empty($agentInfo['name'])) {
            return $agentInfo['name'];
        }
        return $this->getKeyName();
    }

    /**
     * Whether or not this AgentConfig represents a rescue agent.
     *
     * @return bool
     */
    public function isRescueAgent(): bool
    {
        return $this->has('rescueAgentSettings');
    }

    public function isArchived(): bool
    {
        return $this->has('archived');
    }

    /**
     * Whether or not this AgentConfig represents a replicated asset.
     *
     * @return bool
     */
    public function isReplicated(): bool
    {
        $originDevice = json_decode($this->get(OriginDeviceSerializer::FILE_KEY), true);
        return $originDevice['isReplicated'] ?? false;
    }

    /**
     * Whether this agent is marked as encrypted
     *
     * @return bool
     */
    public function isEncrypted(): bool
    {
        return $this->has('encryption');
    }

    public function isPaused(): bool
    {
        return $this->has('backupPause');
    }

    public function isShadowsnap(): bool
    {
        return $this->has('shadowSnap');
    }

    public function isDirectToCloud(): bool
    {
        return $this->has(DirectToCloudAgentSettingsSerializer::FILE_KEY);
    }

    /**
     * @return array|null
     */
    public function getAgentInfo()
    {
        $agentInfo = $this->get('agentInfo');
        return $agentInfo ? unserialize($agentInfo, ['allowed_classes' => false]) : null;
    }

    /**
     * The pairing process uses a minimal subset of the agentInfo data. For certain values (uuid and fqdn in
     * particular) it is important to check for the existence of the pairingInfo file and prefer the values contained
     * there if it exists.
     *
     * @return array|null
     */
    public function getAgentInfoOrPairingInfo()
    {
        $agentInfo = $this->get(PairHandler::PAIRING_INFO_KEYFILE);
        if (!$agentInfo) {
            $agentInfo = $this->get('agentInfo');
        }
        return $agentInfo ? unserialize($agentInfo, ['allowed_classes' => false]) : null;
    }

    /**
     * This takes a list of AgentInfo properties to search, along with a term to search those property values.  If
     * any of those values are case insensitively partially matched, this returns true, otherwise false.
     *
     * @param array $properties
     * @param string $searchTerm
     * @return bool
     */
    public function searchAgentInfo(array $properties, string $searchTerm): bool
    {
        $result = [];
        $agentInfo = $this->getAgentInfoOrPairingInfo();
        $agentInfoSubArray = array_intersect_key($agentInfo, array_flip($properties));
        if (!is_null($agentInfoSubArray)) {
            $result = array_filter($agentInfoSubArray, function ($el) use ($searchTerm) {
                return (stripos($el, $searchTerm) !== false);
            });
        }

        return count($result) > 0;
    }
}
