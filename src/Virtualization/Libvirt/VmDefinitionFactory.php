<?php

namespace Datto\Virtualization\Libvirt;

use Datto\Connection\ConnectionType;
use Datto\Virtualization\Libvirt\Builders\BaseVmDefinitionBuilder;
use Datto\Virtualization\Libvirt\Builders\DefaultNetworkInterfacesBuilder;
use Datto\Virtualization\Libvirt\Builders\EsxDiskDevicesBuilder;
use Datto\Virtualization\Libvirt\Builders\EsxNetworkInterfacesBuilder;
use Datto\Virtualization\Libvirt\Builders\EsxSerialPortBuilder;
use Datto\Virtualization\Libvirt\Builders\EsxVideoBuilder;
use Datto\Virtualization\Libvirt\Builders\EsxVmxMachineBuilder;
use Datto\Virtualization\Libvirt\Builders\HyperVDiskDevicesBuilder;
use Datto\Virtualization\Libvirt\Builders\KvmCpuBuilder;
use Datto\Virtualization\Libvirt\Builders\KvmDiskDevicesBuilder;
use Datto\Virtualization\Libvirt\Builders\KvmNetworkInterfacesBuilder;
use Datto\Virtualization\Libvirt\Builders\KvmSerialPortBuilder;
use Datto\Virtualization\Libvirt\Builders\KvmVideoBuilder;
use Datto\Virtualization\Libvirt\Builders\MachineBuilder;
use Datto\Virtualization\Libvirt\Builders\StorageControllersBuilder;
use Datto\Virtualization\Libvirt\Builders\UsbTabletInputBuilder;
use Datto\Virtualization\Libvirt\Builders\VncGraphicsBuilder;
use Datto\Virtualization\Libvirt\Domain\VmDefinition;
use RuntimeException;

/**
 * Builds a Libvirt VmDefinition instance.
 *
 * This class should only use data provided in the VmDefinitionContext,
 * and should not modify the state of the system at all.
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class VmDefinitionFactory
{
    /**
     * Create a new VmDefintion instance with the given context.
     *
     * @param VmDefinitionContext $context
     * @return VmDefinition
     */
    public function create(VmDefinitionContext $context): VmDefinition
    {
        $vmDefinition = new VmDefinition();

        foreach ($this->getBuilders($context) as $builder) {
            $builder->build($vmDefinition);
        }

        return $vmDefinition;
    }

    /**
     * Create list of builders based on the given context
     *
     * @param $context
     * @return BaseVmDefinitionBuilder[]
     */
    private function getBuilders(VmDefinitionContext $context): array
    {
        $vmHostProperties = $context->getVmHostProperties();
        switch ($vmHostProperties->getConnectionType()) {
            case ConnectionType::LIBVIRT_KVM():
                return $this->getKvmBuilders($context);
            case ConnectionType::LIBVIRT_ESX():
                return $this->getEsxBuilders($context);
            case ConnectionType::LIBVIRT_HV():
                return $this->getHyperVBuilders($context);
            default:
                throw new RuntimeException("Libvirt connection type '{$vmHostProperties->getConnectionType()}' is unsupported.");
        }
    }

    /**
     * @param VmDefinitionContext $context
     * @return BaseVmDefinitionBuilder[]
     */
    private function getKvmBuilders(VmDefinitionContext $context): array
    {
        $builders = [
            new MachineBuilder($context),
            new KvmCpuBuilder($context),
            new KvmDiskDevicesBuilder($context),
            new KvmNetworkInterfacesBuilder($context),
            new KvmVideoBuilder($context),
            new VncGraphicsBuilder($context),
            new StorageControllersBuilder($context),
            new UsbTabletInputBuilder($context)
        ];

        if ($context->isSerialPortRequired()) {
            $builders[] = new KvmSerialPortBuilder($context);
        }

        return $builders;
    }

    /**
     * @param VmDefinitionContext $context
     * @return BaseVmDefinitionBuilder[]
     */
    private function getEsxBuilders(VmDefinitionContext $context): array
    {
        $builders =  [
            new MachineBuilder($context),
            new EsxDiskDevicesBuilder($context),
            new EsxNetworkInterfacesBuilder($context),
            new EsxVideoBuilder($context),
            new StorageControllersBuilder($context),
        ];

        if ($context->hasNativeConfiguration()) {
            $builders = [
                new EsxVmxMachineBuilder($context),
                new EsxDiskDevicesBuilder($context),
            ];
        }

        if ($context->supportsVnc()) {
            $builders[] = new VncGraphicsBuilder($context);
        }

        if ($context->isSerialPortRequired()) {
            $builders[] = new EsxSerialPortBuilder($context);
        }

        return $builders;
    }

    /**
     * @param VmDefinitionContext $context
     * @return BaseVmDefinitionBuilder[]
     */
    private function getHyperVBuilders(VmDefinitionContext $context): array
    {
        return [
            new MachineBuilder($context),
            new HyperVDiskDevicesBuilder($context),
            new DefaultNetworkInterfacesBuilder($context),
            new StorageControllersBuilder($context),
        ];
    }
}
