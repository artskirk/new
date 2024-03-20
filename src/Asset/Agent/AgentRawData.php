<?php

namespace Datto\Asset\Agent;

/**
 * Encapsulates the data retrieved and used during an agent data update.
 * @author Devon Welcheck <dwelcheck@datto.com>
 */
class AgentRawData
{
    /** @var string */
    private $assetKey;

    /** @var AgentPlatform */
    private $platform;

    /** @var array */
    private $hostResponse;

    /** @var array */
    private $agentInfo;

    /** @var array */
    private $vssWriters;

    /** @var array */
    private $winData;

    /**
     * @param string $assetKey
     * @param AgentPlatform $platform
     * @param array $hostResponse
     */
    public function __construct(string $assetKey, AgentPlatform $platform, array $hostResponse)
    {
        $this->assetKey = $assetKey;
        $this->platform = $platform;
        $this->hostResponse = $hostResponse;
    }

    /**
     * @return string
     */
    public function getAssetKey(): string
    {
        return $this->assetKey;
    }

    /**
     * @return AgentPlatform
     */
    public function getPlatform(): AgentPlatform
    {
        return $this->platform;
    }

    /**
     * @return array
     */
    public function getHostResponse(): array
    {
        return $this->hostResponse;
    }

    /**
     * @return array
     */
    public function getAgentInfo(): array
    {
        return $this->agentInfo;
    }

    /**
     * @param array $agentInfo
     */
    public function setAgentInfo(array $agentInfo): void
    {
        $this->agentInfo = $agentInfo;
    }

    /**
     * @return array
     */
    public function getVssWriters(): array
    {
        return $this->vssWriters;
    }

    public function hasVssWriters(): bool
    {
        return isset($this->vssWriters);
    }

    /**
     * @param array $vssWriters
     */
    public function setVssWriters(array $vssWriters): void
    {
        $this->vssWriters = $vssWriters;
    }

    /**
     * @return array
     */
    public function getWinData(): array
    {
        return $this->winData;
    }

    public function hasWinData(): bool
    {
        return isset($this->winData);
    }

    /**
     * @param array $winData
     */
    public function setWinData(array $winData): void
    {
        $this->winData = $winData;
    }
}
