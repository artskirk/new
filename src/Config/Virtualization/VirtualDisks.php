<?php

namespace Datto\Config\Virtualization;

use ArrayIterator;
use Datto\Asset\Agent\Backup\DiskDrive;
use Datto\Asset\Agent\Volume;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\Virtualization\Exception\MissingVirtualDisksException;
use Datto\Common\Utility\Filesystem;
use Datto\Restore\LinkedVmdkMaker;
use Datto\Util\OsFamily;
use DomainException;
use Exception;

/**
 * Provides a list of VirtualDisk objects containing information about the
 * .vmdk file name and corresponding raw .datto file. This class processes the
 * agentInfo 'volumes' section to determine which drives are 'attachable' to
 * VM and in what order.
 *
 * @author Dawid Zamirski <dzamirski@datto.com>
 */
class VirtualDisks extends ArrayIterator
{
    /** @var string */
    private $storageLocation;
    /** @var Filesystem */
    private $filesystem;
    /** @var LinkedVmdkMaker */
    private $vmdkMaker;

    public function __construct(
        Filesystem $filesystem = null,
        LinkedVmdkMaker $vmdkMaker = null,
        $array = [],
        int $flags = 0
    ) {
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->vmdkMaker = $vmdkMaker ?: new LinkedVmdkMaker($this->filesystem);

        parent::__construct($array, $flags);
    }

    /**
     *  Given a path to directory, find which ones should be attached to VM.
     *
     *  Also performs basic boot record parsing to detect which one contains the
     *  boot partition in order to attach it as first disk in an effort to get a
     *  bootable VM.
     *
     * @param DiskDrive[] $diskDrives
     * @param string $storageLocation
     * @param bool $isReadOnly This is readonly path, don't write to this location.
     * @return void
     */
    public function fromDiskDrives(array $diskDrives, string $storageLocation, bool $isReadOnly = false)
    {
        $virtualDisks = [];

        $missingCount = 0;
        foreach ($diskDrives as $drive) {
            $dattoPath = sprintf('%s/%s.datto', $storageLocation, $drive->getUuid());
            $dettoPath = sprintf('%s/%s.detto', $storageLocation, $drive->getUuid());
            $vmdkPath = sprintf('%s/%s.vmdk', $storageLocation, $drive->getUuid());

            if (!$this->filesystem->exists($dattoPath) && !$this->filesystem->exists($dettoPath)) {
                $missingCount++;
                continue;
            }

            if (!$isReadOnly && !$this->filesystem->exists($vmdkPath)) {
                $this->vmdkMaker->make($dattoPath, basename($vmdkPath));
            }

            $virtualDisk = new VirtualDisk(
                basename($dattoPath),
                basename($vmdkPath),
                $storageLocation,
                $drive->isGpt()
            );

            if ($drive->hasBootablePartition()) {
                $this->append($virtualDisk);
            } else {
                $virtualDisks[] = $virtualDisk;
            }
        }

        // In UVB, we don't have intentional exclusions, so any missing disk is
        // to be treated as a problem.
        if ($missingCount > 0) {
            throw new MissingVirtualDisksException(sprintf(
                'One or more virtual disks from UVB is missing, ' . PHP_EOL .
                'passed count: %d, missing: %d',
                count($diskDrives),
                $missingCount
            ));
        }

        foreach ($virtualDisks as $virtualDisk) {
            $this->append($virtualDisk);
        }
    }

    /**
     * Loads the virtual disks information.
     *
     * Given agentInfo, storage location, populates itself with the information
     * on virtual disks that must be attached to a VM, in proper order.
     *
     * @param Volume[] $volumes
     * @param OsFamily $osFamily
     * @param string $storageLocation The path to directory where disk images are stored.
     *
     * @return void
     */
    public function fromVolumes(array $volumes, OsFamily $osFamily, string $storageLocation)
    {
        if ($this->count() > 0) {
            throw new Exception(
                'VirtualDisks has already been loaded with data'
            );
        }

        $this->storageLocation = $storageLocation;

        if (false === $this->filesystem->exists($this->storageLocation)) {
            throw new DomainException(sprintf(
                'The passed storage location does not exist: %s',
                $this->storageLocation
            ));
        }

        $disks = $this->getVirtualDisks($volumes, $osFamily);

        foreach ($disks as $disk) {
            $this->append($disk);
        }
    }

