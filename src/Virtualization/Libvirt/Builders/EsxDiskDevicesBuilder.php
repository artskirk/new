<?php

namespace Datto\Virtualization\Libvirt\Builders;

use ArrayObject;
use Datto\Asset\Agent\Backup\DiskDrive;
use Datto\Config\Virtualization\VirtualDisk;
use Datto\Virtualization\Libvirt\Domain\VmDefinition;
use Datto\Virtualization\Libvirt\Domain\VmDiskDefinition;

/**
 * Disk builder for ESX Hypervisor
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class EsxDiskDevicesBuilder extends BaseDiskDevicesBuilder
{
    /**
     * @param VmDefinition $vmDefinition
     */
    public function build(VmDefinition $vmDefinition)
    {
        $vmDisks = $this->getContext()->getAgentSnapshot()->getDiskDrives() ?? [];
        $disks = $vmDefinition->getDiskDevices();

        $canUseNative = count($disks) > 0 && count($vmDisks) > 0;
        $wantsNative = $this->getContext()->hasNativeConfiguration();

        if ($canUseNative && $wantsNative) {
            $this->buildFromNative($vmDisks, $disks);
        } else {
            parent::build($vmDefinition);
        }
    }

    /**
     * @inheritdoc
     */
    protected function buildDisk(int $diskIndex, VirtualDisk $sourceDisk, VmDiskDefinition $targetVmDisk)
    {
        $targetVmDisk->setSourcePath(sprintf(
            '%s/%s',
            $sourceDisk->getStorageLocation(),
            $sourceDisk->getVmdkFileName()
        ));
    }

    /**
     * @inheritdoc
     */
    protected function getTargetBus(): string
    {
        $targetBus = parent::getTargetBus();

        // only override if the user has not explicitly configured
        if (!$this->getContext()->getVmSettings()->isUserDefined()
            && $this->isLegacyWindowsOs() && $targetBus === VmDiskDefinition::TARGET_BUS_SCSI) {
            // SCSI not supported for legacy windows on ESX;
            // Note: ESX loads an IDE storage controller by default, so we don't need to add it
            $targetBus = VmDiskDefinition::TARGET_BUS_IDE;
        }

        return $targetBus;
    }

    /**
     * @param VmDefinition $vmDefinition
     * @param DiskDrive[] $vmDisks
     * @param ArrayObject $disks
     */
    private function buildFromNative(array $vmDisks, ArrayObject $disks)
    {
        $os2Disks = $this->getContext()->getDisks();

        $diskCount = $disks->count();
        for ($i = 0; $i < $diskCount; $i++) {
            /** @var VmDiskDefinition */
            $disk = $disks[$i];

            // TODO: We backup only disk devices - skip CDROM and FLOPPY which
            // reside on the "source" ESX datastore. In theory we could attach
            // them if we verify the that datastore path is accessible on the
            // "restore" host or at least attach those in "ejected" state, if
            // such thing would work..
            if ($disk->getDiskDevice() !== VmDiskDefinition::DISK_DEVICE_DISK) {
                $disks->offsetUnset($i);
                continue;
            }

            // Match vmdks listed in .vmx against .datto and adjust paths only
            // so they point at device storage. Keep order, bus, controler etc
            // as in the .vmx
            $path = $disk->getSourcePath();

            // capture the .vmdk name from .vmx:
            // [datastore] foo.vmdk => foo.vmdk
            // /vmfs/asdfaxfgsdf/foo.vmdk => foo.vmdk
            // /vmfs/asdfasdf/foo-000001.vmdk => foo.vmdk - 000001 is there, if
            // VM was "on as snapshot"
            $vmdkNamePattern = '/(\[.*\] )?(.*)(-0000\d+.vmdk)/';
            $vmxVmdk = preg_replace(
                $vmdkNamePattern,
                '\2.vmdk',
                basename($path)
            );

            // there must be at least one at this point, no need to check, and
            // all disks are at the same location on the device.
            $storageLocation = $os2Disks[0]->getStorageLocation();

            foreach ($vmDisks as $vmDisk) {
                $targetVmdk = preg_replace(
                    $vmdkNamePattern,
                    '\2.vmdk',
                    basename($vmDisk->getPath())
                );

                if ($vmxVmdk === $targetVmdk) {
                    // the VMDK is shared as <uuid>.vmdk on device
                    $disk->setSourcePath(sprintf(
                        '%s/%s.vmdk',
                        $storageLocation,
                        $vmDisk->getUuid()
                    ));
                    break;
                }
            }
        }
    }
}
