<?php

namespace Datto\Utility\Virtualization\GuestFs;

/**
 * Class: OsInspector
 *
 * Provides OS inspection API of libguestfs.
 *
 */
class OsInspector extends GuestFsHelper
{
    /** @var string|null the 'active' root FS to work against. */
    private ?string $rootFs = null;

    /**
     * It will contain just one FS most of the time, but for multi-boot images
     * there may be more than one.
     *
     * @var array All filesystems that appear to contain OS root.
     */
    private array $rootFilesystems;

    public function __construct(GuestFs $guestFs)
    {
        parent::__construct($guestFs);
    }

    /**
     * Runs OS Inspection on the GuestFS
     *
     * @return bool True if inspection detected at least one operating system
     */
    public function inspect(): bool
    {
        $osInspection = guestfs_inspect_os($this->getHandle());
        if (is_array($osInspection)) {
            $this->rootFilesystems = $osInspection;
        } else {
            $this->rootFilesystems = [];
        }
        if ($this->detectedAnOs()) {
            $this->rootFs = $this->rootFilesystems[0];
            return true;
        }
        return false;
    }

    /**
     * Get whether or not the OS Inspector has valid data
     *
     * @return bool True if the OS inspector has detected at least one OS in the guestfs instance
     */
    public function detectedAnOs(): bool
    {
        return !empty($this->rootFilesystems);
    }

    /**
     * Gets the architecture of the guest OS.
     *
     * @return string Architecture as string "x86_64", "i386", "ppc", "ppc64" etc.
     */
    public function getArch(): string
    {
        return $this->throwOnFalse(guestfs_inspect_get_arch($this->getHandle(), $this->rootFs));
    }

    /**
     * Get the distro name of the guest os.
     *
     * For Windows it will return 'windows'
     *
     * @return string Distro name (e.g. 'ubuntu', 'rhel', 'sles', 'windows' or 'unknown')
     */
    public function getDistro(): string
    {
        return $this->throwOnFalse(guestfs_inspect_get_distro($this->getHandle(), $this->rootFs));
    }

    /**
     * Get the OS type of the guest OS.
     *
     * @return string e.g. "windows", "linux", "freebsd", "dos" etc, "unknown" on failure.
     */
    public function getType(): string
    {
        return $this->throwOnFalse(guestfs_inspect_get_type($this->getHandle(), $this->rootFs));
    }

    /**
     * Gets the guest OS product name.
     *
     * @return string e.g. 'Fedora 22', 'Windows 7 Professional', 'unknown' etc.
     */
    public function getProductName(): string
    {
        return $this->throwOnFalse(guestfs_inspect_get_product_name($this->getHandle(), $this->rootFs));
    }

    /**
     * Get the guest OS product variant.
     *
     * @return string e.g. "Desktop", "Server". "unknown" on failure.
     */
    public function getProductVariant(): string
    {
        return $this->throwOnFalse(guestfs_inspect_get_product_variant($this->getHandle(), $this->rootFs));
    }

    /**
     * Get the version of the guest OS as "major.minor".
     *
     * @return string e.g. "7.2"
     */
    public function getVersion(): string
    {
        $major = $this->throwOnFalse(guestfs_inspect_get_major_version($this->getHandle(), $this->rootFs));
        $minor = $this->throwOnFalse(guestfs_inspect_get_minor_version($this->getHandle(), $this->rootFs));

        return sprintf('%d.%d', $major, $minor);
    }

    /**
     * Get the Windows OS system root directory name.
     *
     * @return string
     */
    public function getWindowsRoot(): string
    {
        return $this->throwOnFalse(guestfs_inspect_get_windows_systemroot($this->getHandle(), $this->rootFs));
    }

    /**
     * Get the Windows OS CurrentControlSet registry key.
     *
     * @return string The control set (e.g. "ControlSet001")
     */
    public function getWindowsCurrentControlSet(): string
    {
        return $this->throwOnFalse(guestfs_inspect_get_windows_current_control_set($this->getHandle(), $this->rootFs));
    }

    /**
     * Get mappings of drive letters to block devices.
     *
     * Useful only for Windows. Looks into HKLM/SYSTEM/MountedDevices.
     *
     * @return array
     *  <code>
     *      array(
     *          'C' => '/dev/sda1',
     *          'D' => '/dev/sda2',
     *      );
     *  </code>
     */
    public function getDriveMappings(): array
    {
        return $this->throwOnFalse(guestfs_inspect_get_drive_mappings($this->getHandle(), $this->rootFs));
    }

    /**
     * Returns the mount points each block device is mounted on guest OS.
     *
     * For Windows, it will return only "/" - use getDriveMappings to get
     * info about which device corresponds to which drive letter.
     *
     * @return array
     */
    public function getMountpoints(): array
    {
        return $this->throwOnFalse(guestfs_inspect_get_mountpoints($this->getHandle(), $this->rootFs));
    }

    /**
     * Get the hostname of the OS.
     *
     * @return string
     */
    public function getHostname(): string
    {
        // TODO: Workaround for upstram libguesfs bug that incorrectly hanldes
        // comment lines. The fix for this was already submitted upstream and
        // once there's patched libguestfs available on devices this
        // implementation can simply be reduced to:
        //
        // return $this->throwOnFalse(guestfs_inspect_get_hostname($this->getHandle(),$this->rootFs));
        $result = guestfs_inspect_get_hostname($this->getHandle(), $this->rootFs);

        if ($result !== false) {
            // Upstream bug #1 (fixed), empty hostname is an error
            if ($result === '') {
                $result = false;
            // Upstram bug #2 (fixed), comment lines should be ignored
            } elseif ($result[0] === '#') {
                // Reparse the hostname file to extract the hostname properly
                // Using minimal error checking for guestfs API calls as this
                // is basically best effort approach and trying to keep
                // workaround code minimal.

                $result = false; // best effort -> assume failure from start

                guestfs_mount($this->getHandle(), $this->rootFs, '/');
                // yeah, those pesky systems can have both files, prefer HOSTNAME over hostname
                $files = ['/etc/hostname', '/etc/HOSTNAME'];
                foreach ($files as $file) {
                    $lines = guestfs_read_lines($this->getHandle(), $file);

                    if (is_array($lines)) {
                        foreach ($lines as $line) {
                            // ignore empty lines and comments
                            if ($line && $line[0] !== '#') {
                                $result = $line;
                                break;
                            }
                        }
                    }
                }

                guestfs_umount($this->getHandle(), '/');
            }
        }

        return $this->throwOnFalse($result);
    }

    /**
     * Get the active root filesystem of the guest OS.
     *
     * By default it's the first root filesystem that was detected.
     *
     * @return string|null
     */
    public function getRootFs(): ?string
    {
        return $this->rootFs;
    }

    /**
     * Get all the root filesystems that were detected.
     *
     * This will usually return just one but there may be more if the
     * disk image contains multi-boot OS installation.
     *
     * @return array
     */
    public function getAllRootFilesystems(): array
    {
        return $this->rootFilesystems;
    }

    /**
     * Allows to change the 'active' root FS.
     *
     * The 'active' root FS is the one that the OsInspector methods will return
     * information for. This is useful when dealing with multi-boot OS images.
     *
     * @param string $filesystemDevice
     */
    public function setActiveRootFs(string $filesystemDevice)
    {
        if (in_array($filesystemDevice, $this->rootFilesystems)) {
            $this->rootFs = $filesystemDevice;
        } else {
            throw new GuestFsException(
                'Invalid root filesystem device',
                $filesystemDevice . ' is not a root filesystem'
            );
        }
    }
}
