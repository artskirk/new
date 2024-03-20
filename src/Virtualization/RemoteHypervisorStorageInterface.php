<?php

namespace Datto\Virtualization;

use Datto\Config\Virtualization\VirtualDisks;

/**
 * Handle offloading virtual disks to a remote hypervisor
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
interface RemoteHypervisorStorageInterface
{
    /**
     * Share VM disk image to hypervisor host for virtualization.
     *
     * @param string $vmName the name of the virtual machine
     * @param string $storageDir the directory containing the image files
     * @param bool $isEncrypted is the storageDir using encrypted datto files (see EsxRemoteStorage)
     * @param VirtualDisks $disks
     *
     * @return VirtualDisks the array of VirtualDisk with paths relative to the hypervisor host
     */
    public function offload(string $vmName, string $storageDir, bool $isEncrypted, VirtualDisks $disks): VirtualDisks;

    /**
     * Remove shared virtual disks
     *
     * @param string $vmName the name of the virtual machine
     * @param string $storageDir the directory containing the image files
     * @param bool $isEncrypted is the storageDir using encrypted datto files (see EsxRemoteStorage)
     */
    public function tearDown(string $vmName, string $storageDir, bool $isEncrypted);
}
