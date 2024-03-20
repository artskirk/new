<?php

namespace Datto\Agentless\Proxy;

use Datto\Common\Utility\Filesystem;
use Datto\Log\DeviceLoggerInterface;
use Datto\Util\OsFamily;
use Datto\Utility\Virtualization\GuestFs\FileManager;
use Datto\Utility\Virtualization\GuestFs\GuestFs;
use Datto\Utility\Virtualization\GuestFs\GuestFsFactory;
use Datto\Utility\Virtualization\GuestFs\OsInspector;
use Datto\Utility\Virtualization\GuestFs\PartitionManager;
use RuntimeException;
use Throwable;
use VirtualDisk;
use VirtualDiskRawDiskMappingVer1BackingInfo;
use Vmwarephp\Extensions\VirtualMachine;
use Vmwarephp\ManagedObject;

/**
 * Service class that leverages both libguestfs and VirtualMachine instance of VMware SOAP api to recreate both
 * agentInfo and esxInfo of a particular VM.
 *
 * @todo This class is ported from legacy agentless, it should be refactored to generate DWA compatible agentInfo"
 *
 * @author Mario Rial <mrial@datto.com>
 */
class EsxVmInfoService
{
    private const ENGINE_VERSION = '2.0';
    private const API_VERSION = '11.0';
    private const DEFAULT_SECTOR_SIZE = 512;
    private const ROOT_PATH = '/';

    /** @var bool */
    private bool $initialized = false;

    /** @var string Base mount point on local filesystem where VDDK disks are mounted */
    private string $vddkMountPoint;

    private DeviceLoggerInterface $logger;
    private GuestFsFactory $guestFsFactory;
    private Filesystem $filesystem;

    private VirtualMachine $virtualMachine;
    private ManagedObject $snapshot;

    // GuestFs Interfaces
    private GuestFs $guestFs;
    private FileManager $fileManager;
    private OsInspector $osInspector;
    private PartitionManager $partitionManager;

    /**
     * @param GuestFsFactory $guestFsFactory
     * @param DeviceLoggerInterface $logger
     * @param Filesystem $filesystem
     */
    public function __construct(
        GuestFsFactory $guestFsFactory,
        DeviceLoggerInterface $logger,
        Filesystem $filesystem
    ) {
        $this->guestFsFactory = $guestFsFactory;
        $this->logger = $logger;
        $this->filesystem = $filesystem;
    }

    /**
     * Initializes the EsxVmInfoService. Because construction is performed by the DI Containers, this
     * must be done after construction, but before use.
     *
     * @param VirtualMachine $virtualMachine The ESX VirtualMachine we are working with
     * @param ManagedObject $snapshot The snapshot we are using for disk information.
     * @param string $vddkMountPoint Local path where the VDDK drives are mapped
     */
    public function initialize(VirtualMachine $virtualMachine, ManagedObject $snapshot, string $vddkMountPoint): void
    {
        if ($this->initialized) {
            throw new RuntimeException("EsxVmInfoService Already Initialized");
        }

        $this->vddkMountPoint = $vddkMountPoint;
        $this->virtualMachine = $virtualMachine;
        $this->snapshot = $snapshot;

        // Iterate over the virtual disks, getting their locally-mounted paths
        $localPaths = [];
        foreach ($this->getVirtualDisks() as $virtualDisk) {
            $remoteVmdk = $virtualDisk->backing->fileName;
            $localPath = $this->getLocalPath($remoteVmdk);
            if (!$this->filesystem->exists($localPath)) {
                throw new RuntimeException('VMDK Not Found. Check VM Mount: ' . $localPath);
            }
            $localPaths[] = $localPath;
        }

        // Now, initialize guestfs and get handles to the helpers
        $this->guestFs = $this->guestFsFactory->initialize($localPaths);
        $this->partitionManager = $this->guestFsFactory->createPartitionManager();
        $this->fileManager = $this->guestFsFactory->createFileManager();
        $this->osInspector = $this->guestFsFactory->createOsInspector();

        // Try to run OS inspection, and log a warning if it failed
        if (!$this->osInspector->inspect()) {
            $this->logger->warning('EVI0001 Unable to identify VM OS, some agent metadata will be missing');
        }

        // Finally, mark the class as initialized
        $this->initialized = true;
    }

