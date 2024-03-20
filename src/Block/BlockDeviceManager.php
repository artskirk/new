<?php

namespace Datto\Block;

use Datto\Common\Utility\Filesystem;
use Datto\Common\Resource\Sleep;
use Datto\System\MountManager;
use Datto\Utility\Block\Blockdev;
use Datto\Utility\Disk\Partprobe;
use Exception;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * Handles creating and releasing block device stacks for unencrypted and encrypted files.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class BlockDeviceManager
{
    use PartitionableBlockDeviceTrait;

    /**
     * After creating a block device,
     * wait this number of cycles for partitions to appear under the loop.
     */
    const PARTITION_SCAN_MAX_WAIT = 60;

    /** How long to sleep when waiting for partitions in microseconds: .5s */
    const PARTITION_WAIT_INTERVAL = 500000;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var LoopManager */
    private $loopManager;

    /** @var DeviceMapperManager */
    private $deviceMapperManager;

    /** @var Filesystem */
    private $filesystem;

    /** @var MountManager */
    private $mountManager;

    /** @var Blockdev */
    private $blockdev;

    /** @var Partprobe */
    private $partprobe;

    /** @var Sleep */
    private $sleep;

    /**
     * @param DeviceLoggerInterface $logger
     * @param LoopManager $loopManager
     * @param DeviceMapperManager $deviceMapperManager
     * @param Filesystem $filesystem
     * @param MountManager $mountManager
     * @param Blockdev $blockdev
     * @param Partprobe $partprobe
     * @param Sleep $sleep
     */
    public function __construct(
        DeviceLoggerInterface $logger,
        LoopManager $loopManager,
        DeviceMapperManager $deviceMapperManager,
        Filesystem $filesystem,
        MountManager $mountManager,
        Blockdev $blockdev,
        Partprobe $partprobe,
        Sleep $sleep
    ) {
        $this->logger = $logger;
        $this->loopManager = $loopManager;
        $this->deviceMapperManager = $deviceMapperManager;
        $this->filesystem = $filesystem;
        $this->mountManager = $mountManager;
        $this->blockdev = $blockdev;
        $this->partprobe = $partprobe;
        $this->sleep = $sleep;
    }

    /**
     * Create a block device backed by an unencrypted file.
     *
     * @param string $filePath Full path to the backing file
     * @param bool $readOnly Set to true for a read-only block device
     * @param bool $runPartitionScan Set to true to run a partition scan on the block device
     * @return string Full path of the block device
     */
    public function acquireForUnencryptedFile(
        string $filePath,
        bool $readOnly,
        bool $runPartitionScan
    ): string {
        $options = $readOnly ? LoopManager::LOOP_CREATE_READ_ONLY : 0;
        $options |= $runPartitionScan ? LoopManager::LOOP_CREATE_PART_SCAN : 0;
        $loopInfo = $this->loopManager->create($filePath, $options, 0, false);
        $blockDevicePath = $loopInfo->getPath();

        if ($runPartitionScan) {
            $this->scanAndWaitForPartitions($blockDevicePath);
        }

        return $blockDevicePath;
    }

    /**
     * Release the block device.
     *
     * @param string $blockDevicePath Full path of the block device
     */
    public function releaseForUnencryptedFile(string $blockDevicePath)
    {
        $loopInfo = new LoopInfo($blockDevicePath, null, null, $this->filesystem);
        $this->loopManager->destroy($loopInfo);
    }

    /**
     * Create a block device backed by an encrypted file.
     *
     * @param string $filePath Full path to the backing file
     * @param string $encryptionKey Encryption key
     * @param string $namePrefix Prefix to add to the block device name
     * @param bool $readOnly Set to true for a read-only block device
     * @param bool $runPartitionScan Set to true to run a partition scan on the block device
     * @return string Full path of the block device
     */
    public function acquireForEncryptedFile(
        string $filePath,
        string $encryptionKey,
        string $namePrefix,
        bool $readOnly,
        bool $runPartitionScan
    ): string {
        $loopInfo = $this->loopManager->create($filePath);
        $loopPath = $loopInfo->getPath();

        $blockDevicePath = $this->deviceMapperManager->create($loopPath, $encryptionKey, $namePrefix, $readOnly);

        if ($runPartitionScan) {
            $partitions = $this->scanAndWaitForPartitions($blockDevicePath);

            if ($readOnly) {
                // Device mappers created from a partprobe do not inherit the read only state of its parent like
                // loops do, so iterate over the partitions to set each one to read only.
                foreach ($partitions as $partition) {
                    $this->blockdev->setReadOnly($partition);
                }
            }
        }

        return $blockDevicePath;
    }

    /**
     * Release the block device.
     *
     * @param string $blockDevicePath Full path of the block device
     */
    public function releaseForEncryptedFile(string $blockDevicePath)
    {
        $backingLoopPath = $this->deviceMapperManager->getBackingFile($blockDevicePath);
        $this->deviceMapperManager->destroy($blockDevicePath);

        $loopInfo = new LoopInfo($backingLoopPath, null, null, $this->filesystem);
        $this->loopManager->destroy($loopInfo);
    }

    /**
     * Get path to the specified partition number under this block device
     *
     * @param string $blockDevicePath
     * @param int $number
     * @return string
     */
    public function getPathToPartition(string $blockDevicePath, int $number): string
    {
        $partitionDelimiter = $this->getPartitionDelimiter($blockDevicePath);
        $partitionPath = $blockDevicePath . $partitionDelimiter . $number;

        if (!$this->filesystem->exists($partitionPath)) {
            throw new Exception('Partition does not exist for  ' . $partitionPath);
        }

        return $partitionPath;
    }

    /**
     * Prod the kernel to rescan the block device and wait for partition devices.
     *
     * @param string $blockDevicePath Full path of the block device
     * @return string[]
     */
    private function scanAndWaitForPartitions(string $blockDevicePath): array
    {
        try {
            $this->partprobe->triggerPartitionScan($blockDevicePath);
        } catch (Throwable $throwable) {
            $this->logger->warning('LOP1008 Failed to scan for partitions under block device path', [
                'blockDevicePath' => $blockDevicePath, 'exception' => $throwable
            ]);
            return [];
        }

        $partitions = [];
        $timeWaiting = 0;
        while (count($partitions) === 0 &&
            $timeWaiting < self::PARTITION_SCAN_MAX_WAIT) {
            $partitions = $this->getBlockDevicePartitions($blockDevicePath, $this->filesystem);

            $this->sleep->usleep(self::PARTITION_WAIT_INTERVAL);
            $timeWaiting++;
        }

        if (count($partitions) === 0) {
            $this->logger->warning('BDM0002 Failed to find partitions under block device path', [
                'blockDevicePath' => $blockDevicePath
            ]);
        }
        return $partitions;
    }
}
