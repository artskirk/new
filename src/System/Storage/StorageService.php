<?php

namespace Datto\System\Storage;

use Datto\AppKernel;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\DeviceConfig;
use Datto\Feature\FeatureService;
use Datto\Log\LoggerFactory;
use Datto\Utility\Azure\InstanceMetadataDisk;
use Datto\Utility\Azure\InstanceMetadataStorageProfile;
use Datto\Utility\ByteUnit;
use Datto\Common\Utility\Filesystem;
use Datto\Core\Storage\SirisStorage;
use Datto\Core\Storage\StorageCreationContext;
use Datto\Core\Storage\StorageInterface;
use Datto\Core\Storage\StoragePoolCreationContext;
use Datto\Core\Storage\StoragePoolDeviceStatus;
use Datto\Core\Storage\StorageType;
use Datto\Core\Storage\Zfs\ZfsStorage;
use Datto\Utility\Block\LsBlk;
use Datto\Utility\Storage\Zpool;
use Datto\Utility\Storage\ZpoolCreateException;
use Exception;
use Datto\Log\DeviceLoggerInterface;
use Datto\Utility\Azure\InstanceMetadata;
use Datto\ZFS\ZpoolService;
use Throwable;

/**
 * This class is used to get information about storage devices and use them to create our zpool.
 * @author Matt Cheman <mcheman@datto.com>
 */
class StorageService
{
    const DEFAULT_QUOTA_DATASET = "homePool/home";
    const DEFAULT_POOL_NAME = "homePool";
    const DEFAULT_CONFIG_BACKUP_SNAPSHOT_NAME = 'homePool/home/configBackup';
    const FSTRIM_COMMAND = 'fstrim';
    const VERBOSE_OPTION = '-v';
    const ALL_MOUNTPOINTS = '-a';
    const ROOT_MOUNTPOINT = '/';
    const HOST_MOUNTPOINT = '/host';

    const MDSTAT_FILE = "/proc/mdstat";

    private Filesystem $filesystem;
    private DeviceConfig $config;
    private LsBlk $lsBlk;
    private DeviceLoggerInterface $logger;
    private StorageDeviceFactory $storageDeviceFactory;
    private FeatureService $featureService;
    private InstanceMetadata $instanceMetadataService;
    private ZpoolService $zpoolService;
    private ProcessFactory $processFactory;
    private StorageInterface $storage;
    private SirisStorage $sirisStorage;

    public function __construct(
        Filesystem $filesystem = null,
        DeviceConfig $config = null,
        LsBlk $lsBlk = null,
        StorageDeviceFactory $storageDeviceFactory = null,
        DeviceLoggerInterface $logger = null,
        FeatureService $featureService = null,
        InstanceMetadata $instanceMetadataService = null,
        ZpoolService $zpoolService = null,
        ProcessFactory $processFactory = null,
        StorageInterface $storage = null,
        SirisStorage $sirisStorage = null
    ) {
        $this->processFactory = $processFactory ?: new ProcessFactory();
        $this->lsBlk = $lsBlk ?: new LsBlk(new ProcessFactory());
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->config = $config ?: new DeviceConfig($this->filesystem);
        $this->storageDeviceFactory = $storageDeviceFactory ?: new StorageDeviceFactory();
        $this->logger = $logger ?: LoggerFactory::getDeviceLogger();
        $this->featureService = $featureService ?: new FeatureService();
        $this->instanceMetadataService = $instanceMetadataService ?: new InstanceMetadata();
        $this->zpoolService = $zpoolService ?: new ZpoolService();
        $this->storage = $storage ?? AppKernel::getBootedInstance()->getContainer()->get(ZfsStorage::class);
        $this->sirisStorage = $sirisStorage ?? AppKernel::getBootedInstance()->getContainer()->get(SirisStorage::class);
    }

    /**
     * Scans the SCSI bus for newly attached drives
     */
    public function rescanDevices()
    {
        if ($this->filesystem->exists('/usr/bin/rescan-scsi-bus.sh')) {
            $process = $this->processFactory->get(['rescan-scsi-bus.sh', '--remove']);
            $process->run();
        }
    }

    /**
     * Get the array of storage devices detected on this machine
     *
     * @return StorageDevice[]
     */
    public function getDevices()
    {
        $devices = [];
        $allBlockDevices = $this->lsBlk->getBlockDevices();
        foreach ($allBlockDevices as $disk) {
            if (!$disk->isDisk()) {
                continue;
            }
            $devices[] = $this->storageDeviceFactory->getStorageDevice($disk->getPath());
        }

        return $devices;
    }

