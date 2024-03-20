<?php

namespace Datto\Virtualization\Libvirt\Builders;

use Datto\Virtualization\Libvirt\Domain\NetworkMode;
use Datto\Virtualization\Libvirt\Domain\VmDefinition;
use Datto\Virtualization\Libvirt\Domain\VmNetworkDefinition;

/**
 * Default network interface builder
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class DefaultNetworkInterfacesBuilder extends BaseVmDefinitionBuilder
{
    /**
     * @inheritdoc
     */
    public function build(VmDefinition $vmDefinition)
    {
        $vmSettings = $this->getContext()->getVmSettings();

        // only add a device if the mode is not NONE
        if ($vmSettings->getNetworkMode() !== NetworkMode::NONE()) {
            $vmNetwork = new VmNetworkDefinition();
            $this->buildNetwork($vmNetwork);
            $vmDefinition->getNetworkInterfaces()->append($vmNetwork);
        }
    }

    /**
     * Build a network instance
     *
     * @param VmNetworkDefinition $vmNetwork
     */
    protected function buildNetwork(VmNetworkDefinition $vmNetwork)
    {
        $vmSettings = $this->getContext()->getVmSettings();
        $nicMode = $vmSettings->getNetworkMode();

        // we only support bridged mode
        if ($nicMode !== NetworkMode::BRIDGED()) {
            throw new \RuntimeException("Unsupported network mode '$nicMode'");
        }

        $vmNetwork->setInterfaceType(VmNetworkDefinition::INTERFACE_TYPE_BRIDGE);
        $vmNetwork->setSourceBridge($vmSettings->getBridgeTarget());
        $vmNetwork->setInterfaceModel($this->getInterfaceModel());
    }

    /**
     * Get the network interface model
     *
     * @return string
     */
    protected function getInterfaceModel(): string
    {
        return $this->getContext()->getVmSettings()->getNetworkController();
    }
}
