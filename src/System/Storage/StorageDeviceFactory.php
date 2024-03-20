<?php

namespace Datto\System\Storage;

use Datto\Common\Resource\ProcessFactory;
use Datto\Config\DeviceConfig;
use Datto\Log\LoggerFactory;
use Datto\System\Hardware;
use Datto\System\Smart\SmartService;
use Datto\Common\Utility\Filesystem;
use Datto\Utility\Block\LsBlk;
use Datto\Utility\Storage\Zpool;
use Datto\Utility\Storage\ZpoolStatusParser;
use Datto\Virtualization\HypervisorType;
use Datto\ZFS\ZpoolService;
use Datto\ZFS\ZpoolStatus;
use Exception;
use Datto\Log\DeviceLoggerInterface;
use RuntimeException;
use Throwable;

/**
 * This class gets StorageDevices based on information gathered from the hardware.
 * @author Matt Cheman <mcheman@datto.com>
 */
class StorageDeviceFactory
{
    const SMART_ATTRIBUTE_POWER_ON_HOURS = 'Power_On_Hours';
    const DISK_BY_ID_PATH = '/dev/disk/by-id/';
    const BLOCK_DEVICE_PATH_SYSFS_FORMAT = '/sys/class/block/%s';
    const DEDICATED_TRANSFER_MOUNTPOINT = '/datto/transfer-os';

    /** @var Hardware $hardware */
    private $hardware;

    private ProcessFactory $processFactory;

    /** @var Filesystem  */
    private $filesystem;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var SmartService */
    private $smartService;

    /** @var ZpoolService|null */
    private $zpoolService;

    /** @var array */
    private $hypervisors;

    /** @var null|string[] */
    private $cachedMdadmSlaves = null;

    /** @var null|ZpoolStatus */
    private $cachedCacheDriveIds = null;

    /** @var DeviceConfig|null */
    private $deviceConfig;

    /** @var Zpool */
    private $zpool;

    /** @var LsBlk */
    private $lsblk;

    public function __construct(
        Hardware $hardware = null,
        ProcessFactory $processFactory = null,
        Filesystem $filesystem = null,
        DeviceLoggerInterface $logger = null,
        SmartService $smartService = null,
        ZpoolService $zpoolService = null,
        DeviceConfig $deviceConfig = null,
        Zpool $zpool = null,
        LsBlk $lsBlk = null
    ) {
        $this->processFactory = $processFactory ?: new ProcessFactory();
        $this->hardware = $hardware ?: new Hardware($this->processFactory);
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->logger = $logger ?: LoggerFactory::getDeviceLogger();
        $this->smartService = $smartService ?: new SmartService();
        $this->zpoolService = $zpoolService ?: new ZpoolService($this->logger);
        $this->deviceConfig = $deviceConfig ?: new DeviceConfig();
        $this->hypervisors = [
            HypervisorType::VMWARE => 'VMware Virtual Disk',
            HypervisorType::HYPER_V => 'Hyper-V Virtual Disk',
        ];
        $this->zpool = $zpool ?: new Zpool($this->processFactory, new ZpoolStatusParser($this->processFactory));
        $this->lsblk = $lsBlk ?: new LsBlk($this->processFactory);
    }

    /**
     * Build a StorageDevice object for a particular device.
     *
     * @param string $fullPath The name of the device. Ex: /dev/sda
     * @return StorageDevice
     */
    public function getStorageDevice($fullPath)
    {
        $shortName = basename($fullPath);
        $isVirtual = $this->deviceConfig->has('isVirtual');
        $model = $this->detectModel($fullPath, $isVirtual);
        $serial = $this->detectSerial($fullPath, $isVirtual);
        $ids = $this->getBlockDeviceIds($fullPath);
        $id = $this->determineCorrectId($ids);
        $status = $this->detectStatus($fullPath, $serial, $id);
        $capacity = $this->detectCapacity($fullPath, $status);
        $hostAndLun = $this->detectScsiHostNumberAndLunId($fullPath);
        $isRotational = null;
        $smartData = null;

        try {
            $isRotational = $this->isRotational($fullPath);
        } catch (\Exception $exception) {
            $this->logger->warning('SDF0001 Error retrieving rotational attribute of storage device', ['exception' => $exception]);
        }

        try {
            $smartData = $this->smartService->getDiskData($fullPath);
            if ($isRotational === false) {
                $smartData["attributes"][static::SMART_ATTRIBUTE_POWER_ON_HOURS] = -1;
            }
        } catch (\Exception $exception) {
            $this->logger->warning('SDF0002 Error getting smart data', ['exception' => $exception]);
        }

        return new StorageDevice(
            $fullPath,
            $model,
            $capacity,
            $serial,
            $status,
            $isVirtual,
            $shortName,
            $isRotational,
            $smartData,
            $id,
            $ids,
            $hostAndLun[0],
            $hostAndLun[1]
        );
    }