    /**
     * Get the array of physical storage devices detected on this machine
     * Command output:
     * sda
     * sdb
     * sdc
     *
     * @return StorageDevice[]
     */
    public function getPhysicalDevices()
    {
        $devices = [];
        $deviceList = $this->lsBlk->getDiskDrives();

        foreach ($deviceList as $disk) {
            $devices[] = $this->storageDeviceFactory->getStorageDevice($disk->getPath());
        }

        return $devices;
    }

    /**
     * @param string $deviceId
     * @return StorageDevice|null
     */
    public function getPhysicalDeviceById(string $deviceId)
    {
        $devices = $this->getPhysicalDevices();

        foreach ($devices as $device) {
            if (in_array($deviceId, $device->getIds())) {
                return $device;
            }
        }

        return null;
    }

    /**
     * @param string $devicePath
     * @return StorageDevice|null
     */
    public function getPhysicalDeviceByPath(string $devicePath)
    {
        $devices = $this->getPhysicalDevices();

        foreach ($devices as $device) {
            if ($device->getName() === $devicePath) {
                return $device;
            }
        }

        return null;
    }

    /**
     * Creates a new zpool with the specified devices and name
     *
     * @param string[] $devices
     * @param string $poolName the name of the zpool to create
     */
    public function createNewPool(array $devices, $poolName = SirisStorage::PRIMARY_POOL_NAME)
    {
        $this->logger->info('STO0001 Request to create new zpool with devices', ['poolName' => $poolName, 'devices' => $devices]);

        // first check to make sure a pool with the specified name does
        // not already exist
        $exists = $this->storage->poolHasBeenImported($poolName);
        if ($exists) {
            $this->logger->error('STO0002 Specified zpool already exists', ['poolName' => $poolName]);
            throw new Exception('Specified zpool '.$poolName.' already exists', 3);
        }
        $storageDevices = $this->getDevices();
        $driveNames = array();
        foreach ($storageDevices as $device) {
            if (in_array($device->getName(), $devices) && $device->getStatus() === StorageDevice::STATUS_AVAILABLE) {
                $driveNames[] = $device->getName();
            }
        }

        $this->createZpool($driveNames, $poolName);
        $this->setCompressionOn($poolName);
        $homeDatasetName = $this->createZfsHomeMountpoint($poolName);
        $this->createZfsTransferMountpoint($poolName);

        if ($this->featureService->isSupported(FeatureService::FEATURE_SET_POOL_QUOTA)) {
            $capacityInGb = intval($this->config->get("capacity"));
            $capacityInBytes = ByteUnit::GIB()->toByte($capacityInGb);
            $this->setDatasetQuota($capacityInBytes, $homeDatasetName);
        }

        $this->createZfsAgentMountpoint($poolName);

        $this->logger->info('STO0012 Finished creating ', ['poolName' => $poolName]);

        // Export the pool and attempt to re-import by id, this is expected to work in all cases
        // except ESX hosts with missing SCSI UUIDs. For ESX hosts fall back to importing by device name.
        $this->zpoolService->export($poolName);

        try {
            $this->zpoolService->import($poolName, false, true);
        } catch (Throwable $exception) {
            $this->logger->info(
                'STO0018 Unable to import pool by id, falling back to importing by device name',
                [
                    'poolName' => $poolName,
                    'exception' => $exception
                ]
            );

            $this->zpoolService->import($poolName);
        }
    }

    public function poolExists($poolName = SirisStorage::PRIMARY_POOL_NAME)
    {
        return $this->storage->poolHasBeenImported($poolName);
    }

    public function poolEmpty(): bool
    {
        $poolName = SirisStorage::PRIMARY_POOL_NAME;
        foreach ($this->storage->getSnapshotInfosFromStorage($poolName, true) as $snapshot) {
            if (strpos($snapshot->getId(), self::DEFAULT_CONFIG_BACKUP_SNAPSHOT_NAME) === false) {
                return false;
            }
        }
        $poolInfo = $this->storage->getPoolInfo($poolName);
        return $poolInfo->getAllocatedPercent() === 0;
    }

    /**
     * Get the status of the passed pool (by default homePool)
     * in the following format:
     * array:
     *      devices:
     *          - indent_level: int
     *            name: string
     *            state: string | null
     *            read: string | null
     *            write: string | null
     *            cksum: string | null
     *            note: string | null
     *          - ...
     *      errors: string
     * @param string $poolName
     * @return array|null
     */
    public function getPoolStatus($poolName = SirisStorage::PRIMARY_POOL_NAME)
    {
        try {
            $storagePoolStatus = $this->storage->getPoolStatus($poolName, false);
            $devices = $this->serializeStoragePoolDeviceStatusArray(0, $storagePoolStatus->getDevices());
            $errors = implode("", $storagePoolStatus->getErrors());
        } catch (Throwable $throwable) {
            return null;
        }

        return ["devices" => $devices, "errors" => $errors];
    }