    /**
     * Creates and returns a data structure with information about the ESX VM.
     *
     * @return array
     */
    public function retrieveEsxVmInfo(): array
    {
        // Get the initial VM Info from the ESXi SOAP APIs
        $esxInfo['name'] = $this->virtualMachine->name;
        $esxInfo['moRef'] = $this->virtualMachine->toReference()->_;
        $esxInfo['vmdkInfo'] = $this->getVmdkInfo();
        $esxInfo['totalBytesCopied'] = 0;

        $this->logger->debug('EVI0010 Dumping ESX VM Info', ['esxInfo' => $esxInfo]);
        return $esxInfo;
    }

    /**
     * Creates and returns a complete AgentInfo data structure, containing all of the necessary
     * information for an Agentless Pairing.
     *
     * @return array
     */
    public function retrieveAgentInfo(): array
    {
        try {
            // If the OS Inspection failed, these will all throw
            $arch = $this->osInspector->getArch();
            $hostname = $this->osInspector->getHostname();
            $distro = $this->osInspector->getDistro();
            $version = $this->osInspector->getVersion();
            $productName = $this->osInspector->getProductName();
        } catch (Throwable $throwable) {
            $arch = 'unknown';
            $hostname = $this->virtualMachine->name;
            $distro = 'unknown';
            $version = 'unknown';
            $productName = 'unknown';
        }

        $arch64 = ($arch === 'x86_64');

        try {
            $volumeInfo = $this->createVolumeInfo();
        } catch (Throwable $throwable) {
            $volumeInfo = [];
            $this->logger->error('EVI0004 Failed to get volume info', [
                'error' => $throwable->getMessage()
            ]);
        }

        // Set the initial AgentInfo structure. We assume that we h
        $agentInfo = [
            'ram' => $this->virtualMachine->config->hardware->memoryMB * 1024 * 1024,
            'stcDriverLoaded' => 0,
            'archBits' => $arch64 ? '64bits' : '32bits',
            'hostName' => $hostname,
            'cpus' => $this->virtualMachine->config->hardware->numCPU,
            'agentSerialNumber' => '',
            'agentVersion' => 'Agentless',
            'volumes' => $volumeInfo,
            'arch' => $arch64 ? '64' : '32',
            'apiVersion' => self::API_VERSION,
            'agentActivated' => 1,
            'os_arch' => $arch64 ? 'x64' : 'x32',
            'os' => "$distro $version",
            'name' => $this->virtualMachine->name,
            'hostname' => $hostname,
            'cores' => $this->virtualMachine->config->hardware->numCPU,
            'memory' => $this->virtualMachine->config->hardware->memoryMB,
            'generated' => time(),
            'version' => self::ENGINE_VERSION,
            'driverVersion' => self::ENGINE_VERSION,
            'os_version' => $version,
            'os_name' => $productName,
            'os_servicepack' => '',
            'os_type' => $this->getOsType()
        ];

        $volumes = $agentInfo['volumes'];
        if ($this->getOsType() != OsFamily::WINDOWS) {
            $agentInfo['kernel'] = $this->getLinuxKernel($volumes);
        }

        $this->logger->debug('EVI0011 Dumping Agent Info', ['agentInfo' => $agentInfo]);
        return $agentInfo;
    }

    /**
     * Shutdown the GuestFs if it was initialized.
     */
    public function shutdown()
    {
        if (!$this->initialized) {
            return;
        }

        $this->guestFs->shutDown();
    }

    /**
     * Generates 'volumes' key for the agent info array.
     *
     * @return array
     */
    private function createVolumeInfo(): array
    {
        if ($this->getOsType() == OsFamily::WINDOWS) {
            return $this->createWindowsVolumeInfo();
        }

        // has some chance to produce useful info for any POSIX os too.
        return $this->createLinuxVolumeInfo();
    }

