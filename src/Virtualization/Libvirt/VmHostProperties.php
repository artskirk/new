<?php

namespace Datto\Virtualization\Libvirt;

use Datto\Connection\ConnectionType;

/**
 * Properties which describe capabilities of a VM Host
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class VmHostProperties
{
    /** @var ConnectionType */
    private $connectionType;

    /** @var string */
    private $host = '';

    /** @var string */
    private $libvirtCpuModel = '';

    /** @var string */
    private $localCpuModel = '';

    /** @var string */
    private $networkBridgeInterfaceName = '';

    /**
     * @param ConnectionType $connectionType
     * @param string $libvirtCpuModel
     */
    public function __construct(ConnectionType $connectionType, string $libvirtCpuModel)
    {
        $this->connectionType = $connectionType;
        $this->libvirtCpuModel = $libvirtCpuModel;
    }

    /**
     * @return ConnectionType
     */
    public function getConnectionType(): ConnectionType
    {
        return $this->connectionType;
    }

    /**
     * @return string host name or ip address
     */
    public function getHost(): string
    {
        return $this->host;
    }

    public function setHost(string $host)
    {
        $this->host = $host;
    }

    /**
     * @return string cpu model assigned by libvirt for this host
     */
    public function getLibvirtCpuModel(): string
    {
        return $this->libvirtCpuModel;
    }

    /**
     * @return string optional cpu model returned by linux (only used for KVM)
     */
    public function getLocalCpuModel(): string
    {
        return $this->localCpuModel;
    }

    public function setLocalCpuModel(string $localCpuModel)
    {
        $this->localCpuModel = $localCpuModel;
    }

    /**
     * @return string optional bridge interface name (only used for KVM)
     */
    public function getNetworkBridgeInterfaceName(): string
    {
        return $this->networkBridgeInterfaceName;
    }

    public function setNetworkBridgeInterfaceName(string $bridgeInterface)
    {
        $this->networkBridgeInterfaceName = $bridgeInterface;
    }
}
