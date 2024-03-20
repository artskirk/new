<?php

namespace Datto\Events\Common;

use Datto\Events\AbstractEventNode;

/**
 * Basic details about an asset and its agent
 */
class AssetData extends AbstractEventNode
{
    use RemoveNullProperties;

    /** @var string the asset's key name */
    protected $key;

    /** @var string the agent's hostname */
    protected $hostname;

    /** @var bool TRUE if the asset is encrypted */
    protected $encrypted;

    /** @var string|null type of agent, E.G. "ShadowSnap", "Datto Linux Agent", etc. */
    protected $agentType;

    /** @var string|null version of the agent running on the asset */
    protected $agentVersion;

    /** @var string|null API version used to communicate with the asset */
    protected $apiVersion;

    /** @var string|null driver version used by the asset's agent */
    protected $driverVersion;

    /** @var string|null name of the operating system */
    protected $osName;

    /** @var string|null (optional) snapshot used during the process that generated the event */
    protected $snapshot;

    /**
     * @var string|null (optional) version of the operating system
     *
     * We currently only collect this for Windows; it's NULL for other assets.
     */
    protected $osVersion;

    /** @var string|null share type (shares only) */
    protected $shareType;

    public function __construct(
        string $key,
        string $hostname,
        bool $encrypted,
        string $agentType = null,
        string $agentVersion = null,
        string $apiVersion = null,
        string $driverVersion = null,
        string $osName = null,
        string $snapshot = null,
        string $osVersion = null,
        string $shareType = null
    ) {
        $this->key = $key;
        $this->hostname = $hostname;
        $this->encrypted = $encrypted;
        $this->agentType = $agentType;
        $this->agentVersion = $agentVersion;
        $this->apiVersion = $apiVersion;
        $this->driverVersion = $driverVersion;
        $this->osName = $osName;
        $this->snapshot = $snapshot;
        $this->osVersion = $osVersion;
        $this->shareType = $shareType;
    }
}