    /**
     * Determine if a drive is rotational.
     *
     * @param string $fullPath Path of the drive device to get information about.
     * @return bool true if rotational, false if not
     */
    public function isRotational($fullPath)
    {
        $driveName = str_replace('/dev/', '', $fullPath);
        $file = "/sys/block/$driveName/queue/rotational";
        if ($this->filesystem->exists($file)) {
            $isRotational = trim($this->filesystem->fileGetContents($file));
            return ($isRotational === '1');
        } else {
            throw new \Exception("Can't find rotational info for drive: $driveName");
        }
    }

    /**
     * Detects the model of the drive.
     *
     * @param $name
     * @param $isVirtual
     * @return mixed|string
     */
    private function detectModel($name, $isVirtual)
    {
        if ($isVirtual) {
            return $this->hypervisors[$this->hardware->detectHypervisor()->value()];
        }

        $process = $this->processFactory->get(['smartctl', '-i', $name]);
        $process->run();

        $matches = [];
        preg_match("/Device Model:(.+)\n/", $process->getOutput(), $matches);
        return count($matches) > 1 ? trim($matches[1]) : 'Unknown';
    }

    /**
     * Detect the drive's serial number. Virtual devices do not have serial numbers.
     *
     * @param $name
     * @param $isVirtual
     * @return null|string
     */
    private function detectSerial($name, $isVirtual)
    {
        if ($isVirtual) {
            return null;
        }

        // only physical drives have serial number
        $process = $this->processFactory->get(['smartctl', '-i', $name]);
        $process->run();

        $matches = [];

        // Insensitive match because we've seen both "Serial Number: X" and "Serial number: X"
        preg_match("/Serial Number:(.+)\n/i", $process->getOutput(), $matches);
        return count($matches) > 1 ? trim($matches[1]) : 'Unknown';
    }

    /**
     * Since there are more than one possible block device id's, if one in particular is used by zpool,
     * use that one. If one is not found, return the first one to preserve previous functionality.
     *
     * @param string[] $ids
     * @return string
     */
    private function determineCorrectId(array $ids) : string
    {
        try {
            $poolStatus = $this->zpool->getStatus(Zpool::ZPOOL_STATUS_ALL, Zpool::USE_FULL_PATH);
        } catch (Throwable $throwable) {
            return '';
        }

        foreach ($ids as $id) {
            $containsId = !empty($id) && strpos($poolStatus, $id) !== false;
            if ($containsId) {
                return $id;
            }
        }

        return count($ids) > 0 ? $ids[0] : '';
    }

    /**
     * Detect if the device is an os drive, part of a zpool, contains a swap partition, or is an optical drive.
     * @param $name
     * @param $serial
     * @param $id
     * @return int
     */
    private function detectStatus($name, $serial, $id)
    {
        if ($this->isOsDrive($name)) {
            return StorageDevice::STATUS_OS_DRIVE;
        } elseif ($this->isCacheDrive($id)) {
            return StorageDevice::STATUS_CACHE_DRIVE;
        } elseif ($this->isInZPool($name, $serial, $id)) {
            return StorageDevice::STATUS_POOL;
        } elseif ($this->isSpecial($name)) {
            return StorageDevice::STATUS_SPECIAL_DRIVE;
        } elseif ($this->isOpticalDrive($name)) {
            return StorageDevice::STATUS_OPTICAL_DRIVE;
        } elseif ($this->isTransferDrive($name)) {
            return StorageDevice::STATUS_TRANSFER_DRIVE;
        } else {
            return StorageDevice::STATUS_AVAILABLE;
        }
    }

