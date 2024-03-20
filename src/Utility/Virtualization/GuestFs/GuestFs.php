<?php

namespace Datto\Utility\Virtualization\GuestFs;

use Throwable;

/**
 * Class: GuestFs
 *
 * A class that contains core libguestfs API functionality.
 * The instance of this class is being injected as a dependency for other
 * classes that provide access to more specialized APIs. Therefore, use the
 * GuestFsFactory to obtain instances of those classes as it handles DI.
 *
 * @final
 */
final class GuestFs extends GuestFsErrorHandler
{
    protected $handle = null;

    public function __construct(array $drivePaths, bool $readOnly)
    {
        // make sure guestFS appliance goes down even on PHP error.
        register_shutdown_function(array($this, '__destruct'));

        // First acquire a library handle
        $this->handle = $this->throwOnFalse(guestfs_create());

        try {
            // Next, add the drives to libguestfs and launch it
            foreach ($drivePaths as $drivePath) {
                $this->addDrive($drivePath, $readOnly);
            }
            $this->launch();
        } catch (Throwable $throwable) {
            // Some kind of an error while constructing. Just shut down the library and re-throw
            $this->shutDown();
            throw $throwable;
        }
    }

    public function __destruct()
    {
        $this->shutDown();
    }

    /**
     * Gets the handle to raw libguestfs API.
     *
     * @return resource
     */
    public function getHandle()
    {
        return $this->handle;
    }

    /**
     * Launch the underlying guestfs library, after drives have been added to it
     */
    private function launch()
    {
        $this->throwOnFalse(guestfs_launch($this->getHandle()));
    }

    /**
     * Add a drive to the guestfs library. Must be done before calling `launch()`
     *
     * @param string $drivePath Local path to the drive to add
     * @param bool $readOnly Whether to add the drive as read-only to the guestfs library
     */
    private function addDrive(string $drivePath, bool $readOnly = true)
    {
        $this->throwOnFalse(guestfs_add_drive($this->getHandle(), $drivePath, $readOnly));
    }

    /**
     * Mounts the device at a given mount point (in the appliance's FS tree)
     *
     * @param string $devicePath
     * @param string $mountPoint
     * @param array|null $options
     */
    public function mount(string $devicePath, string $mountPoint, array $options = null)
    {
        if (null !== $options) {
            $this->throwOnFalse(guestfs_mount_options($this->getHandle(), $options, $devicePath, $mountPoint));
        } else {
            $this->throwOnFalse(guestfs_mount($this->getHandle(), $devicePath, $mountPoint));
        }
    }

    /**
     * Unmounts a mounted device from the guestfs filesystem
     *
     * @param string $mountPoint
     */
    public function umount(string $mountPoint)
    {
        $this->throwOnFalse(guestfs_umount($this->getHandle(), $mountPoint));
    }

    /**
     * List the mount points of the devices that are mounted.
     *
     * @return array
     */
    public function listMountPoints(): array
    {
        return $this->throwOnFalse(guestfs_mounts($this->getHandle()));
    }

    /**
     * List the devices.
     *
     * @return array
     */
    public function listDevices(): array
    {
        return $this->throwOnFalse(guestfs_list_devices($this->getHandle()));
    }

    /**
     * List filesystems.
     *
     * @return array
     */
    public function listFilesystems(): array
    {
        return $this->throwOnFalse(guestfs_list_filesystems($this->getHandle()));
    }

    /**
     * List partitions.
     *
     * Just the partition list without any details, e.g. /dev/vda1, /dev/vda2 etc
     * Yes, /dev/vda* will also be returned for Windows :-)
     *
     * @return array
     */
    public function listPartitions(): array
    {
        return $this->throwOnFalse(guestfs_list_partitions($this->getHandle()));
    }

    /**
     * List the files at a given path. Requires mounting a partition.
     *
     * @param string $dirPath
     * @return array
     */
    public function listFiles(string $dirPath): array
    {
        return $this->throwOnFalse(guestfs_ls($this->getHandle(), $dirPath));
    }

    /**
     * Writes raw bytes to the attached device.
     *
     * It's a low level write that ignores a filesystem or anything. Basically,
     * an equivalent of fopen('/dev/sda'), fwrite($h, 1234), if it was done
     * outside of guestfs container which is not a good idea for the drives that
     * are already attached to guestfs.
     *
     * @param string $devicePath
     * @param string $buffer The byte string to write.
     * @param int $offset
     *
     * @return int number of bytes written
     */
    public function writeDevice(string $devicePath, string $buffer, int $offset): int
    {
        return $this->throwOnFalse(guestfs_pwrite_device($this->getHandle(), $devicePath, $buffer, $offset));
    }

    /**
     * Reads raw bytes from the attached device.
     *
     * A low-level read, @see writeDevice
     *
     * @param string $devicePath
     * @param int $count
     * @param int $offset
     *
     * @return string The byte string read
     */
    public function readDevice(string $devicePath, int $count, int $offset): string
    {
        return $this->throwOnFalse(guestfs_pread_device($this->getHandle(), $devicePath, $count, $offset));
    }

    /**
     * Shuts down a guestfs instance, stopping the underlying library and freeing the handle
     */
    public function shutDown(): void
    {
        if ($this->getHandle() !== null) {
            guestfs_shutdown($this->handle);
            $this->handle = null;
        }
    }
}
