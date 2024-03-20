<?php

namespace Datto\Virtualization\Libvirt\Builders;

use ArrayObject;
use Datto\Config\Virtualization\VirtualDisk;
use Datto\Virtualization\Libvirt\Domain\VmDefinition;
use Datto\Virtualization\Libvirt\Domain\VmDiskDefinition;

/**
 * Disk builder for Hyper-V Hypervisor
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class HyperVDiskDevicesBuilder extends BaseDiskDevicesBuilder
{
    /**
     * @inheritdoc
     */
    public function build(VmDefinition $vmDefinition)
    {
        parent::build($vmDefinition);
        $disks = $vmDefinition->getDiskDevices();
        $settings = $this->getContext()->getVmSettings();

        // Hyper-v VMs with a large number of disks need two storage controllers,
        // IDE for the boot + os, and scsi for the rest. This is mostly due to libvirt
        // only supporting Gen1 hyper-v VMs AND the fact that gen1 only supports booting off
        // IDE controllers. "Auto" was created to accommodate this.
        if ($settings->getStorageController() === VmDiskDefinition::TARGET_BUS_AUTO) {
            for ($i = 0; $i < min($disks->count(), 2); $i++) {
                /** @var VmDiskDefinition */
                $diskDefinition = $disks->offsetGet($i);
                $diskDefinition->setTargetBus(VmDiskDefinition::TARGET_BUS_IDE);
                $diskDefinition->setTargetDevice($this->getDiskDeviceName(VmDiskDefinition::TARGET_BUS_IDE, $i));
            }

            for ($i = 2; $i < $disks->count(); $i++) {
                /** @var VmDiskDefinition */
                $diskDefinition = $disks->offsetGet($i);
                $diskDefinition->setTargetBus(VmDiskDefinition::TARGET_BUS_SCSI);

                $diskDefinition->setTargetDevice(
                    $this->getDiskDeviceName(
                        VmDiskDefinition::TARGET_BUS_SCSI,
                        $i - 2 // Restart SCSI disk labels at 'a'
                    )
                );
            }
        }
    }

    /**
     * @inheritdoc
     */
    protected function buildDisk(int $diskIndex, VirtualDisk $sourceDisk, VmDiskDefinition $targetVmDisk)
    {
        $targetVmDisk->setDiskType(VmDiskDefinition::DISK_TYPE_BLOCK);
        $targetVmDisk->setSourcePath($sourceDisk->getRawFileName());
    }
}