    /**
     * Detect the device's capacity in bytes
     *
     * @param $name
     * @param $status
     * @return float|int
     */
    private function detectCapacity($name, $status)
    {
        // testing an optical drive would make blockdev complain.
        if ($status === StorageDevice::STATUS_OPTICAL_DRIVE) {
            return 0;
        }

        // get drive capacity
        $process = $this->processFactory->get(['blockdev', '--getsize64', $name]);
        $process->run();

        return intval(trim($process->getOutput()));
    }

    /**
     * Determine is this storage device contains the operating system for this device
     *
     * @param $name
     * @return bool true if this is the OS drive
     */
    private function isOsDrive($name)
    {
        $process = $this->processFactory->get(['mount']);
        $process->run();

        $isOsDrive = preg_match("~{$name}[0-9]* on /host type~", $process->getOutput()) === 1;
        $isOsMdadmSlave = in_array($name, $this->getMdadmSlaves());

        return $isOsDrive || $isOsMdadmSlave;
    }

    /**
     * Determine if this storage device (partition) is "special" aka swap or extended
     *
     * @param $name
     * @return bool
     */
    private function isSpecial($name)
    {
        $process = $this->processFactory->get(['fdisk', '-l']);
        $process->run();

        return preg_match("~{$name}.*(swap|Extended)~", $process->getOutput()) === 1;
    }

    /**
     * Checks whether the block device is an optical drive.
     *
     * @param $name
     * @return bool
     */
    private function isOpticalDrive($name)
    {
        $process = $this->processFactory->get(['lsscsi']);
        $process->run();

        return preg_match("~cd/dvd.*{$name}~", $process->getOutput()) === 1;
    }

    /**
     * Determine if this drive is currently part of the zpool on this device
     *
     * @param $name
     * @param $serial
     * @param $id
     * @return bool true if this drive is currently part of the zpool
     */
    private function isInZPool($name, $serial, $id)
    {
        // this needs to work for both physical and virtual devices. For virtual devices,
        // the hard drive does not have a serial number so we need to use the drive name instead
        try {
            $poolStatus = $this->zpool->getStatus(Zpool::ZPOOL_STATUS_ALL, Zpool::USE_FULL_PATH);
        } catch (Throwable $throwable) {
            $poolStatus = '';
        }

        $containsSerial = !empty($serial) && strpos($poolStatus, $serial) !== false;
        $containsId = !empty($id) && strpos($poolStatus, $id) !== false;
        $containsName = !empty($name) && strpos($poolStatus, $name) !== false;

        return $containsSerial || $containsId || $containsName;
    }

