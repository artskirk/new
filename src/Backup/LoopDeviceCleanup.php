<?php

/**
 * @author Kocsen
 * @author Oliver Castaneda
 *
 * A utility class for cleaning up loops devices after asset backups.
 * This is a bandaid solution, and this file should eventually become obsolete.
 */

namespace Datto\Backup;

use Datto\Block\LoopManager;
use Datto\Common\Utility\Filesystem;
use Datto\Iscsi\IscsiTarget;
use Datto\Log\LoggerAwareTrait;
use Datto\Utility\Block\Dmsetup;
use Exception;
use Psr\Log\LoggerAwareInterface;
use Throwable;

class LoopDeviceCleanup implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private LoopManager $loopManager;
    private IscsiTarget $iscsiTarget;
    private Filesystem $filesystem;
    private Dmsetup $dmsetup;

    public function __construct(
        LoopManager $loopManager,
        IscsiTarget $iscsiTarget,
        Filesystem $filesystem,
        Dmsetup $dmsetup
    ) {
        $this->loopManager = $loopManager;
        $this->iscsiTarget = $iscsiTarget;
        $this->filesystem = $filesystem;
        $this->dmsetup = $dmsetup;
    }

    /**
     * Remove loops for image files in a path.
     *
     * Created originally as a prevention measure for hung loops ran after a couple processes
     *
     * @param string $path
     *   The path containing the image files whose loops should be removed.
     * @param bool $cleanEncrypted
     *   TRUE if device mapper crypt devices should be cleaned up. If FALSE,
     *   only loops for unencrypted files will be cleaned up.
     * @return bool Whether any loops needed cleaning
     */
    public function cleanLoopsForImageFilesInPath(string $path, bool $cleanEncrypted = false): bool
    {
        //Tracks any loop deletions
        $loopsRemoved = false;

        // Make a list of files that may have loop devices.
        $agentFiles = $this->filesystem->glob($path . '/*.{datto,detto,checksum}', GLOB_BRACE);

        // Check for loop devices pointing to files in the agent directory.
        foreach ($agentFiles as $file) {
            if ($cleanEncrypted && preg_match('/.detto$/', $file)) {
                // If it's encrypted, check for and remove DMCrypt devices.
                try {
                    $cryptFiles = $this->getCryptDevicesUsingBackingFile($file);
                    foreach ($cryptFiles as $name) {
                        $loopsRemoved |= $this->deleteIscsiTargets($name);
                        $loopsRemoved |= $this->deleteDMDevices($name);
                    }
                } catch (Throwable $t) {
                    $this->logger->error(
                        'LOP0007 WARNING: Failed to retrieve crypt device data from device mapper.',
                        ['exception' => $t]
                    );
                }
            }
            //Delete all loop devices pointing to file
            $loopsRemoved |= $this->deleteLoops($file);
        }

        if ($loopsRemoved) {
            $this->logger->warning('LOP0012 Leaked loops were removed.', ['path' => $path]);
        } else {
            $this->logger->debug('LOP0011 Cleanup found no leaked loops.');
        }
        return $loopsRemoved;
    }

    /**
     * Delete iscsi targets for a named device
     *
     * @param string $name
     * @return bool whether any targets were deleted
     */
    private function deleteIscsiTargets(string $name): bool
    {
        $devMapperPath = '/dev/mapper/' . $name;
        // Check if an iSCSI target is using the file.
        $targets = $this->iscsiTarget->getTargetsByPath($devMapperPath);
        foreach ($targets as $target) {
            try {
                $this->logger->warning(
                    'LOP0001 Cleanup found file in use by iSCSI target',
                    ['target' => $target, 'path' => $devMapperPath]
                );
                $this->iscsiTarget->deleteTarget($target);
                return true;
            } catch (Throwable $t) {
                $this->logger->error(
                    'LOP0004 WARNING: Failed to remove iSCSI target',
                    ['target' => $target, 'exception' => $t]
                );
            }
        }
        return false;
    }

    /**
     * Delete device-mapper devices for a named device
     *
     * @param string $name
     * @return bool whether any devices were removed
     */
    private function deleteDMDevices(string $name): bool
    {
        $devMapperPath = '/dev/mapper/' . $name;
        if ($this->fileExists($devMapperPath)) {
            // Remove the device mapper device (also should remove backing loop for us)
            try {
                $this->logger->warning(
                    'LOP0002 Cleanup found stale encryption device in dev/mapper',
                    ['path' => $devMapperPath]
                );
                $this->DMRemove($name);
                return true;
            } catch (Throwable $t) {
                $this->logger->error(
                    'LOP0005 WARNING: Cleanup failed to remove stale encryption device',
                    ['name' => $name, 'exception' => $t]
                );
            }
        }
        return false;
    }

    /**
     * Unmount any loops that map to a given backing file
     *
     * @param string $file the full path to the backing file
     * @return bool whether any loops were deleted
     */
    private function deleteLoops(string $file): bool
    {
        try {
            //Convert /dev/mapper/name to /dev/dm-X.
            if (preg_match('#^/dev/mapper/.*$#', $file)) {
                $file = '/dev/' . $this->dmNameToIndex(basename($file));
            }
            if ($this->loopManager->destroyLoopsForBackingFile($file) > 0) {
                $this->logger->warning(
                    'LOP0010 WARNING: Cleaned leaked loop.',
                    ["file" => $file]
                );
                return true;
            }
        } catch (Throwable $t) {
            $this->logger->error(
                'LOP0006 WARNING: Failed to remove loop devices for backing file',
                ['backingFile' => $file, 'exception' => $t]
            );
        }
        return false;
    }

    private function fileExists(string $path): bool
    {
        clearstatcache();
        return $this->filesystem->exists($path);
    }

    /**
     * Determine the suffix that partition devices use.
     *
     * Generates a pattern for use in the glob() function. glob() must be called
     * with the optional GLOB_BRACE flag.
     *
     * Note that this function makes the potentially unsafe assumption that the
     * device will not contain more than 128 partitions.
     *
     * @param $device
     *   The device whose partition suffix you need.
     *
     * @return string
     *   The text to append to the device name in order to create a pattern for
     *   use in the glob() function. The call to glob() must use the optional
     *   GLOB_BRACE flag.
     */
    private function getPartitionSuffix(string $device): string
    {
        $numbers = '{' . implode(',', range(1, 128)) . '}';
        return $this->getPartitionDelimiter($device) . $numbers;
    }

    /**
     * Remove a Device Mapper device.
     *
     * @param string $name
     *   The device name to remove.
     */
    private function DMRemove(string $name)
    {
        $path = '/dev/mapper/' . $name;

        // Remove any COW-merge partition devices.
        $partitions = $this->filesystem->glob(
            $path . $this->getPartitionSuffix($path),
            GLOB_BRACE
        );
        foreach ($partitions as $partition) {
            try {
                $this->DMRemove(basename($partition));
            } catch (Throwable $t) {
                throw new Exception('Failed to remove DM partition device: ' . $partition . ' : ' . $t->getMessage());
            }
        }

        $this->dmsetup->destroy($name);
    }

    /**
     * Determine the delimiter used for partition devices.
     *
     * The naming of partition device files changed between Lucid and Precise:
     *     • On lucid, it will always be suffixed with pX, where X is an integer.
     *     • On precise, if it ends with a number, then it will be suffixed with
     *       pX, where X is an integer. If it ends with a letter, then it will
     *       be suffixed with just the partition number.
     *     • Trusty follows the same naming convention as precise.
     *
     * @param string $device
     *   The device whose partition delimiter character you need.
     *
     * @return string
     *   The partition delimiter, either 'p' or an empty string.
     */
    private function getPartitionDelimiter(string $device): string
    {
        if (ctype_digit(substr($device, -1, 1))) {
            return 'p';
        } else {
            return '';
        }
    }

    /**
     * Fetch a list of devices using loops mounting a backing file
     *
     * @param string $backingFile
     * @return array
     */
    private function getCryptDevicesUsingBackingFile(string $backingFile): array
    {
        $cryptDevices = $this->dmsetup->getDevicesForTarget('crypt');
        return array_filter($cryptDevices, function ($deviceName) use ($backingFile) {
            $loopPath = $this->dmsetup->getLoopPathForDevice($deviceName);
            $loopDevice = $this->loopManager->getLoopInfo($loopPath);
            return $loopDevice->getBackingFilePath() === $backingFile;
        });
    }

    /**
     * Convert a device mapper device's given name to its sysfs name.
     *
     * Takes the name of a device from /dev/mapper (whatever was passed to the
     * 'dmsetup create' command) and returns the name of its item in /sys/block
     * (dm-0, dm-1, etc.).
     *
     * @param string $name
     *   The name of the device mapper device.
     *
     * @return string
     *   The name of the sysfs entry.
     * @throws Exception
     *   Thrown if there are no device mapper devices or no sysfs entries match
     *   the provided name.
     */
    private function dmNameToIndex(string $name): string
    {
        $dmNames = $this->filesystem->glob('/sys/block/dm-*/dm/name');
        if (empty($dmNames)) {
            throw new Exception('No active device mapper devices.');
        }
        foreach ($dmNames as $dmName) {
            if (trim($this->filesystem->fileGetContents($dmName)) === $name) {
                return str_replace(['/sys/block/', '/dm/name'], '', $dmName);
            }
        }
        throw new Exception('Device mapper device not found: ' . $name);
    }
}
