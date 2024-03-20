<?php
namespace Datto\System;

use Datto\Config\DeviceConfig;
use Datto\Common\Resource\ProcessFactory;
use Datto\Log\LoggerFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Common\Resource\PosixHelper;
use Datto\Common\Resource\Sleep;
use Datto\Utility\File\Lsof;
use Datto\Utility\Process\ProcessCleanup;
use LogicException;
use Datto\Log\DeviceLoggerInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * This class can be used to manage mounts on the device.
 *
 * @author John Roland <jroland@datto.com>
 * @author Pim Otte <potte@datto.com>
 * @author Michael Meyer <mmeyer@datto.com>
 */
class MountManager
{
    /*
     * Available mount options
     * Note: options may not be applicable to every filesystem
     */
    const MOUNT_OPTION_IGNORE_CASE = 0x1;  // 0000 0001
    const MOUNT_OPTION_READ_ONLY   = 0x2;  // 0000 0010
    const MOUNT_OPTION_NO_UUID     = 0x4;  // 0000 0100
    const MOUNT_OPTION_FORCE       = 0x8;  // 0000 1000
    const MOUNT_OPTION_DISCARD     = 0x10; // 0001 0000
    const MOUNT_OPTION_ACL         = 0x20; // 0010 0000
    const MOUNT_OPTION_USER_XATTR  = 0x40; // 0100 0000

    /*
     * Filesystem type constants
     * Must match the output blkid's TYPE attribute
     */
    const FILESYSTEM_TYPE_NTFS = 'ntfs';
    const FILESYSTEM_TYPE_XFS = 'xfs';
    const FILESYSTEM_TYPE_CIFS = 'cifs'; // not a block device type, remotely mounted SAMBA share

    /** @var ProcessFactory */
    private $processFactory;

    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var Sleep */
    private $sleep;

    /** @var ProcessCleanup */
    private $processCleanup;

    /** @var DeviceLoggerInterface */
    private $logger;

    public function __construct(
        ProcessFactory $processFactory = null,
        DeviceConfig $deviceConfig = null,
        Sleep $sleephandler = null,
        ProcessCleanup $processCleanup = null,
        DeviceLoggerInterface $logger = null
    ) {
        $this->processFactory = $processFactory ?? new ProcessFactory();
        $this->deviceConfig = $deviceConfig ?? new DeviceConfig();
        $this->sleep = $sleephandler ?? new Sleep();
        $this->processCleanup = $processCleanup ?? new ProcessCleanup(
            new Lsof(),
            new PosixHelper($this->processFactory),
            $this->sleep,
            new Filesystem($this->processFactory)
        );
        $this->logger = $logger ?? LoggerFactory::getDeviceLogger();
    }

