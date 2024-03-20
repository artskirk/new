<?php

namespace Datto\Virtualization\Libvirt\Builders;

use Datto\Virtualization\Libvirt\Domain\NetworkMode;
use Datto\Virtualization\Libvirt\Domain\VmNetworkDefinition;

/**
 * Network interface builder for KVM Hypervisor
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class KvmNetworkInterfacesBuilder extends DefaultNetworkInterfacesBuilder
{
    /**
     * @inheritdoc
     */
    protected function buildNetwork(VmNetworkDefinition $vmNetwork)
    {
        $vmSettings = $this->getContext()->getVmSettings();
        $nicMode = $vmSettings->getNetworkMode();
        $vmNetwork->setInterfaceModel($vmSettings->getNetworkController());
        $vmNetwork->setMacAddress($vmSettings->getMacAddress());

        if ($nicMode === NetworkMode::NAT()) {
            $vmNetwork->setInterfaceType(VmNetworkDefinition::INTERFACE_TYPE_NETWORK);
            $vmNetwork->setSourceNetwork(VmNetworkDefinition::DEFAULT_NETWORK_NAME);
            $vmNetwork->addFilter(VmNetworkDefinition::DEFAULT_NETWORKFILTER_NAME);
        } elseif ($nicMode === NetworkMode::INTERNAL()) {
            $vmNetwork->setInterfaceType(VmNetworkDefinition::INTERFACE_TYPE_NETWORK);
            $vmNetwork->setSourceNetwork(VmNetworkDefinition::ISOLATED_NETWORK_NAME);
        } elseif ($nicMode === NetworkMode::BRIDGED()) {
            $vmNetwork->setInterfaceType(VmNetworkDefinition::INTERFACE_TYPE_BRIDGE);
            $vmNetwork->setSourceBridge($this->getContext()->getVmHostProperties()->getNetworkBridgeInterfaceName());
        } else {
            throw new \RuntimeException("Unsupported network mode '$nicMode'");
        }
    }
}