    /**
     * Determine if a drive is a dedicated transfer drive.
     *
     * @param string $name
     * @return bool
     */
    private function isTransferDrive($name)
    {
        $lines = explode("\n", $this->filesystem->fileGetContents('/proc/mounts'));

        foreach ($lines as $line) {
            // Example line:
            // /dev/zd16p1 /datto/mounts/TestShare1 ext4 rw,relatime,discard,stripe=32,data=ordered 0 0
            if (preg_match('/^(\S+)\s+(\S+)/', $line, $match)) {
                $fullName = $match[1]; // Includes '/dev/' prefix
                $mountpoint = $match[2];

                $hasMatchingName = strpos($fullName, $name) === 0;
                $isTransferMountpoint = $mountpoint === self::DEDICATED_TRANSFER_MOUNTPOINT;

                if ($isTransferMountpoint && $hasMatchingName) {
                    // Found our transfer drive
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Determine if a drive is used as a zpool cache.
     *
     * @param string $id
     * @return bool
     */
    private function isCacheDrive($id)
    {
        if (!isset($this->cachedCacheDriveIds)) {
            try {
                $status = $this->zpoolService->getZpoolStatus(ZpoolService::HOMEPOOL);
                $this->cachedCacheDriveIds = $status->getCacheDriveIds();
            } catch (Exception $e) {
                $this->cachedCacheDriveIds = [];
            }
        }

        return in_array($id, $this->cachedCacheDriveIds);
    }

    /**
     * Get the block device's matching /dev/disk/by-id/ counterpart.
     *
     * @param string $name
     * @return string[]
     */
    private function getBlockDeviceIds(string $name): array
    {
        // If $name is already a /dev/disk/by-id/... path we want to get its backing path (ex: /dev/sda) so we can
        // ensure we find the complete list of ids for this device.
        $realName = $this->filesystem->realpath($name);

        $blockDeviceIds = [];
        // Iterate through all symlinks in /dev/disk/by-id and see if any link to the block device
        $deviceIdPaths = $this->filesystem->glob(static::DISK_BY_ID_PATH . '*');
        foreach ($deviceIdPaths as $deviceIdPath) {
            if ($this->filesystem->isLink($deviceIdPath)) {
                $destination = $this->filesystem->realpath($deviceIdPath);

                if ($destination === $realName) {
                    $deviceId = basename($deviceIdPath);
                    $blockDeviceIds[] = $deviceId;
                }
            }
        }

        return $blockDeviceIds;
    }

    /**
     * Determine if the given device is a partition.
     *
     * Examples:
     *      md0     => false
     *      md0p1   => true
     *      sda     => false
     *      sda1    => true
     *
     * @param string $deviceName
     * @return bool
     */
    private function isPartition($deviceName)
    {
        $partitionAttrPath = sprintf(self::BLOCK_DEVICE_PATH_SYSFS_FORMAT . '/partition', $deviceName);
        $isPartition = $this->filesystem->exists($partitionAttrPath);

        return $isPartition;
    }

    /**
     * Get the parent device of a partition. Eg. md0p1 => md0, sda1 => sda
     *
     * @param string $deviceName
     * @return string
     */
    private function getParent($deviceName)
    {
        $sysfsPath = sprintf(self::BLOCK_DEVICE_PATH_SYSFS_FORMAT, $deviceName);

        // The symlink will look something like this:
        //   ../../devices/virtio-pci/virtio0/block/vda/vda1
        // In this case vda is the parent device of vda1.
        $symlinkPath = $this->filesystem->readlink($sysfsPath);
        $parentDevice = basename(dirname($symlinkPath));

        return $parentDevice;
    }

    /**
     * Determine any devices (*not partitions*) that are part of an mdadm array on the system.
     *
     * For example, if "/dev/sda1" and "/dev/sdb" are used to form the mdadm array
     * "/dev/md0", ["/dev/sda","/dev/sdb"] will be returned.
     *
     * @return string[] Full device names prefixed with /dev/ (eg. "/dev/sda")
     */
    private function getMdadmSlaves()
    {
        if (!isset($this->cachedMdadmSlaves)) {
            $slavesDir = sprintf(self::BLOCK_DEVICE_PATH_SYSFS_FORMAT . '/slaves/*', 'md*');

            $parentMapper = function ($deviceName) {
                return $this->isPartition($deviceName) ? $this->getParent($deviceName) : $deviceName;
            };

            $basenameMapper = function ($device) {
                return basename($device);
            };

            $fullNameMapper = function ($deviceName) {
                return '/dev/' . $deviceName;
            };

            $slaves = $this->filesystem->glob($slavesDir);
            $slaves = array_map($basenameMapper, $slaves);
            $slaves = array_map($parentMapper, $slaves);
            $slaves = array_map($fullNameMapper, $slaves);

            $this->cachedMdadmSlaves = $slaves;
        }

        return $this->cachedMdadmSlaves;
    }

    /**
     * Determine the logical unit number of the specified block device.
     *
     * @param string $name The name of the device. Ex: /dev/sda
     *
     * @return (int|null)[]
     */
    private function detectScsiHostNumberAndLunId(string $name)
    {
        try {
            $blockDevice = $this->lsblk->getBlockDeviceByPath($name);
            $hostNum = $blockDevice->getScsiHostNumber();
            $lunId = $blockDevice->getLunId();
        } catch (RuntimeException $exception) {
            $hostNum = null;
            $lunId = null;
        }

        return [$hostNum, $lunId];
    }
}