    /**
     * Generates volume info array for Windows OS.
     *
     * @return array
     */
    private function createWindowsVolumeInfo(): array
    {
        $filesystems = $this->guestFs->listFilesystems();
        $mappings = $this->osInspector->getDriveMappings();
        $rootFs = $this->osInspector->getRootFs();
        $volumes = [];

        foreach ($filesystems as $mountable => $fsType) {
            // There are a handful of parameters that are required for a volume to be useful. If any of these
            // error out, the volume will be skipped. The rest are allowed to fail with sane defaults provided
            try {
                // Skip over extended partitions or unknown partition types
                if ($fsType === 'unknown' || $this->partitionManager->isExtended($mountable)) {
                    continue;
                }

                $device = $this->partitionManager->getPartitionDevice($mountable);
                $partNum = $this->partitionManager->getPartitionNumber($mountable);
                $partType = $this->partitionManager->getPartitionType($device);
                $partSize = $this->partitionManager->getPartitionSize($mountable);
                $isBootable = $this->partitionManager->isBootable($device, $partNum);

                // Treat GPT and non-GPT disks slightly different
                if ($this->isGpt($partType)) {
                    // Note that this is mostly-irrelevant for GPT disks, and is simply the "locally-unique"
                    // ID for the block device's partition table (not the partition itself)
                    $serialNumber = $this->formatGuid($this->partitionManager->getPartitionTableUuid($device));
                    $guid = $this->formatGuid($this->partitionManager->getGptPartitionGuid($mountable));
                    $partScheme = 'GPT';
                } else {
                    $serialNumber = $this->getNtfsSerialNumber($device);
                    $guid = $this->generateVolumeGuid($mountable, $serialNumber);
                    $partScheme = 'MBR';
                }

                $vol = [
                    // These parameters are required to do anything useful with a backup
                    'capacity' => $partSize,
                    'filesystem' => strtoupper($fsType),
                    'guid' => $guid,
                    'OSVolume' => $mountable === $rootFs,
                    'partScheme' => $partScheme,
                    'realPartScheme' => $partScheme,
                    'serialNumber' => $serialNumber,
                    'spaceTotal' => $partSize,
                    'sysVolume' => $isBootable,

                    // These are optional, and the functions that populate them will return defaults if errors occur
                    'clusterSizeInBytes' => $this->getClusterSize($mountable, 0),
                    'hiddenSectors' => 0,
                    'label' => $this->getPartitionLabel($mountable, ''),
                    'mountpoints' => '',
                    'removable' => false,
                    'sectorSize' => $this->getSectorSize($mountable, self::DEFAULT_SECTOR_SIZE),
                    'spacefree' => $this->getFreeSpace($mountable, 0),
                    'volumeType' => 'basic', // We don't support dynamic (e.g. RAID) disks
                ];

                // Search the drive mappings for drive letter associations
                if (is_array($mappings) && in_array($mountable, $mappings)) {
                    $vol['mountpoints'] = array_search($mountable, $mappings) . ':\\';
                }

                // Append the volume information to the array
                $volumes[] = $vol;
            } catch (Throwable $throwable) {
                $this->logger->warning('EVI0002 Error determining Windows volume information', [
                    'error' => $throwable->getMessage(),
                    'mountable' => $mountable,
                    'fsType' => $fsType
                ]);
            }
        }

        return $volumes;
    }