    /**
     * Function that returns information about the OS storage filesystem,
     * Used, capacity, and percentage used.
     *
     * @return array
     */
    public function getOsDriveInfo()
    {
        $process = $this->processFactory->getFromShellCommandLine("df -h | grep '/$' | awk 'NR<2'");
        $process->run();

        $storageString = $process->getOutput();
        $storageArray = preg_split("/\s+/", $storageString);
        $used = $storageArray[2];
        $capacity = $storageArray[1];
        $percentUsed = $storageArray[4];
        $osDriveInfo = array(
            "used" => $used,
            "capacity" => $capacity,
            "percentage" => $percentUsed
        );

        return $osDriveInfo;
    }

    /**
     * Gets the storage device where the OS/Image lives
     *
     * @return StorageDevice
     */
    public function getOsDrive(): StorageDevice
    {
        $osDisk = $this->getFilesystemPartition("/");
        if (strpos($osDisk, "/dev/loop") !== false) {
            $osDisk = $this->getFilesystemPartition("/host");
        }

        $osDisk = $this->stripPartition($osDisk);

        $osDisk = $this->storageDeviceFactory->getStorageDevice($osDisk);

        return $osDisk;
    }

    /**
     * Sets the quota of the specified dataset.
     *
     * @param int $quotaInBytes Quota to set in bytes.
     * @param string $datasetName the name of the dataset
     */
    public function setDatasetQuota($quotaInBytes, $datasetName = self::DEFAULT_QUOTA_DATASET)
    {
        $this->logger->info(
            'STO0011 Setting quota for dataset',
            ['datasetName' => $datasetName, 'quotaInBytes' => $quotaInBytes]
        );
        $this->storage->setStorageProperties($datasetName, ['quota' => $quotaInBytes]);
    }

    /**
     * Get the quota of the specified dataset.
     *
     * @param string $datasetName
     * @return int
     */
    public function getDatasetQuota($datasetName = self::DEFAULT_QUOTA_DATASET)
    {
        $storageInfo = $this->storage->getStorageInfo($datasetName);
        return $storageInfo->getQuotaSizeInBytes();
    }

    /**
     * Trim all relevant mounted filesystems, based on the rotational status of every drive.
     * If all drives are non-rotational, the device is all SSD and we can trim all mounted filesystems.
     * Otherwise, only the OS drive is SSD and the OS drive filesystems will be trimmed.
     *
     * @return bool
     */
    public function trimSolidStateDriveFilesystems(): bool
    {
        try {
            $drives = $this->lsBlk->getDiskDrives();
        } catch (Exception $exception) {
            $this->logger->error('STO0013 The command to retrieve drive information failed');
            return false;
        }

        if (!$drives) {
            $this->logger->error('STO0014 No drives available');
            return false;
        }

        $onlySolidStateDrives = true;
        foreach ($drives as $drive) {
            $onlySolidStateDrives = $onlySolidStateDrives && !$drive->isRotational();
        }

        try {
            $this->logger->info('STO0015 Trimming filesystems');
            if ($onlySolidStateDrives) {
                // All drives are non-rotational, so trim ALL mounted file systems
                $this->trimFilesystem(self::ALL_MOUNTPOINTS);
            } else {
                // Some drives are rotational, so only trim filesystems on the SSD
                $this->trimFilesystem(self::ROOT_MOUNTPOINT);
                $this->trimFilesystem(self::HOST_MOUNTPOINT);
            }
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('STO0017 Unable to trim filesystem', ['exception' => $e]);
            return false;
        }
    }

    /**
     * Return a list of /dev/sdX disk names that are attached as data disks
     * according to imds, and are status = "available" according to the
     * os-2 storage service.
     *
     * @return string[]
     */
    public function getAzureDataDiskNames(): array
    {
        return array_values(array_map(
            function (StorageDevice $storageDevice) {
                return $storageDevice->getName();
            },
            $this->getAzureDataDisks([StorageDevice::STATUS_AVAILABLE])
        ));
    }

