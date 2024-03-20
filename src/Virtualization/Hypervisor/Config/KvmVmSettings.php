<?php
namespace Datto\Virtualization\Hypervisor\Config;

use Datto\Virtualization\Libvirt\Domain\VmVideoDefinition;

/**
 * Manages virtualization settings for QEMU/KVM hypervisors.
 *
 * @author Dawid Zamirski <dzamirski@datto.com>
 *
 * @see AbstractVmSettings
 */
class KvmVmSettings extends AbstractVmSettings
{
    protected function loadDefaults(): void
    {
        $this
            ->setStorageController('virtio')
            ->setNetworkController('virtio')
            ->setNetworkMode('NONE')
            ->setVideoController(VmVideoDefinition::MODEL_VGA);
    }

    protected function getType(): string
    {
        return 'kvm';
    }

    public function getSupportedNetworkControllers(): array
    {
        return ['e1000', 'rtl8139', 'virtio'];
    }
    
    public function getSupportedStorageControllers(): array
    {
        return ['ide', 'sata', 'scsi', 'virtio'];
    }

    public function getSupportedVideoControllers(): array
    {
        return [VmVideoDefinition::MODEL_VGA, VmVideoDefinition::MODEL_CIRRUS];
    }
}