    /**
     * Creates volume info array for Linux OS.
     *
     * @return array
     */
    private function createLinuxVolumeInfo(): array
    {
        // Get the list of filesystems and the root filesystem
        $filesystems = $this->guestFs->listFilesystems();

        // Attempt to determine the root filesystem. In cases where OS inspection failed, this will be null
        $rootFs = $this->osInspector->getRootFs();

        // Attempt to determine the mount points for the filesystems. This may also fail if OS
        // inspection failed, so allow this to fail gracefully
        try {
            $mountpoints = $this->osInspector->getMountpoints();
            foreach ($mountpoints as &$mp) {
                if ($this->partitionManager->isLvmLv($mp)) {
                    $mp = $this->partitionManager->getCanonicalDeviceName($mp);
                }
            }
        } catch (Throwable $throwable) {
            $this->logger->warning('EVI0006 Could not determine mount points. Volume information will be incomplete', [
                'error' => $throwable->getMessage()
            ]);
            $mountpoints = [];
        }

        // Loop over the mountable filesystems, adding them to the volumes array
        $volumes = [];
        foreach ($filesystems as $mountable => $fsType) {
            try {
                if ($fsType === 'unknown') {
                    continue;
                }
                $isOsRoot = ($mountable === $rootFs);
                $isSwap = ($fsType === 'swap');

                // Determine some basic information about the partition. If any of this fails, we don't really
                // have much we can do for a volume, so we just don't add it to the list
                // LVMs are never bootable and won't work with regular partition API.
                if ($this->partitionManager->isLvmLv($mountable)) {
                    $isBootable = false;
                    $type = 'MBR';
                } else {
                    if ($this->partitionManager->isExtended($mountable)) {
                        continue;
                    }
                    $device = $this->partitionManager->getPartitionDevice($mountable);
                    $type = $this->partitionManager->getPartitionType($device);
                    $partNum = $this->partitionManager->getPartitionNumber($mountable);
                    $isBootable = $this->partitionManager->isBootable($device, $partNum);
                }
                $uuid = $this->partitionManager->getFilesystemUuid($mountable);

                // If the list of mountpoints contains this drive, set the key to the mount point. If not,
                // just use the partition ID, which is better than nothing, and will work in cases when
                // OS Inspection failed
                $mountpoint = array_search($mountable, $mountpoints) ?: $uuid;
                $key = $isSwap ? '<swap>' : $mountpoint;

                // Get space statistics
                $freeSpace = $isSwap ? 0 : $this->getFreeSpace($mountable, 0);
                $totalSpace = $this->partitionManager->getPartitionSize($mountable);
                $sectorSize = $this->getSectorSize($mountable, self::DEFAULT_SECTOR_SIZE);

                // If the partition scheme is not GPT, we default it to MBR (as opposed to MSDOS, etc...)
                $partScheme = $this->isGpt($type) ? 'GPT' : 'MBR';

                // hard-coded values either can't be read with guestfs or DLA always seems to return
                // the same value regardless
                $vol = [
                    'guid' => $uuid,
                    'scID' => $key,
                    'mountpoints' => $key,
                    'device' => $this->partitionManager->getCanonicalDeviceName($mountable),
                    'blockDevice' => $this->partitionManager->getCanonicalDeviceName($device),
                    'spaceTotal' => $totalSpace,
                    // Unfortunately, the rest of the codebase accesses both spacefree AND spaceFree. todo fix that
                    'spacefree' => $freeSpace,
                    'spaceFree' => $freeSpace,
                    'filesystem' => $fsType,
                    'sectorSize' => $sectorSize,
                    'sectorsTotal' => ceil($totalSpace / $sectorSize),
                    'blockSize' => $this->partitionManager->getPartitionBlockSize($mountable),
                    'hiddenSectors' => 0,
                    'serialNumber' => 0,
                    'volumeType' => '',
                    'label' => $this->getPartitionLabel($mountable, ''),
                    'OSVolume' => $isOsRoot,
                    'sysVolume' => $isBootable,
                    'removable' => false,
                    'mounted' => true,
                    'readonly' => false,
                    'partScheme' => $partScheme,
                    'realPartScheme' => $partScheme,
                    'mountpointsArray' => [$key]
                ];

                $volumes[$key] = $vol;
            } catch (Throwable $throwable) {
                $this->logger->warning('EVI0007 Error determining VM volume information', [
                    'error' => $throwable->getMessage(),
                    'mountable' => $mountable,
                    'fsType' => $fsType
                ]);
            }
        }

        return $volumes;
    }

