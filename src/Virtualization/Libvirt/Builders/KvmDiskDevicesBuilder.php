<?php

namespace Datto\Virtualization\Libvirt\Builders;

use Datto\Config\Virtualization\VirtualDisk;
use Datto\Virtualization\Libvirt\Domain\VmDiskDefinition;

/**
 * Disk builder for KVM Hypervisor
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class KvmDiskDevicesBuilder extends BaseDiskDevicesBuilder
{
    /**
     * @inheritdoc
     */
    protected function buildDisk(int $diskIndex, VirtualDisk $sourceDisk, VmDiskDefinition $targetVmDisk)
    {
        // KVM hates our VMDKs, use raw .dattos
        $targetVmDisk->setSourcePath(sprintf(
            '%s/%s',
            $sourceDisk->getStorageLocation(),
            $sourceDisk->getRawFileName()
        ));
    }
}
