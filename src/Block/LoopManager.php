<?php

namespace Datto\Block;

use Datto\Common\Resource\ProcessFactory;
use Datto\Filesystem\SysFs;
use Datto\Log\LoggerAwareTrait;
use Datto\Log\LoggerFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Common\Resource\Sleep;
use Datto\System\MountManager;
use Datto\Utility\Block\Blockdev;
use Datto\Utility\Block\Losetup;
use Datto\Utility\Disk\Hdparm;
use Datto\Utility\Disk\Partprobe;
use Datto\Log\DeviceLoggerInterface;
use Psr\Log\LoggerAwareInterface;
use RuntimeException;
use Throwable;

/**
 * Creates loop devices.
 *
 * @author Michael Meyer <mmeyer@datto.com>
 * @author Dawid Zamirski <dzamirski@datto.com>
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class LoopManager implements LoggerAwareInterface
{
    use PartitionableBlockDeviceTrait;
    use LoggerAwareTrait;

    /**
     * Available options for loop creation
     * To use multiple options bitwise OR (|) them together.
     */
    public const LOOP_CREATE_READ_ONLY = 0x1; // 0001
    public const LOOP_CREATE_PART_SCAN = 0x2; // 0010

    /**
     * After creating a loop with partscan enabled,
     * wait this number of cycles for partitions to appear
     * under the loop.
     */
    private const PART_SCAN_MAX_WAIT = 60;

    /** how long to sleep when waiting for partitions in milliseconds: 0.5s */
    private const PARTITION_WAIT_INTERVAL = 500;

    /** initial interval for detecting possible hung loops in milliseconds: 1s */
    private const HUNG_LOOP_WAIT_INTERVAL = 1000;

    /** The number of attempts for detecting a hung loop */
    private const HUNG_LOOP_RETRY_ATTEMPTS = 5;

    private Filesystem $filesystem;
    private MountManager $mountManager;
    private Losetup $losetup;
    private Hdparm $hdparm;
    private Partprobe $partprobe;
    private Blockdev $blockdevUtility;
    private Sleep $sleep;
    private SysFs $sysFs;

    public function __construct(
        DeviceLoggerInterface $logger = null,
        Filesystem $filesystem = null,
        MountManager $mountManager = null,
        Losetup $losetup = null,
        Hdparm $hdparm = null,
        Partprobe $partprobe = null,
        Blockdev $blockdevUtility = null,
        Sleep $sleep = null,
        SysFs $sysFs = null
    ) {

        $this->logger = $logger ?: LoggerFactory::getDeviceLogger();
        $this->filesystem = $filesystem ?? new Filesystem(new ProcessFactory());
        $this->mountManager = $mountManager ?? new MountManager();

        $processFactory = new ProcessFactory();
        $this->losetup = $losetup ?? new Losetup($processFactory, $this->filesystem);
        $this->hdparm = $hdparm ?? new Hdparm($processFactory);
        $this->partprobe = $partprobe ?? new Partprobe($processFactory);
        $this->blockdevUtility = $blockdevUtility ?? new Blockdev($processFactory);
        $this->sleep = $sleep ?? new Sleep();
        $this->sysFs = $sysFs ?? new SysFs();
    }

    /**
     * Create a loop device backed by the specified file.
     * The options are declared bitmask-style, for example:
     *    (..., LoopManager::LOOP_CREATE_READ_ONLY | LoopManager::LOOP_CREATE_PART_SCAN)
     * Will result in the created loop being partprobe'd and read only.
     *
     * @param string $file
     * @param int $options
     * @param int $offset (Optional) defaults to 0
     * @param bool $runPartitionScan
     *
     * @return LoopInfo
     */
    public function create(string $file, int $options = 0, int $offset = 0, bool $runPartitionScan = true): LoopInfo
    {
        $loopPath = $this->losetup->create($file, $options);
        $this->logger->debug('LOP1001 Created loop', ['loopPath' => $loopPath, 'backingFile' => $file]);

        if ($options & self::LOOP_CREATE_PART_SCAN && $runPartitionScan) {
            $this->waitForPartitions($loopPath);
        }

        return new LoopInfo($loopPath, $file, $offset, $this->filesystem, $this->sysFs);
    }

    /**
     * Removes loop device.
     *
     * First, checks if there are mount points using the loop and unmounts as
     * needed, then proceeds to actual loop removal.
     *
     * @param LoopInfo $loopInfo
     */
    public function destroy(LoopInfo $loopInfo)
    {
        $this->unmountPartitions($loopInfo);

        try {
            $this->deleteLoopDevice($loopInfo);
        } catch (Throwable $throwable) {
            $this->logger->critical('LOP1006 Failed to destroy loop device', ['exception' => $throwable]);
            throw $throwable;
        }
    }

    /**
     * Detach all loop devices associated with a backing file
     * @param string $backingFilePath
     *
     * @return int - the number of loop devices removed
     */
    public function destroyLoopsForBackingFile(string $backingFilePath): int
    {
        $loopPaths = $this->losetup->getLoopsByBackingFile($backingFilePath);

        foreach ($loopPaths as $loopPath => $backingFile) {
            $this->destroy(new LoopInfo($loopPath, null, null, $this->filesystem, $this->sysFs));
        }

        return count($loopPaths);
    }

    /**
     * Gets info on all loops on the device.
     *
     * @return LoopInfo[]
     */
    public function getLoops(): array
    {
        $loops = $this->losetup->getAllLoops();
        $loopInfos = $this->translateLoopsToLoopInfos($loops);
        return $loopInfos;
    }

    /**
     * Get info on loop devices attached to the given file.
     *
     * @param string $filePath absolute path to the file.
     *
     * @return LoopInfo[]
     */
    public function getLoopsOnFile(string $filePath): array
    {
        $loops = $this->losetup->getLoopsByBackingFile($filePath);
        $loopInfos = $this->translateLoopsToLoopInfos($loops);
        foreach ($loopInfos as $loopInfo) {
            $this->logger->debug(
                'LOP1009 Found loop at ',
                ['loopPath' => $loopInfo->getPath(), 'backingFile' => basename($filePath)]
            );
        }
        return $loopInfos;
    }

    /**
     * Returns a LoopInfo for the provided loop device or throws an exception
     *
     * @param string $loopPath
     * @return LoopInfo
     */
    public function getLoopInfo(string $loopPath): LoopInfo
    {
        foreach ($this->getLoops() as $loopInfo) {
            if ($loopInfo->getPath() === $loopPath) {
                return $loopInfo;
            }
        }

        throw new RuntimeException('Loop ' . $loopPath . ' could not be found');
    }

    /**
     * Determine if the given block device path is a loop
     *
     * @param string $blockDevicePath Block device path to check
     * @return bool
     */
    public function isLoopDevice(string $blockDevicePath): bool
    {
        return $this->losetup->exists($blockDevicePath);
    }

    /**
     * Prod the kernel to rescan the block device and wait for partition devices.
     *
     * @param string $blockDev an absolute path to block device.
     *
     * @return bool
     */
    private function waitForPartitions(string $blockDev): bool
    {
        try {
            $this->hdparm->triggerDiskRescan($blockDev);

            // On non-loop devices we must use partprobe.
            $isLoop = preg_match('/loop[0-9]+$/', $blockDev);
            if (!$isLoop) {
                $this->partprobe->triggerPartitionScan($blockDev);
            }
        } catch (Throwable $throwable) {
            $this->logger->warning(
                'LOP1008 Failed to scan for partitions',
                ['blockDevice' => $blockDev, 'exception' => $throwable]
            );
            return false;
        }

        // Wait for partition devices to appear
        $timeWaiting = 0;
        while ($timeWaiting < self::PART_SCAN_MAX_WAIT) {
            $partitions = $this->getBlockDevicePartitions($blockDev, $this->filesystem);

            if ($partitions) {
                return true;
            }

            $this->sleep->msleep(self::PARTITION_WAIT_INTERVAL);
            $timeWaiting++;
        }

        $this->logger->warning('LOP1003 Failed to find partitions under ', ['blockDevice' => $blockDev]);

        return false;
    }

    /**
     * Unmount any mounted loop partitions.
     *
     * TODO:
     *   This unmount logic is shared between LoopManager and DeviceMapperManager.
     *   It can be moved to a common location.
     *   Unmounting in the destroy function is a band-aid to fix clients that
     *     mount a loop partition, but then do not properly unmount it.
     *
     * @param LoopInfo $loopInfo
     */
    private function unmountPartitions(LoopInfo $loopInfo)
    {
        $partLoops = $loopInfo->getLoopPartitionDevices();
        foreach ($partLoops as $partition) {
            $mountPoint = $this->mountManager->getDeviceMountPoint($partition);

            if ($mountPoint === false) {
                continue;
            }

            $this->logger->debug(
                'LOP1003 Unmounting loop partition',
                ['mountPoint' => $mountPoint, 'loopPath' => $loopInfo->getPath()]
            );

            try {
                $this->mountManager->unmount($mountPoint);
            } catch (Throwable $throwable) {
                $this->logger->critical(
                    'LOP1004 Failed to unmount loop partition',
                    ['mountPoint' => $mountPoint, 'exception' => $throwable]
                );

                throw $throwable;
            }
        }
    }

    /**
     * Deletes loop device and tries to verify loop detachment,
     * or throws an error on timeout.
     *
     * @param LoopInfo $loopInfo
     */
    private function deleteLoopDevice(LoopInfo $loopInfo)
    {
        $loopDevice = $loopInfo->getPath();

        //If loop device is not attached, nothing to delete.
        if (!$this->losetup->exists($loopDevice)) {
            $this->logger->warning('LOP1012 Trying to delete nonexistent loop device.', ['loopPath' => $loopDevice]);
            return;
        }

        $backingFileCached = $this->losetup->getBackingFile($loopDevice);

        $this->logger->debug(
            'LOP1017 Detaching backing file from loop device.',
            ['loopPath' => $loopDevice, 'backingFile' => $backingFileCached]
        );

        try {
            $this->losetup->destroy($loopDevice);
        } catch (Throwable $throwable) {
            $this->logger->error(
                'LOP1011 Error detaching loop device.',
                ['exception' => $throwable, 'loopPath' => $loopDevice, 'backingFile' => $backingFileCached]
            );
        }

        $waitDuration = self::HUNG_LOOP_WAIT_INTERVAL;
        foreach (range(0, self::HUNG_LOOP_RETRY_ATTEMPTS) as $attempt) {
            if (!$this->losetup->exists($loopDevice)) {
                $this->logger->info(
                    'LOP1014 Deleted loop device no longer exists.',
                    ['loopPath' => $loopDevice, 'backingFile' => $backingFileCached]
                );
                return; // Deleted successfully!
            }

            $currentBackingFile = $this->losetup->getBackingFile($loopDevice);
            //Use str_pos to handle case where (deleted) is appended to backing file name
            $isBackingFileUnchanged = strpos($currentBackingFile, $backingFileCached) === 0;

            $isHanging = $this->losetup->isHanging($loopDevice) && $isBackingFileUnchanged;

            if (!$isHanging) {
                $this->logger->info(
                    'LOP1015 Deleted loop device claimed by another process.',
                    ['loopPath' => $loopDevice, 'oldFile' => $backingFileCached, 'newFile' => $currentBackingFile]
                );
                return; // Deleted successfully!
            }

            if ($attempt < self::HUNG_LOOP_RETRY_ATTEMPTS - 1) {
                //Loop is in use, and may be a potential leak
                $this->logger->warning(
                    'LOP1016 Loop still in use, flushing buffers and retrying.',
                    ['loopPath' => $loopDevice, 'backingFile' => $backingFileCached]
                );

                //Flush IO buffers on block device
                $this->blockdevUtility->flushBuffers($loopDevice);
                $this->sleep->msleep($waitDuration);
                //Wait 1,2,4,8 seconds
                $waitDuration *= 2;
            }
        }

        //The OS may keep a loop attached indefinitely for as long as file is in use.
        //Not necessarily critical, but most likely a process is misbehaving and loop is hung.
        throw new RuntimeException("Timed out detaching loop device $loopDevice with backing file $backingFileCached.");
    }

    /**
     * Create list of LoopInfo objects from a returned array from losetup.
     *
     * @param string[] $loops
     * @return LoopInfo[]
     */
    private function translateLoopsToLoopInfos(array $loops): array
    {
        $loopInfos = [];
        foreach ($loops as $loop => $backingFile) {
            $loopInfos[] = new LoopInfo($loop, $backingFile, null, $this->filesystem, $this->sysFs);
        }
        return $loopInfos;
    }
}
