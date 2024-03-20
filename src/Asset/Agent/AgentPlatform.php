<?php

namespace Datto\Asset\Agent;

use Eloquent\Enumeration\AbstractValueMultiton;

/**
 * Agent software platform
 *
 * @author Jason Lodice <JLodice@datto.com
 *
 * @method static AgentPlatform SHADOWSNAP()
 * @method static AgentPlatform DATTO_WINDOWS_AGENT()
 * @method static AgentPlatform DATTO_LINUX_AGENT()
 * @method static AgentPlatform DATTO_MAC_AGENT()
 * @method static AgentPlatform AGENTLESS()
 * @method static AgentPlatform AGENTLESS_GENERIC()
 * @method static AgentPlatform DIRECT_TO_CLOUD()
 */
final class AgentPlatform extends AbstractValueMultiton
{
    /** @var string */
    private $shortName;

    /** @var string */
    private $friendlyName;

    /**
     * @param string $key
     * @param string $value
     * @param string $shortName
     * @param string $friendlyName
     */
    protected function __construct(
        string $key,
        string $value,
        string $shortName,
        string $friendlyName
    ) {
        parent::__construct($key, $value);
        $this->shortName = $shortName;
        $this->friendlyName = $friendlyName;
    }

    protected static function initializeMembers()
    {
        new static('SHADOWSNAP', 'ShadowSnap', 'SS', 'ShadowSnap');
        new static('DATTO_WINDOWS_AGENT', 'DattoWindowsAgent', 'DWA', 'Datto Windows Agent');
        new static('DATTO_LINUX_AGENT', 'DattoLinuxAgent', 'DLA', 'Datto Linux Agent');
        new static('DATTO_MAC_AGENT', 'DattoMacAgent', 'DMA', 'Datto Mac Agent');
        new static('AGENTLESS', 'SnapToVM', 'DVA', 'Agentless');
        // FIXME: AGENTLESS_GENERIC does not belong in AgentPlatform since it is not a separate platform.
        // FIXME: It communicates through the same api class as Agentless. Note that this value makes it's way up to
        // FIXME: device-web so we'll want to avoid breaking any functionality that relies on that.
        new static('AGENTLESS_GENERIC', 'AgentlessFullDisk', 'DVAG', 'Generic Agentless');
        new static('DIRECT_TO_CLOUD', 'DirectToCloud', 'DTC', 'Direct To Cloud');
    }

    /**
     * @return string
     */
    public function getShortName(): string
    {
        return $this->shortName;
    }

    /**
     * @return string
     */
    public function getFriendlyName(): string
    {
        return $this->friendlyName;
    }

    /**
     * @return bool
     */
    public function isAgentless(): bool
    {
        return $this === AgentPlatform::AGENTLESS() || $this === AgentPlatform::AGENTLESS_GENERIC();
    }
}