    /**
     * Return information about disks that are attached as data disks
     * according to imds, and are status = "available" according to the
     * os-2 storage service.
     *
     * @param int[] $desiredStatus
     *
     * @return AzureStorageDevice[]
     */
    public function getAzureDataDisks(array $desiredStatus): array
    {
        $imdsDisks = $this->instanceMetadataService->getDataDisks();
        $imdsStorageProfile = $this->instanceMetadataService->getStorageProfile();

        /** @var AzureStorageDevice[] */
        $outputDevices = [];

        foreach ($this->getDevices() as $storageDevice) {
            if ($this->isLikelyToBeAnAzureDataDisk($storageDevice, $imdsStorageProfile) &&
                in_array($storageDevice->getStatus(), $desiredStatus)
            ) {
                $imdsDisk = $this->getAzureDiskThatMatchesStorageDevice($storageDevice, $imdsDisks);
                $outputDevices[] = AzureStorageDevice::fromStorageDevice(
                    $storageDevice,
                    $imdsDisk->getName()
                );
            }
        }

        return $outputDevices;
    }

    /**
     * Note: This is a heuristic. Nothing currently prevents this inadvertently
     * detecting a temporary data disk as a pool member _if_ the data disk
     * happens to be the same size as the temporary disk. However, for the
     * DS1_v2 the temporary data disk is 7GB, and for now we are attaching
     * data disks that are >= 512GB. This heuristic should be revisited when
     * other VM SKUs are supported with potentially larger temporary data disks.
     */
    private function isLikelyToBeAnAzureDataDisk(
        StorageDevice $storageDevice,
        InstanceMetadataStorageProfile $imdsStorageProfile
    ): bool {
        return $storageDevice->getLunId() !== null
            && $storageDevice->getCapacity() !== $imdsStorageProfile->getTempDiskSizeInBytes();
    }

    /**
     * @param InstanceMetadataDisk[] $imdsDisks
     */
    private function getAzureDiskThatMatchesStorageDevice(
        StorageDevice $storageDevice,
        $imdsDisks
    ): InstanceMetadataDisk {
        $matches = array_values(array_filter(
            $imdsDisks,
            function (InstanceMetadataDisk $imdsDisk) use ($storageDevice) {
                return $imdsDisk->getLunId() === $storageDevice->getLunId();
            }
        ));

        if (count($matches) !== 1) {
            $this->logger->error(
                'STO0019 No IMDS match found for storage device',
                [
                    'storageDevice' => $storageDevice,
                    'matches' => $matches
                ]
            );
            throw new \RuntimeException('Couldn\'t find an IMDS match for ' . $storageDevice->getName());
        }

        return $matches[0];
    }

    /**
     * Trim a mounted filesystem
     *
     * @param string $filesystem
     */
    private function trimFilesystem(string $filesystem)
    {
        $process = $this->processFactory->get([self::FSTRIM_COMMAND, $filesystem, self::VERBOSE_OPTION]);
        $process->run();
        $outputArray = explode("\n", trim($process->getOutput()));
        foreach ($outputArray as $line) {
            $this->logger->info('STO0016 Trimmed FS', ['fileSystem' => $line]);
        }
    }

    private function createZpool(array $driveNames, $poolName)
    {
        $this->logger->info('STO0003 Creating pool with devices', ['driveNames' => $driveNames]);

        try {
            $poolCreationContext = new StoragePoolCreationContext(
                $poolName,
                $driveNames,
                storagePoolCreationContext::DEFAULT_MOUNTPOINT
            );
            $poolCreationContext->setFileSystemProperties(Zpool::DEFAULT_FILESYSTEM_PROPERTIES);
            $this->storage->createPool($poolCreationContext);
        } catch (ZpoolCreateException $zpoolCreateException) {
            $msg = 'Error creating storage pool: ' .
                $zpoolCreateException->getOutput() .
                ' (' .
                $zpoolCreateException->getCommand() .
                ' )';
            $this->logger->error('STO0004 Error creating storage pool', ['exception' => $zpoolCreateException]);
            throw new Exception($msg, 4, $zpoolCreateException);
        }
    }

    private function setCompressionOn($poolName)
    {
        $this->logger->info('STO0006 Setting compression on.');
        try {
            $this->storage->setStorageProperties($poolName, ['compression' => 'on']);
        } catch (Throwable $throwable) {
            $this->logger->error('STO0005 Error setting compression', ['exception' => $throwable]);
            throw new Exception('Error setting compression', 5, $throwable);
        }
    }