    /**
     * Generates VirtualDisks array based on agent environment settings.
     *
     * Primarily 'parser' agentInfo's 'volume' section to figure out which
     * disks are atttachable to VMs and in what order.
     *
     * @param Volume[] $volumes
     * @param OsFamily $osFamily
     * @return VirtualDisk[] Where array keys are disk GUIDs.
     */
    private function getVirtualDisks(array $volumes, OsFamily $osFamily): array
    {
        $bootVolumeGuid = '';
        $osVolumeGuid = '';
        $isWindows = $osFamily === OsFamily::WINDOWS();
        $normalizedVolumes = array();
        $noletterIndex = 0;

        $excludedCount = 0;
        foreach ($volumes as $vol) {
            $guid = $vol->getGuid();

            if ($this->excludeVolume($vol)) {
                $excludedCount++;
                continue;
            }

            $diskFileName = $this->getDiskFileName($isWindows, $vol);

            if (empty($diskFileName)) {
                $diskFileName = 'noletter' . $noletterIndex;
                $noletterIndex++;
            }

            if ($vol->isSysVolume()) {
                $bootVolumeGuid = $guid;
            }

            if ($vol->isOsVolume()) {
                $osVolumeGuid = $guid;
            }

            $isGpt = !empty($vol->getRealPartScheme())
                && $vol->getRealPartScheme() === 'GPT';

            $normalizedVolumes[$guid] =
                [
                    'diskName' => $diskFileName,
                    'isGpt' => $isGpt
                ];
        }

        if (empty($bootVolumeGuid)) {
            $bootVolumeGuid = $osVolumeGuid;
        }

        if (!$normalizedVolumes) {
            throw new MissingVirtualDisksException(sprintf(
                'There were no volumes that could be used as virtual disks, ' .
                'they were either all excluded or all image files are missing. ' .
                'Total drives passed in: %d, excluded: %d',
                count($volumes),
                $excludedCount
            ));
        }

        return $this->processNormalizedVolumes(
            $normalizedVolumes,
            $bootVolumeGuid,
            $osVolumeGuid
        );
    }

    /**
     * Determines whether the given volume should be ignored / attached.
     *
     * For example, Linux swap and Windows SRP should not be attached.
     *
     * @param Volume $volume
     * @return bool
     */
    private function excludeVolume(Volume $volume): bool
    {
        // Do not attach Windows SRP
        if ($this->isSRP($volume)) {
            return true;
        }

        // Do not attach Linux swap disk
        if ($volume->getMountpoint() === '<swap>') {
            return true;
        }

        $datto = sprintf('%s/%s.datto', $this->storageLocation, $volume->getGuid());
        $detto = sprintf('%s/%s.detto', $this->storageLocation, $volume->getGuid());

        // if no raw .d[ae]tto file actually exists, exclude.
        if (!$this->filesystem->exists($detto) && !$this->filesystem->exists($datto)) {
            return true;
        }

        return false;
    }

    /**
     * Checks if volume appears to be SRP
     *
     * Obviously applicable to Windows .agentInfo's only.
     *
     * @param Volume $volume
     * @return bool
     */
    private function isSRP(Volume $volume): bool
    {
        // 550 MiB or less
        $srpSize = 576716800;

        return strtolower($volume->getFilesystem()) == 'ntfs'
            && $volume->isSysVolume()
            && empty($volume->getMountpoint())
            && $volume->getSpaceTotal() <= $srpSize;
    }

    /**
     * Gets the final disk filename from agentInfo volume data.
     *
     * For Windows, it tries to get drive letter first then falls back to label.
     * For Linux, it's simply block device name.
     *
     * @param mixed $isWindows
     * @param Volume $volume
     *
     * @return string
     */
    private function getDiskFileName($isWindows, Volume $volume): string
    {
        $diskFileName = '';
        if ($isWindows) {
            $diskFileName = !empty($volume->getMountpoint())
                ? $volume->getMountpoint()[0]
                : '';
        } else {
            $diskFileName = !empty($volume->getBlockDevice())
                ? basename($volume->getBlockDevice())
                : '';
        }

        return $diskFileName;
    }

    /**
     * Return array of VirtualDisks based on the normalized volumes array.
     *
     * @param array $normalizedVolumes
     * @param string $bootVolumeGuid
     * @param string $osVolumeGuid
     *
     * @return VirtualDisk[]
     *  Where array keys are disk GUIDs
     */
    private function processNormalizedVolumes(
        array $normalizedVolumes,
        $bootVolumeGuid,
        $osVolumeGuid
    ): array {
        $finalVolumes = array();

        $hirBootVolumePath = sprintf('%s/boot.vmdk', $this->storageLocation);

        // We always put boot.datto first when it exists.
        // This purposefully ignores the user's boot disk selection.
        if ($this->filesystem->exists($hirBootVolumePath)) {
            $finalVolumes['boot'] = new VirtualDisk(
                'boot.datto',
                'boot.vmdk',
                $this->storageLocation
            );
        }

        // User-selected boot volume
        $finalVolumes[$bootVolumeGuid] = $this->makeVirtualDisk(
            $bootVolumeGuid,
            $normalizedVolumes
        );
        unset($normalizedVolumes[$bootVolumeGuid]);

        // OS root
        if ($bootVolumeGuid != $osVolumeGuid) {
            $finalVolumes[$osVolumeGuid] = $this->makeVirtualDisk(
                $osVolumeGuid,
                $normalizedVolumes
            );
            unset($normalizedVolumes[$osVolumeGuid]);
        }

        // data drives in any order
        foreach (array_keys($normalizedVolumes) as $guid) {
            $finalVolumes[$guid] = $this->makeVirtualDisk(
                $guid,
                $normalizedVolumes
            );
        }

        return $finalVolumes;
    }

    /**
     * Creates VirtualDisk instance based on volume arrays.
     *
     * @param string $guid
     * @param array $normalizedVolumes
     *
     * @return VirtualDisk
     */
    private function makeVirtualDisk($guid, array $normalizedVolumes): VirtualDisk
    {
        return new VirtualDisk(
            $guid . '.datto',
            $normalizedVolumes[$guid]['diskName'] . '.vmdk',
            $this->storageLocation,
            $normalizedVolumes[$guid]['isGpt']
        );
    }
}