    /**
     * Get all known mounts to the system.
     *
     * @param string $type
     *  (Optional) A filsystem type string to filter mounts by. If not provided,
     *  all mounted filesystems will be returned.
     *
     * @return Mount[]
     */
    public function getMounts($type = null)
    {
        if ($type) {
            $process = $this->processFactory
                ->get(['mount', '-l', '-t', $type]);
        } else {
            $process = $this->processFactory
                ->get(['mount', '-l']);
        }

        $process->enableOutput();
        $process->run();

        if ($process->isSuccessful()) {
            $output = $process->getOutput();
        } else {
            throw new ProcessFailedException($process);
        }

        $outputLines = explode(PHP_EOL, trim($output));

        $mounts = [];
        foreach ($outputLines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) >= 3) {
                $mountPoint = $parts[2];
                $device = $parts[0];
                $fileSystem = $parts[4];
                $mountInfo = new Mount($mountPoint, $device, $fileSystem);
                $mounts[] = $mountInfo;
            }
        }

        return $mounts;
    }

    /**
     * Returns a mount point for given block device.
     *
     * @param string $blockDevice
     *  Absolute path to the block device.
     *
     * @return string|bool
     *  False if mount point was not found.
     */
    public function getDeviceMountPoint(string $blockDevice)
    {
        $mounts = $this->getMounts();
        foreach ($mounts as $mount) {
            if ($mount->getDevice() === $blockDevice) {
                return $mount->getMountPoint();
            }
        }

        return false;
    }

    /**
     * Returns the block device for a given mount point.
     *
     * @param string $mountPoint
     *  Absolute path to the mount point of a block device.
     *
     * @return string|bool
     *  False if a device is not mounted to the given mount point.
     */
    public function getMountPointDevice(string $mountPoint)
    {
        $mounts = $this->getMounts();
        foreach ($mounts as $mount) {
            if ($mount->getMountPoint() === $mountPoint) {
                return $mount->getDevice();
            }
        }
        return false;
    }

    /**
     * Get the filesystem type of the given block device/partition.
     * Return examples: 'ntfs', 'ext4, 'vfat'
     *
     * Returns NULL if the filesystem type could not be determined (non-existent).
     *
     * @param string $blockDevice
     * @return string|null
     */
    public function getFilesystemType($blockDevice)
    {
        $blkidProcess = $this->processFactory
            ->get([
                'blkid',
                '-s',
                'TYPE', // limit returned attributes to filesystem type only
                '-p',   // low-probe to avoid any issues with outdated cache
                $blockDevice     // block device (partition) to probe
            ]);

        $blkidProcess->run();

        if ($blkidProcess->isSuccessful()) {
            $lowercaseOutput = strtolower($blkidProcess->getOutput());
            if (preg_match('/type="(\w+)"/', $lowercaseOutput, $match)) {
                return $match[1];
            }
        }
        return null;
    }

    /**
     * Tests if a mountPoint is associated with an active mount.
     *
     * @param string $mountPoint
     * @return bool  true if mounted, false if unmounted or directory does not exist
     */
    public function isMounted(string $mountPoint)
    {
        if (empty($mountPoint)) {
            return false;
        }

        $process = $this->processFactory
            ->get([
                'mountpoint',
                '-q', // quiet
                $mountPoint
            ]);
        $process->run();
        return ($process->getExitCode() == 0);
    }

    /**
     * Mount something which provides a specialized builder for the mount. When dealing with
     * block devices, use the mountDevice method instead.
     *
     * @param MountableInterface $mountable
     * @param string $mountPoint
     * @return MountResult
     */
    public function mount(MountableInterface $mountable, string $mountPoint)
    {
        if ($this->isMounted($mountPoint)) {
            throw new LogicException("Attempted to mount $mountPoint when it is already mounted.");
        }
        $mountArguments = $mountable->getMountArguments();
        $mountProcess = $this->processFactory
            ->get(array_merge(['mount'], $mountArguments, [$mountPoint]));
        try {
            $mountProcess->run();
        } catch (ProcessTimedOutException $exception) {
        }

        return new MountResult($mountProcess);
    }

    /**
     * Attempt to mount the given block device.
     *
     * Options are given as a bitmask. For example:
     *     mountDevice(..., MountManager::MOUNT_OPTION_IGNORE_CASE | MountManager::MOUNT_OPTION_READ_ONLY);
     *
     * You may pass an array variable in for the $output parameter, or null.
     * If this value is _not_ null, it will be filled with details about the executed command.
     *
     * @param string $blockDevice
     * @param string $mountPoint
     * @param int $options
     * @return MountResult
     */
    public function mountDevice($blockDevice, $mountPoint, $options = 0)
    {
        // Prerequisite information
        $filesystemType = $this->getFilesystemType($blockDevice);

        // Determine requested options
        $optionIgnoreCase = $options & static::MOUNT_OPTION_IGNORE_CASE;
        $optionReadOnly = $options & static::MOUNT_OPTION_READ_ONLY;
        $optionNoUUID = $options & static::MOUNT_OPTION_NO_UUID;
        $optionForce = $options & static::MOUNT_OPTION_FORCE;
        $optionDiscard = $options & static::MOUNT_OPTION_DISCARD;
        $optionAcl = $options & static::MOUNT_OPTION_ACL;
        $optionUserXattr = $options & static::MOUNT_OPTION_USER_XATTR;

        // Construct options we will pass to mount
        $mountType = null;
        $mountOptions = [];

        if ($filesystemType === static::FILESYSTEM_TYPE_NTFS) {
            if ($optionIgnoreCase) {
                $mountType = 'lowntfs-3g';
                array_push($mountOptions, 'ignore_case');
            } else {
                $mountType = 'ntfs-3g';
            }
            if ($optionForce) {
                array_push($mountOptions, 'remove_hiberfile');
            }
        } elseif ($filesystemType === static::FILESYSTEM_TYPE_XFS) {
            if ($optionNoUUID) {
                array_push($mountOptions, 'nouuid');
            }
        }

        // Every filesystem driver I know of supports the ro option
        if ($optionReadOnly) {
            array_push($mountOptions, 'ro');
        }

        if ($optionDiscard) {
            array_push($mountOptions, 'discard');
        }

        if ($optionAcl) {
            array_push($mountOptions, 'acl');
        }

        if ($optionUserXattr) {
            array_push($mountOptions, 'user_xattr');
        }

        // Create the process builder with correct options
        $commandLineArgs = ['mount'];
        if ($mountType !== null) {
            $commandLineArgs[] = '-t';
            $commandLineArgs[] = $mountType;
        }
        if (!empty($mountOptions)) {
            $commandLineArgs[] = '-o';
            $commandLineArgs[] = implode(',', $mountOptions);
        }
        $commandLineArgs[] = $blockDevice;
        $commandLineArgs[] = $mountPoint;

        // Execute
        $mountProcess = $this->processFactory
            ->get($commandLineArgs);
        $mountProcess->run();

        return new MountResult($mountProcess);
    }

    /**
     * Unmount the given path (device or directory)
     */
    public function unmount(string $path)
    {
        $this->processCleanup->killProcessesUsingDirectory($path, $this->logger);
        $this->processFactory
            ->get(['umount', '-f', $path])
            ->mustRun();
    }
}