    private function createZfsHomeMountpoint($poolName)
    {
        $this->logger->info('STO0007 Creating mount point for home');

        try {
            $storageCreationContext = new StorageCreationContext(
                SirisStorage::HOME_STORAGE,
                SirisStorage::PRIMARY_POOL_NAME,
                StorageType::STORAGE_TYPE_FILE,
                StorageCreationContext::MAX_SIZE_DEFAULT,
                false,
                ['sync' => 'disabled', 'mountpoint' => '/home']
            );
            $datasetName = $this->storage->createStorage($storageCreationContext);
        } catch (Throwable $throwable) {
            $this->logger->error('STO0008 Error creating home mount point', ['exception' => $throwable]);
            throw new Exception('Error creating home mount point', 6, $throwable);
        }

        return $datasetName;
    }

    /**
     * Create the agents ZFS dataset during registration/auto-activation.
     *
     * This is to prevent a known ZFS race condition: https://kaseya.atlassian.net/browse/BCDR-24863
     * build-imaged devices will have the agents dataset created via the CreateAgentDataset config repair task.
     *
     * @param string $poolName
     */
    private function createZfsAgentMountpoint(string $poolName)
    {
        $this->logger->info('STO0020 Creating mount point for agents');

        try {
            $storageCreationContext = new StorageCreationContext(
                'agents',
                $this->sirisStorage->getHomeStorageId(),
                StorageType::STORAGE_TYPE_FILE,
                StorageCreationContext::MAX_SIZE_DEFAULT,
                false,
                ['mountpoint' => '/home/agents']
            );
            $this->storage->createStorage($storageCreationContext);
        } catch (Throwable $throwable) {
            $this->logger->error('STO0021 Error creating agents mount point', ['exception' => $throwable]);
            throw new Exception('Error creating agents mount point', 8, $throwable);
        }
    }

    private function createZfsTransferMountpoint($poolName)
    {
        $this->logger->info('STO0009 Creating mount point for transfer');

        try {
            $storageCreationContext = new StorageCreationContext(
                'transfer',
                SirisStorage::PRIMARY_POOL_NAME,
                StorageType::STORAGE_TYPE_FILE,
                StorageCreationContext::MAX_SIZE_DEFAULT,
                false,
                ['sync' => 'disabled', 'mountpoint' => '/datto/transfer']
            );
            $datasetName = $this->storage->createStorage($storageCreationContext);
        } catch (Throwable $throwable) {
            $this->logger->error('STO0010 Error creating transfer mount point', ['exception' => $throwable]);
            throw new Exception('Error creating transfer mount point', 7, $throwable);
        }
    }

    /**
     * Gets the partition mounted at a given mountpoint
     *
     * @param string $mountpoint
     * @return string
     */
    private function getFilesystemPartition($mountpoint): string
    {
        $process = $this->processFactory->get(['findmnt', $mountpoint, '-n', '--output', 'SOURCE']);
        $process->mustRun();
        return $process->getOutput();
    }

    /**
     * Strips the partition off and leaves you with the parent device (e.g. /dev/sda)
     *
     * @param string $fullPath e.g. /dev/sda12 or /dev/md0p12
     * @return string
     */
    private function stripPartition($fullPath): string
    {
        if (preg_match("|(\/dev\/md[0-9]*)p.*|", $fullPath, $matches)) {
            if (isset($matches[1])) {
                return $matches[1];
            }
        } elseif (preg_match("/(\/dev\/sd[a-z]+)[0-9*]/", $fullPath, $matches)) {
            if (isset($matches[1])) {
                return $matches[1];
            }
        } elseif (preg_match("/(\/dev\/nvme\d+n\d+)p[0-9*]/", $fullPath, $matches)) {
            if (isset($matches[1])) {
                return $matches[1];
            }
        }

        throw new \Exception("Unable to get parent device of " . $fullPath);
    }

    /**
     * Convert the pool devices status into nested arrays
     *
     * @param int $indentLevel Device depth level
     * @param StoragePoolDeviceStatus[] $poolDevices Pool device status tree
     */
    private function serializeStoragePoolDeviceStatusArray(int $indentLevel, array $poolDevices): array
    {
        $devices = [];
        foreach ($poolDevices as $poolDevice) {
            $device['indent_level'] = $indentLevel;
            $device['name'] = $poolDevice->getName();
            $device['state'] = $poolDevice->getState();
            $device['read'] = $poolDevice->getRead();
            $device['write'] = $poolDevice->getWrite();
            $device['cksum'] = $poolDevice->getChecksum();
            $device['note'] = $poolDevice->getNote();
            $devices[] = $device;

            $subDevices = $poolDevice->getSubDevices();
            if (!empty($subDevices)) {
                $devices = array_merge($devices, $this->serializeStoragePoolDeviceStatusArray($indentLevel + 2, $subDevices));
            }
        }
        return $devices;
    }
}
