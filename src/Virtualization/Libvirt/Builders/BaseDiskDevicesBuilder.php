<?php

namespace Datto\Virtualization\Libvirt\Builders;

use ArrayObject;
use Datto\Config\Virtualization\VirtualDisk;
use Datto\Virtualization\Libvirt\Domain\VmDefinition;
use Datto\Virtualization\Libvirt\Domain\VmDiskDefinition;

/**
 * Base class for hypervisor specific disk builders
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
abstract class BaseDiskDevicesBuilder extends BaseVmDefinitionBuilder
{
    /**
     * @inheritdoc
     */
    public function build(VmDefinition $vmDefinition)
    {
        $disks = $this->getContext()->getDisks();

        $vmDisks = new ArrayObject();

        for ($i = 0; $i < $disks->count(); $i++) {
            $vmDisk = new VmDiskDefinition();
            $vmDisk->setDiskDevice(VmDiskDefinition::DISK_DEVICE_DISK);
            $vmDisk->setDiskType(VmDiskDefinition::DISK_TYPE_FILE);

            $targetBus = $this->getTargetBus();
            $vmDisk->setTargetBus($targetBus);

            /** @var VirtualDisk */
            $virtualDisk = $disks->offsetGet($i);

            $this->buildDisk($i, $virtualDisk, $vmDisk);

            // makes 'sda', 'sdb', 'sdc' etc for with each loop iteration.
            // FIXME: IDE supports 2 drives per bus
            // https://github.com/libvirt/libvirt/blob/2e7965735a11db0a5cb67a872583ec4ec2ea67dd/src/vmx/vmx.c#L140
            $vmDisk->setTargetDevice($this->getDiskDeviceName($targetBus, $i));
            $vmDisks->append($vmDisk);
        }

        $vmDefinition->setDiskDevices($vmDisks);
    }

    /**
     * Build a disk instance
     *
     * @param int $diskIndex
     * @param VirtualDisk $sourceDisk
     * @param VmDiskDefinition $targetVmDisk
     * @return mixed
     */
    abstract protected function buildDisk(int $diskIndex, VirtualDisk $sourceDisk, VmDiskDefinition $targetVmDisk);

    /**
     * Determine the target bus for the disks
     *
     * @return string
     */
    protected function getTargetBus(): string
    {
        $vmSettings = $this->getContext()->getVmSettings();

        if ($vmSettings->isSata()) {
            return VmDiskDefinition::TARGET_BUS_SATA;
        } elseif ($vmSettings->isIde()) {
            return VmDiskDefinition::TARGET_BUS_IDE;
        } elseif ($vmSettings->isScsi()) {
            return VmDiskDefinition::TARGET_BUS_SCSI;
        } elseif ($vmSettings->getStorageController() === VmDiskDefinition::TARGET_BUS_VIRTIO) {
            return VmDiskDefinition::TARGET_BUS_VIRTIO;
        } else {
            return VmDiskDefinition::TARGET_BUS_SCSI;
        }
    }

    protected function getDiskDeviceName(string $targetBus, int $deviceIndex): string
    {
        $prefix = $targetBus === VmDiskDefinition::TARGET_BUS_IDE ? 'hd' : 'sd';

        return $prefix . chr(($deviceIndex % 26) + 97);
    }
}