    /**
     * Get the latest kernel installed on Linux OS.
     *
     * There's no way to obtain the currently running kernel as:
     *  - VM may not even be running when this is called.
     *  - There's variety of possible bootloaders and grub2 is unparseable, so
     *    it would be very unreliable.
     *
     * @param array $volumes
     * @return string
     */
    private function getLinuxKernel(array $volumes): string
    {
        $kernels = [];

        // Iterate over all of the volumes. To handle the case where /boot/ is mounted on a separate
        // partition, as well as the case where it's not, we look in '/boot/' of the OS Volume, and '/'
        // for all others.
        foreach ($volumes as $vol) {
            // Skip over swap partitions, they can't be mounted
            if ($vol['filesystem'] === 'swap') {
                continue;
            }

            try {
                $this->guestFs->mount($vol['device'], self::ROOT_PATH);
                $globPattern = $vol['OSVolume'] ? '/boot/vmlinuz-*' : '/vmlinuz-*';
                $kernels = array_merge($kernels, $this->fileManager->glob($globPattern));
            } catch (Throwable $throwable) {
                $this->logger->warning('EVI0017 Error getting linux kernel information', [
                    'error' => $throwable->getMessage(),
                    'device' => $vol['device']
                ]);
            } finally {
                try {
                    $this->guestFs->umount(self::ROOT_PATH);
                } catch (Throwable $throwable) {
                    $this->logger->debug('EVI0018 Could not unmount volume', [
                        'error' => $throwable->getMessage(),
                        'device' => $vol['device']
                    ]);
                }
            }
        }

        // Sort the list of kernels, so that the "latest" version is at the end
        sort($kernels, SORT_NATURAL);
        return str_replace('vmlinuz-', '', basename(end($kernels)));
    }

    /**
     * Get the operating system type
     *
     * @return string
     */
    private function getOsType(): string
    {
        $type = 'unknown';

        try {
            $type = $this->osInspector->getType();
        } catch (Throwable $throwable) {
            $this->logger->warning('EVI0012 Could not determine OS Type', [
                'error' => $throwable->getMessage()
            ]);
        }
        return $type;
    }

    /**
     * Returns a "guid" like string. Actually MD5 hash.
     *
     * The volumes in agentInfo have "guid" element, which definitely are
     * not GUIDs - they just look like it. This method generates a MD5 hash
     * based on volume serial number and partition UUID so that's guaranteed
     * to be unique for an agent and yet reproducible when needed.
     *
     * Relevant for MBR partitions only  which don't have GUIDs.
     *
     * @param string $partition
     * @param string $serialNumber
     * @return string
     */
    private function generateVolumeGuid(string $partition, string $serialNumber): string
    {
        $uuid = $this->partitionManager->getFilesystemUuid($partition);
        return md5($uuid . $serialNumber);
    }

    /**
     * Reads NTFS Serial Number from MBR.
     *
     * Uses guestfs instance, so it needs to be already launched to work.
     *
     * @param string $device
     *  Path to guestfs block device.
     *
     * @return string
     * @throws RuntimeException
     *  When failed to read raw bytes from device. Might happen when called,
     *  before internal guestfs instance was not properly launched.
     */
    private function getNtfsSerialNumber(string $device): string
    {
        $rawBytes = $this->guestFs->readDevice($device, 4, 440);
        $ret = unpack('V', $rawBytes);
        if (!$ret) {
            return '';
        }
        return $ret[1];
    }

    /**
     * Format GUID for storage in agentInfo. Strips '-' and makes sure it's lower case.
     *
     * @param string $guid
     * @return string
     */
    private function formatGuid(string $guid): string
    {
        return str_replace('-', '', strtolower($guid));
    }

