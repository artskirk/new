<?php
namespace Datto\Filesystem;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Block\LoopInfo;

/**
 * Class: SysFs provides methods to interact with low-level sysfs filesystem
 * to lookup additional information about block devices.
 *
 * @author Dawid Zamirski <dzamirski@datto.com>
 */
class SysFs
{
    private Filesystem $filesystem;

    public function __construct(Filesystem $filesystem = null)
    {
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
    }

    /**
     * Gets a list of block device slaves.
     *
     * Can be used to get loop devices for the backing DM block device.
     *
     * @param string $device A block device path.
     * @param bool $loopsOnly (Optional) return only slave loops, default
     *
     * @return LoopInfo[]|string[]
     *  An array of slave block device paths. If none, empty array is retuned.
     */
    public function getSlaves($device, $loopsOnly = true)
    {
        $basedev = basename($device);
        $sysFsPath = sprintf('/sys/block/%s/slaves', $basedev);

        $slaves = array();
        if (!$this->filesystem->isDir($sysFsPath)) {
            return $slaves;
        }

        $dirHandle = $this->filesystem->opendir($sysFsPath);

        if (!$dirHandle) {
            return $slaves;
        }

        while ($dirEntry = $this->filesystem->readdir($dirHandle)) {
            if (in_array($dirEntry, array('.', '..'))) {
                continue;
            }

            if ($loopsOnly) {
                $looksLikeLoop = preg_match('/^loop[0-9]+$/', $dirEntry);
                if ($looksLikeLoop) {
                    $slaves[] = new LoopInfo(
                        '/dev/' . $dirEntry,
                        $this->getLoopBackingFilePath($dirEntry),
                        $this->getLoopOffset($dirEntry),
                        $this->filesystem,
                        $this
                    );
                }
            } else {
                $slaves[] = "/dev/$dirEntry";
            }
        }

        $this->filesystem->closedir($dirHandle);

        return $slaves;
    }

    /**
     * Lookup the backing file the loop device is pointing at.
     *
     * @param string $loopDev absolute path to loop block device.
     *
     * @return string|null
     */
    public function getLoopBackingFilePath($loopDev)
    {
        $loop = basename($loopDev);

        $backingFile = sprintf('/sys/block/%s/loop/backing_file', $loop);

        //TODO: use system common filesystem calls after update
        clearstatcache();

        if ($this->filesystem->exists($backingFile) === false) {
            return null;
        }

        $backingFile = preg_replace(
            '/\(deleted\)$/',
            '',
            trim($this->filesystem->fileGetContents($backingFile))
        );

        return $backingFile;
    }

    /**
     * Checks if loop block device corresponds to 'active' loop.
     *
     * @param string $loopDev path to loop block device
     *
     * @return bool
     */
    public function loopExists($loopDev)
    {
        $loop = basename($loopDev);
        $sysFsPath = sprintf('/sys/block/%s/loop', $loop);

        return $this->filesystem->exists($sysFsPath);
    }

    /**
     * Get offset in the backing file the loop is pointing at.
     *
     * @param string $loopDev absolute path to loop block device.
     *
     * @return int
     */
    public function getLoopOffset($loopDev)
    {
        $loop = basename($loopDev);

        $sysFsPath = sprintf('/sys/block/%s/loop/offset', $loop);

        if (!$this->filesystem->exists($sysFsPath)) {
            return 0;
        }

        $offset = (int) $this->filesystem->fileGetContents(sprintf(
            '/sys/block/%s/loop/offset',
            $loop
        ));

        return $offset;
    }

    /**
     * Gets info on all loops on the device.
     *
     * @return LoopInfo[]
     */
    public function getLoops()
    {
        $loops = array();

        $dirHandle = $this->filesystem->opendir('/sys/block');

        if (!$dirHandle) {
            return $loops;
        }

        while ($entry = $this->filesystem->readdir($dirHandle)) {
            $looksLikeLoop = preg_match('/^loop[0-9]+$/', $entry);

            if (!$looksLikeLoop || !$this->loopExists($entry)) {
                continue;
            }

            $loopDev = sprintf('/dev/%s', $entry);

            $loops[$loopDev] = new LoopInfo(
                $loopDev,
                $this->getLoopBackingFilePath($loopDev),
                $this->getLoopOffset($loopDev),
                $this->filesystem,
                $this
            );
        }

        $this->filesystem->closedir($dirHandle);

        return $loops;
    }

    /**
     * Get DM device name, given DM block device path.
     *
     * @param string $dmBlockDev
     *
     * @return string
     */
    public function getDmDeviceName($dmBlockDev)
    {
        $dmBlock = basename($dmBlockDev);

        $sysFsPath = sprintf('/sys/block/%s/dm/name', $dmBlock);

        // no "name" file means the dm-device likely isn't in an intialized state
        if (!$this->filesystem->exists($sysFsPath)) {
            return null;
        }

        return trim($this->filesystem->fileGetContents($sysFsPath));
    }

    /**
     * Get a list of DM devices.
     *
     * @return array an assiciative array of DM devices with path and names
     */
    public function getDmDevices()
    {
        $dmDevices = array();

        $dirHandle = $this->filesystem->opendir('/sys/block');

        if (!$dirHandle) {
            return $dmDevices;
        }

        while ($entry = $this->filesystem->readdir($dirHandle)) {
            $looksLikeDmDevice = preg_match('/^dm-[0-9]+$/', $entry);

            if (!$looksLikeDmDevice) {
                continue;
            }

            $name = $this->getDmDeviceName($entry);

            // dm device likely isn't in an intialized state
            if ($name === null) {
                continue;
            }

            // TODO: Create DmDeviceInfo akin to LoopInfo?
            $dmDevices[] = array(
                'path' => sprintf('/dev/%s', $entry),
                'name' => $this->getDmDeviceName($entry),
            );
        }

        $this->filesystem->closedir($dirHandle);

        return $dmDevices;
    }
}
