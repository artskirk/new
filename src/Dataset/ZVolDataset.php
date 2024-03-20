<?php

namespace Datto\Dataset;

use Datto\Asset\Asset;
use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Resource\Sleep;
use Datto\Common\Utility\Block\Blockdev;
use Datto\Common\Utility\ByteUnit;
use Datto\Common\Utility\Filesystem;
use Datto\Core\Storage\SirisStorage;
use Datto\Core\Storage\StorageCreationContext;
use Datto\Core\Storage\StorageInterface;
use Datto\Core\Storage\StorageType;
use Datto\System\MountManager;
use Datto\ZFS\ZfsService;
use Exception;
use Throwable;

/**
 * Dataset class dedicated to SnapNAS ZVol implementation (for now, to be extended)
 *
 * @deprecated Use StorageInterface instead
 */
class ZVolDataset extends Dataset
{
    /**
     * The base device block directory
     * @const BLK_BASE_DIR  Device Block directory
     */
    const BLK_BASE_DIR = '/dev/zvol';

    const MKDIR_MODE = 0777;

    private Sleep $sleep;
    private Blockdev $blockdev;
    private ProcessFactory $processFactory;

    public function __construct(
        string $zfsPath,
        Filesystem $filesystem,
        ZfsService $zfsService,
        MountManager $mountManager,
        Sleep $sleep,
        Blockdev $blockdev,
        StorageInterface $storage,
        SirisStorage $sirisStorage,
        ProcessFactory $processFactory
    ) {
        parent::__construct($zfsPath, $filesystem, $zfsService, $mountManager, $storage, $sirisStorage);

        $this->sleep = $sleep;
        $this->blockdev = $blockdev;
        $this->processFactory = $processFactory;
    }

    /**
     * Create a ZVol with a specified size, partition it, then format
     *
     * @deprecated Use StorageInterface instead
     * @param string $size The size to create the ZVol (ex. 4T, 100G, etc)
     * @param string $format The format of the ZVol ('ext4','ntfs')
     */
    public function create($size, $format)
    {
        try {
            $nameAndParentId = $this->sirisStorage->getNameAndParentIdFromStorageId($this->zfsPath);
            $shortName = $nameAndParentId['name'];
            $parentId = $nameAndParentId['parentId'];

            $storageCreationContext = new StorageCreationContext(
                $shortName,
                $parentId,
                StorageType::STORAGE_TYPE_BLOCK,
                ByteUnit::convertSizeStringToBytes($size)
            );
            $this->storage->createStorage($storageCreationContext);

            $this->waitForDevice($this->getBlockDevice());
            $this->assertZVolExists();

            if ($format !== null) {
                $this->createPartition($format);
                $this->formatPartition($format);
            }
        } catch (Throwable $throwable) {
            $this->logger->error(
                'ZVD0001 There was an issue creating the zvol',
                ['storagePath' => $this->zfsPath, 'exception' => $throwable]
            );
            throw new Exception(
                'There was an issue creating the zvol: ' . $throwable->getMessage(),
                $throwable->getCode(),
                $throwable
            );
        }
    }

    /**
     * Destroy the dataset
     *
     * @deprecated Use StorageInterface instead
     */
    public function destroy()
    {
        if ($this->mountManager->isMounted($this->getMountPoint())) {
            throw new Exception('Cannot destroy dataset without unmounting it first.');
        }

        if ($this->storage->storageExists($this->zfsPath)) {
            $blockDevice = $this->getBlockDevice();
            if ($blockDevice) {
                $this->blockdev->flushBuffers($blockDevice);
            }

            $this->storage->destroyStorage($this->zfsPath, true);
        }
    }

    /**
     * Mount the dataset to the given path
     *
     * @param string $mountPath The path to mount the dataset to
     * @return bool Whether or not the dataset was properly mounted
     */
    public function mount($mountPath = null): bool
    {
        if ($mountPath === null) {
            throw new Exception('Cannot mount dataset to a null path');
        }

        if ($this->isMounted()) {
            return true;
        }

        $dirCreated = $this->filesystem->mkdirIfNotExists($mountPath, true, self::MKDIR_MODE);

        $result = $this->mountManager->mountDevice($this->getPartitionBlockLink(), $mountPath, 0
            | MountManager::MOUNT_OPTION_DISCARD
            | MountManager::MOUNT_OPTION_ACL
            | MountManager::MOUNT_OPTION_USER_XATTR);

        if ($result->mountFailed()) {
            if ($dirCreated) {
                $this->filesystem->rmdir($mountPath);
            }

            throw new Exception('The partition ' . $this->getPartitionBlockLink() . ' could not be mounted to ' . $mountPath);
        }

        if (!$this->isMounted()) {
            throw new Exception($this->getPartitionBlockDevice() . ' is not currently mounted.');
        }

        return true;
    }

    public function getLiveDatasetBasePath(): string
    {
        return Asset::BASE_MOUNT_PATH;
    }

    /**
     * Returns the path to the underlying block device for this dataset
     *
     * @return false|string The block device, or false if it doesn't exist
     */
    public function getBlockDevice()
    {
        return $this->filesystem->realpath($this->getBlockLink());
    }

    /**
     * Returns the ZFS-created symlink to the dataset's block device
     *
     * @return string The symlink to this Dataset's Block Device
     */
    public function getBlockLink(): string
    {
        return self::BLK_BASE_DIR . '/' . $this->zfsPath;
    }

    /**
     * Returns the path to the underlying partition block device for this dataset
     *
     * @return false|string The block device, or false if it doesn't exist
     */
    public function getPartitionBlockDevice()
    {
        return $this->filesystem->realpath($this->getPartitionBlockLink());
    }