    /**
     * Get the amount of free space in bytes on a mountable partition, with a user-provided default
     * return value in the case of an error.
     *
     * @param string $mountable
     * @param int $default
     * @return int
     */
    private function getFreeSpace(string $mountable, int $default): int
    {
        try {
            $this->guestFs->mount($mountable, self::ROOT_PATH);
            $vfsStat = $this->fileManager->getVfsStat(self::ROOT_PATH);
            $freeSpace = intval($vfsStat['bsize'] * $vfsStat['bfree']);
        } catch (Throwable $throwable) {
            $this->logger->warning('EVI0013 Could not retrieve filesystem statistics', [
                'error' => $throwable->getMessage(),
                'mountable' => $mountable
            ]);
            $freeSpace = $default;
        } finally {
            $this->guestFs->umount(self::ROOT_PATH);
        }
        return $freeSpace;
    }

    /**
     * Get the cluster size of a filesystem, in bytes.
     *
     * Cluster size potentially differs from physical block size. While block size refers to the unchanging
     * physical geometry of the disk, cluster size is able to be set while creating a filesystem, and is
     * the minimial size of a block that is readable or writable by the OS.
     *
     * @param string $mountable
     * @param int $default The default value to return, in case of an error
     * @return int The cluster size in bytes, or 0 if an error occurred
     */
    private function getClusterSize(string $mountable, int $default): int
    {
        try {
            $this->guestFs->mount($mountable, self::ROOT_PATH);
            $vfsStat = $this->fileManager->getVfsStat(self::ROOT_PATH);
            $clusterSize = intval($vfsStat['bsize']);
        } catch (Throwable $throwable) {
            $this->logger->warning('EVI0014 Could not retrieve filesystem statistics', [
                'error' => $throwable->getMessage(),
                'mountable' => $mountable
            ]);
            $clusterSize = $default;
        } finally {
            $this->guestFs->umount(self::ROOT_PATH);
        }
        return $clusterSize;
    }

    /**
     * Get the sector size of a mountable partition, or the provided default value in the
     * case of an error.
     *
     * @param string $mountable
     * @param int $default
     * @return int
     */
    private function getSectorSize(string $mountable, int $default): int
    {
        try {
            $sectorSize = $this->partitionManager->getPartitionSectorSize($mountable);
        } catch (Throwable $throwable) {
            $this->logger->warning('EVI0015 Could not determine sector size, using default', [
                'error' => $throwable->getMessage(),
                'mountable' => $mountable
            ]);
            $sectorSize = $default;
        }
        return $sectorSize;
    }

    /**
     * Get the label of a mountable partition, or the provided default if an error occurs
     *
     * @param string $mountable
     * @param string $default
     * @return string
     */
    private function getPartitionLabel(string $mountable, string $default): string
    {
        try {
            $label = $this->partitionManager->getPartitionLabel($mountable);
        } catch (Throwable $throwable) {
            $this->logger->warning('EVI0016 Could not retrieve partition label', [
                'error' => $throwable->getMessage(),
                'mountable' => $mountable
            ]);
            $label = $default;
        }
        return $label;
    }

