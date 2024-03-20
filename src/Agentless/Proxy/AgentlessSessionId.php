<?php

namespace Datto\Agentless\Proxy;

/**
 * Represent an agentless session id.
 * It's composed of two parts, a UUID and a VM-MoRefId.
 * The UUID can either be a vCenter UUID when the connection is established to a vCenter server or
 * an ESX host UUID when the connection is directly established to the host.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class AgentlessSessionId
{
    const UUID_SEPARATOR = '_';

    private string $uuid;
    private string $vmMoRefId;
    private string $assetKeyName;

    private function __construct(string $uuid, string $vmMoRefId, string $assetKeyName)
    {
        $this->uuid = $uuid;
        $this->vmMoRefId = $vmMoRefId;
        $this->assetKeyName = $assetKeyName;
    }

    public static function create(string $uuid, string $vmMoRefId, string $assetKeyName): AgentlessSessionId
    {
        $cleanUuid = strtr($uuid, [self::UUID_SEPARATOR => '', '/' => '']);
        $cleanVmMoRefId = strtr($vmMoRefId, [self::UUID_SEPARATOR => '', '/' => '']);
        $cleanAssetKeyName = strtr($assetKeyName, [self::UUID_SEPARATOR => '']);

        return new AgentlessSessionId($cleanUuid, $cleanVmMoRefId, $cleanAssetKeyName);
    }

    public static function fromString(string $agentlessSessionId): AgentlessSessionId
    {
        $idParts = explode(self::UUID_SEPARATOR, $agentlessSessionId);
        if (count($idParts) !== 3) {
            throw new \Exception("Invalid agentless session id: '$agentlessSessionId'");
        }

        return new AgentlessSessionId($idParts[0], $idParts[1], $idParts[2]);
    }

    /**
     * Returns the directory name of the session id.
     */
    public function toSessionIdName(): string
    {
        return $this->uuid . self::UUID_SEPARATOR . $this->vmMoRefId . self::UUID_SEPARATOR . $this->assetKeyName;
    }

    public function __toString(): string
    {
        return $this->toSessionIdName();
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getVmMoRefId(): string
    {
        return $this->vmMoRefId;
    }

    public function getAssetKeyName(): string
    {
        return $this->assetKeyName;
    }
}