    /**
     * Returns the ZFS-created symlink to the dataset's partition
     *
     * @return string The partition block link
     */
    public function getPartitionBlockLink(): string
    {
        return $this->getBlockLink() . '-part1';
    }

    /**
     * @return false|string mountpoint or false on failure
     */
    public function getMountPoint()
    {
        return $this->mountManager->getDeviceMountPoint($this->getPartitionBlockDevice());
    }

    /**
     * Gets the format of the partition's block device.
     *
     * @return string The partition format
     */
    public function getPartitionFormat(): string
    {
        return $this->mountManager->getFilesystemType($this->getPartitionBlockDevice());
    }

    /**
     * Verifies that this ZVol exists on the disk
     */
    public function assertZVolExists()
    {
        if (!$this->storage->storageExists($this->zfsPath)) {
            throw new Exception($this->zfsPath . ' could not be verified and may not exist.');
        }

        if (!$this->filesystem->isLink($this->getBlockLink())) {
            throw new Exception($this->getBlockLink() . ' is not a valid link.');
        }

        if (!$this->filesystem->isBlockDevice($this->getBlockDevice())) {
            throw new Exception($this->getBlockDevice() . ' is not a block device.');
        }
    }

    /**
     * Verifies that a single partition spanning this ZVol's full size has been created.
     */
    public function assertZvolIsPartitioned()
    {
        if (!$this->filesystem->isLink($this->getPartitionBlockLink())) {
            throw new Exception($this->getPartitionBlockLink() . ' could not be verified and may not exist.');
        }

        if (!$this->filesystem->isBlockDevice($this->getPartitionBlockDevice())) {
            throw new Exception($this->getPartitionBlockDevice() . ' is not a block device.');
        }
    }

    /**
     * Format the device block symlink with a specified format
     *
     * @param string $format The desired format ('ext4','ntfs')
     */
    private function formatPartition(string $format = 'ext4')
    {
        $device = $this->getPartitionBlockDevice();
        $this->logger->debug('ZVD0004 Formatting partition.', ['device' => $device, 'format' => $format]);
        switch ($format) {
            case 'ntfs':
                $process = $this->processFactory
                    ->get(['mkntfs', '-f', $device])
                    ->setTimeout(self::HOUR_TIMEOUT)
                    ->mustRun();
                $output = $process->getOutput();

                if (strpos($output, 'mkntfs completed successfully') === false) {
                    throw new Exception('Could not format the partition: ' . $output);
                }
                break;

            default:
            case 'ext4':
                $this->processFactory
                    // Do not discard on mkfs because of poor zfs performance.
                    ->get(['mkfs.ext4', '-E', 'nodiscard', $device])
                    ->setTimeout(self::HOUR_TIMEOUT)
                    ->mustRun();
                break;
        }

        $this->assertPartitionIsFormat($format);
    }

    /**
     * Create a single partition spanning this ZVol's full size.
     *
     * @param string $format
     */
    private function createPartition(string $format = 'ext4')
    {
        $device = $this->getBlockDevice();
        $this->logger->debug('ZVD0002 Creating device partition.', ['device' => $device, 'format' => $format]);
        try {
            $this->processFactory->get([
                'parted', '-s', '-a', 'optimal', $device, 'mklabel', 'gpt', '--', 'mkpart', 'primary', $format, 1, -1
            ])
            ->setTimeout(self::HOUR_TIMEOUT)
            ->mustRun();
        } catch (Throwable $throwable) {
            $this->logger->error('ZVD0006 Could not create partition', ['exception' => $throwable]);
            throw new Exception(
                'Could not create partition: ' . $throwable->getMessage(),
                $throwable->getCode(),
                $throwable
            );
        }

        try {
            $this->processFactory->get(['partprobe', $device])->setTimeout(300)->mustRun();
        } catch (Throwable $throwable) {
            // This does not necessarily mean that the overall operation will fail, so log a warning, but don't fail
            $this->logger->warning('ZVD0005 Probing Partition table failed', ['exception' => $throwable]);
        }

        // At this point, we've done all we can to get this partition to show up, but we still have to wait for it
        $this->waitForDevice($this->getPartitionBlockLink());
    }

    /**
     * Verifies the format of the device block link
     *
     * @param string $format The format it should be
     */
    private function assertPartitionIsFormat(string $format)
    {
        $partitionFormat = $this->getPartitionFormat();
        if ($partitionFormat !== $format) {
            throw new Exception("Partition format ($partitionFormat) does not match expected ($format)");
        }
    }

    /**
     * Wait for a block device to exist, so that we can perform an operation on it. If the
     * 5 second timeout expires before the block device is valid, an exception will be thrown.
     *
     * @param string $device The block device (or symlink to one) to wait for
     */
    private function waitForDevice(string $device)
    {
        $timeoutMs = 5000;

        while (!$this->isBlockDeviceValid($device)) {
            $this->sleep->msleep(100);
            $timeoutMs -= 100;

            if ($timeoutMs <= 0) {
                throw new Exception('Timed out waiting for block device ' . $device);
            }
        }
    }

    /**
     * Determine if a block device or link to a block device is valid.
     *
     * @param string $device The block device (or symlink to a block device) to validate
     * @return bool true if the link is valid, and its target is a valid block device, false otherwise
     */
    private function isBlockDeviceValid(string $device): bool
    {
        // Realpath can return a string with the real path, or false on an error
        // (bad symlink, nonexistent file, etc...), so handle that.
        $real = $this->filesystem->realpath($device);
        return
            ($real !== false) &&
            $this->filesystem->exists($real) &&
            $this->filesystem->isBlockDevice($real);
    }
}