    /**
     * Looks at the VM disks and pull all the needed info for backing up.
     *
     * Disk that cannot be backed up, e.g. specific RDM disk types, are still
     * included but marked as 'canSnapshot' => false. This is done so that
     * there's some way to indicate this in UI and inform the user. Those disks
     * are permanently excluded from backups.
     *
     * @return array Information on each VMDK we were able to process
     */
    private function getVmdkInfo(): array
    {
        $info = [];
        foreach ($this->getVirtualDisks() as $index => $virtualDisk) {
            try {
                // Get the VMDK and Guestfs paths associated with the virtual disk
                $remoteVmdk = $virtualDisk->backing->fileName;
                $guestfsDevice = $this->getGuestFsDeviceNode($index);
                $canSnapshot = $this->supportsSnapshots($virtualDisk);

                // Try to get the partition type. This will throw on unpartitioned disks.
                try {
                    $partType = $this->partitionManager->getPartitionType($guestfsDevice);
                } catch (Throwable $throwable) {
                    $partType = 'unknown';
                }

                // Populate information from the virtual disk
                $disk = [
                    'changeId' => $virtualDisk->backing->changeId,
                    'diskPath' => $remoteVmdk,
                    'diskSizeKiB' => (int)$virtualDisk->capacityInKB,
                    'deviceKey' => (int)$virtualDisk->key,
                    'diskUuid' => $virtualDisk->backing->uuid,
                    'localDiskPath' => $this->getLocalPath($remoteVmdk),
                    'canSnapshot' => $canSnapshot,
                    'isGpt' => $canSnapshot && $this->isGpt($partType),
                    'partitions' => $canSnapshot ? $this->getPartitionInfo($guestfsDevice) : []
                ];

                // Add the disk to the info array
                $info[] = $disk;
            } catch (Throwable $throwable) {
                $this->logger->warning('EVI0003 Could not get VMDK Info for ESX Snapshot Disk', [
                    'error' => $throwable->getMessage(),
                    'remoteVmdk' => $remoteVmdk,
                ]);
            }
        }

        return $info;
    }

    /**
     * Gets basic information about the partitioning of a guestfs device.
     *
     * The partition layout is extracted from remote VMDKs that are
     * analyzed using the PartitionManager.
     *
     * @param string $device The guestfs device name
     * @return array Information for each partition in the device
     */
    private function getPartitionInfo(string $device): array
    {
        $partLayout = [];

        try {
            $osType = $this->getOsType();
            $partInfo = $this->partitionManager->getPartitionLocations($device);
            $partType = $this->partitionManager->getPartitionType($device);

            if ($osType == OsFamily::WINDOWS) {
                // Get the serial number. Empty string for GPT Partitions
                $serialNumber = $this->isGpt($partType) ? '' : $this->getNtfsSerialNumber($device);

                foreach ($partInfo as $pi) {
                    $partDev = $device . $pi['part_num'];
                    if ($this->partitionManager->isExtended($partDev)) {
                        continue;
                    }

                    if ($this->isGpt($partType)) {
                        $guid = $this->formatGuid($this->partitionManager->getGptPartitionGuid($partDev));
                    } else {
                        $guid = $this->generateVolumeGuid($partDev, $serialNumber);
                    }

                    $partitionInfo = [
                        'part_num' => $pi['part_num'],
                        'part_start' => $pi['part_start'],
                        'part_end' => $pi['part_end'],
                        'part_size' => $pi['part_size'],
                        'guid' => $guid,
                        'bootable' => $this->partitionManager->isBootable($device, $pi['part_num']),
                    ];

                    $partLayout[] = $partitionInfo;
                }
            } else {
                $lvOffset = 0;
                $pvNum = 0;
                foreach ($partInfo as $pi) {
                    $partDev = $device . $pi['part_num'];
                    if ($this->partitionManager->isLvmPv($partDev)) {
                        $pvs = $this->partitionManager->getLvmPvsFull();
                        $lvOffset = $pi['part_start'] + $pvs[0]['pe_start'];
                        $pvNum = $pi['part_num'];
                        continue;
                    }

                    // skip extended partition
                    if ($this->partitionManager->isExtended($partDev)) {
                        continue;
                    }

                    $partitionInfo = [
                        'part_num' => $pi['part_num'],
                        'part_start' => $pi['part_start'],
                        'part_end' => $pi['part_end'],
                        'part_size' => $pi['part_size'],
                        'guid' => $this->partitionManager->getFilesystemUuid($partDev),
                        'bootable' => $this->partitionManager->isBootable($device, $pi['part_num']),
                    ];

                    $partLayout[] = $partitionInfo;
                }

                // Append the LVM partitions now
                $this->appendLvmPartitions($lvOffset, $pvNum, $partLayout);
            }
        } catch (Throwable $throwable) {
            $this->logger->warning('EVI0009 Error retrieving GuestFS partition information', [
                'error' => $throwable->getMessage(),
                'device' => $device,
            ]);
        }
        return $partLayout;
    }

