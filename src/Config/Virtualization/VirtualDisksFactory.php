<?php

namespace Datto\Config\Virtualization;

use Datto\Asset\Agent\Backup\AgentSnapshotRepository;
use Datto\Asset\Agent\Backup\AgentSnapshotService;
use Datto\Restore\CloneSpec;

/**
 * A Factory that creates Virtual Disks in various ways
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class VirtualDisksFactory
{
    /** @var AgentSnapshotService */
    private $agentSnapshotService;
    
    public function __construct(AgentSnapshotService $agentSnapshotService)
    {
        $this->agentSnapshotService = $agentSnapshotService;
    }

    /**
     * Used for getting virtual disks from a clone
     */
    public function getVirtualDisks(CloneSpec $cloneSpec): VirtualDisks
    {
        return $this->get($cloneSpec->getAssetKey(), $cloneSpec->getSnapshotName(), $cloneSpec->getTargetMountpoint());
    }

    /**
     * This can be used to get the count of virtual disks when a clone hasn't been created yet.
     */
    public function getVirtualDisksCount(string $keyName, int $snapshot): int
    {
        // Since the clone isn't available and we're only reading data, it's ok to create the
        // virtual disks in the .zfs directory
        $storageDir = sprintf(AgentSnapshotRepository::BACKUP_DIRECTORY_TEMPLATE, $keyName, $snapshot);

        return count($this->get($keyName, $snapshot, $storageDir, true));
    }

    private function get(string $keyName, int $snapshot, string $storageDir, bool $isReadOnly = false)
    {
        // We're using this for reading information about the snapshot because we don't have these functions for a clone
        $agentSnapshot = $this->agentSnapshotService->get($keyName, $snapshot);

        $diskDrives = $agentSnapshot->getDiskDrives();
        $volumes = $agentSnapshot->getVolumes()->getArrayCopy();

        $virtualDisks = new VirtualDisks();

        if ($diskDrives) {
            $virtualDisks->fromDiskDrives($diskDrives, $storageDir, $isReadOnly);
        } else {
            $virtualDisks->fromVolumes(
                $volumes,
                $agentSnapshot->getOperatingSystem()->getOsFamily(),
                $storageDir
            );
        }

        return $virtualDisks;
    }
}