    /**
     * Extracts partitions from LVM volumes and get their absolute offsets, then appends that
     * information to the passed-in partition layout array
     *
     * @param int $lvOffset The offset where the first Logical Volume data starts.
     * @param int $pvPartNum The partition number of the Physical Volume.
     * @param array $partLayout Reference to the array holding info about all partitions.
     *
     * @return void
     */
    private function appendLvmPartitions(int $lvOffset, int $pvPartNum, array &$partLayout)
    {
        $partitionManager = $this->guestFsFactory->createPartitionManager();
        try {
            $lvsFull = $partitionManager->getLvmLvsFull();
        } catch (Throwable $throwable) {
            $this->logger->warning('EVI0008 Error retrieving LVM LV Partition Information', [
                'error' => $throwable->getMessage()
            ]);
            return;
        }

        $lvs = $partitionManager->getLvmLvs();
        $index = 0;
        $partStart = $lvOffset;
        foreach ($lvsFull as $lv) {
            unset($lvDev);
            foreach ($lvs as $lvMountable) {
                if (basename($lvMountable) == $lv['lv_name']) {
                    $lvDev = $lvMountable;
                }
            }

            $partitionInfo = [
                'part_num' => $pvPartNum + $index,
                'part_start' => $partStart,
                'part_end' => $partStart + $lv['lv_size'] - 1,
                'part_size' => $lv['lv_size'],
                'guid' => isset($lvDev) ? $partitionManager->getFilesystemUuid($lvDev) : ''
            ];

            $partStart = $partitionInfo['part_end'] + 1;
            $partLayout[] = $partitionInfo;
            $index++;
        }
    }

    /**
     * Convert a remote VMDK Path to a local one.
     * Example: "[datastore1] /path/to Great/file.vmdk" => "/<vddk-mount-point>/datastore1__path_to_Great_file.vmdk"
     *
     * @param string $remoteVmdkPath
     * @return string
     */
    private function getLocalPath(string $remoteVmdkPath): string
    {
        $convertedPath = str_replace(['[', ']', '/', ' '], ['', '', '_', '_'], $remoteVmdkPath);
        return sprintf('%s/%s', $this->vddkMountPoint, $convertedPath);
    }

    /**
     * Converts an index to a guestfs device mapping, using the same mechanism used by the guestfs library.
     * (0 => /dev/sda, 1 => /dev/sdb, 2 => /dev/sdc, etc...)
     *
     * Note that these mappings are agnostic of what is actually on the disk, whether it be Windows, Linux,
     * or anything else.
     *
     * @param int $index
     * @return string
     */
    private function getGuestFsDeviceNode(int $index): string
    {
        return '/dev/sd' . range('a', 'z')[$index % 26];
    }

    /**
     * @param string $partType
     * @return bool
     */
    private function isGpt(string $partType): bool
    {
        return $partType === PartitionManager::PART_TYPE_GPT;
    }

    /**
     * Get the virtual disks associated with the snapshot. Note that this includes RDM disks, which
     * do not get backed up, but do get displayed.
     *
     * @return array
     */
    private function getVirtualDisks(): array
    {
        $virtualDisks = [];
        try {
            $devices = $this->snapshot->config->hardware->device;
            foreach ($devices as $device) {
                if ($device instanceof VirtualDisk) {
                    $virtualDisks[] = $device;
                }
            }
        } catch (Throwable $throwable) {
            $this->logger->warning('EVI0005 Error retrieving snapshot virtual disks', [
                'error' => $throwable->getMessage()
            ]);
        }
        return $virtualDisks;
    }

    /**
     * Determine whether a given virtual disk supports snapshots. Right now we just exclude VMWare RDC disks
     *
     * @param mixed $virtualDisk
     * @return bool
     */
    private function supportsSnapshots($virtualDisk): bool
    {
        if ($virtualDisk->backing instanceof VirtualDiskRawDiskMappingVer1BackingInfo) {
            return false;
        }
        return true;
    }
}
