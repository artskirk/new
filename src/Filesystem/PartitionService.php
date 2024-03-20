<?php

namespace Datto\Filesystem;

use Datto\Common\Resource\ProcessFactory;
use Datto\Log\LoggerAwareTrait;
use Datto\Common\Utility\Filesystem;
use Datto\Util\RetryHandler;
use Exception;
use Psr\Log\LoggerAwareInterface;

/**
 * Service class to handle partitioning of drives
 *
 * @author Andrew Cope <acope@datto.com>
 */
class PartitionService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const COMMAND_SGDISK = 'sgdisk';
    const SGDISK_ZAP_ALL = '--zap-all';

    const COMMAND_SFDISK = 'sfdisk';

    const COMMAND_FDISK = 'fdisk';
    const FDISK_INPUT_NEW_DOS_PARTITION_TABLE = 'o';
    const FDISK_INPUT_WRITE_CHANGES = 'w';
    const FDISK_INPUT_NEW_PARTITION = 'n';
    const FDISK_INPUT_PRIMARY_PARTITION = 'p';
    const FDISK_INPUT_DEFAULT_PARTITION_NUMBER = 1;
    const FDISK_INPUT_ACCEPT_DEFAULT = ''; // Empty to use the default for the device
    const FDISK_INPUT_CHANGE_PARTITION_TYPE = 't';
    const FDISK_INPUT_MAKE_BOOTABLE = 'a';
    const FDISK_INPUT_PARTITION_SIZE_IDENTIFIER_MB = 'M';
    const FDISK_PARTITION_ALIGNMENT_IN_BYTES = 1048576;

    const COMMAND_GDISK = 'gdisk';
    const GDISK_INPUT_NEW_GPT_PARTITION_TABLE = 'o';
    const GDISK_INPUT_CONFIRM = 'Y';
    const GDISK_INPUT_WRITE_CHANGES = 'w';
    const GDISK_INPUT_EXPERT_FUNCTIONS = 'x';
    const GDISK_INPUT_SECTOR_ALIGNMENT = 'l';
    const GDISK_INPUT_MAIN_MENU = 'm';
    const GDISK_INPUT_NEW_PARTITION = 'n';
    const GDISK_INPUT_ACCEPT_DEFAULT = '';

    const COMMAND_PARTPROBE = 'partprobe';
    const COMMAND_SYNC = 'sync';

    const COMMAND_MKDOSFS = 'mkdosfs';
    const COMMAND_MKNTFS = 'mkfs.ntfs';
    const FAT32 = 32;

    const GPT_VOL_TEXT = 'GPT';
    const MBR_VOL_TEXT = 'MBR';
    const MBR_LABEL = 'dos';
    const COMMAND_TRUNCATE = 'truncate';
    const EFI_SYSTEM_GUID = 'C12A7328-F81F-11D2-BA4B-00A0C93EC93B';

    // Two terabytes in size with a safety buffer of 33 blocks + 1 block for mbr->gpt conversion
    const SAFE_GPT_BUFFER_BYTES = (34 * 512);

    /** @var ProcessFactory */
    private $processFactory;

    /** @var Filesystem */
    private $filesystem;

    /** @var RetryHandler */
    private $retryHandler;

    public function __construct(
        ProcessFactory $processFactory = null,
        Filesystem $filesystem = null,
        RetryHandler $retryHandler = null
    ) {
        $this->processFactory = $processFactory ?: new ProcessFactory();
        $this->filesystem = $filesystem ?: new Filesystem($this->processFactory);
        $this->retryHandler = $retryHandler ?: new RetryHandler();
    }

    /**
     * Erases the block device and creates a DOS partition table on it
     *
     * @param string $blockDevice
     */
    public function createMasterBootRecordBlockDevice(string $blockDevice)
    {
        $this->clearPartitionsOnDisk($blockDevice);
        $this->wipeMagicLabelsOnDisk($blockDevice);
        $this->createMasterBootRecordOnDisk($blockDevice);
    }

    /**
     * Erases the block device and creates a GPT partition table on it
     *
     * @param string $blockDevice
     */
    public function createGptBlockDevice(string $blockDevice)
    {
        $this->clearPartitionsOnDisk($blockDevice);
        $this->wipeMagicLabelsOnDisk($blockDevice);
        $this->createGptOnDisk($blockDevice);
    }

    /**
     * Create a single partition on a given block device.
     *
     *
     * @param AbstractPartition $partition
     * @param bool $verify if true, use partprobe to verify partition was created
     */
    public function createSinglePartition(AbstractPartition $partition, bool $verify = true)
    {
        if ($partition instanceof MbrPartition) {
            $this->createSingleMbrPartition($partition);
        } elseif ($partition instanceof GptPartition) {
            $this->createSingleGptPartition($partition);
        } else {
            throw new \InvalidArgumentException("Unsupported Partition type " . get_class($partition));
        }

        if ($verify) {
            $this->probeForNewPartitions($partition->getBlockDevice());
            $this->syncPartitions($partition->getBlockDevice());
            // Get the first partition (includes the block device as well, e.g. sda and sda1)
            $blockDevicePartitions = $this->filesystem->glob($partition->getBlockDevice() . '*');
            if (count($blockDevicePartitions) <= 1) {
                throw new Exception('Unable to determine path to first partition of device');
            }
        }
    }

    /**
     * Format the partition as a FAT partition
     *
     * This will occasionally fail, resulting in a timeout exception from $formatPartitionProcess
     * which occurs at 60 seconds. 60 seconds is normally plenty of time, since the partition we create is tiny.
     * The cause is xhci_hcd (the USB 3.0 module) losing access to the device occasionally. (see syslog messages)
     * Unplug the drive and replug the drive, then try again as a workaround.
     *
     * @param AbstractPartition $partition
     * @param int $numberOfFats
     */
    public function formatFatPartition(AbstractPartition $partition, int $numberOfFats = self::FAT32)
    {
        $this->logger->debug("PTS0002 Formatting FAT32 partition: {$partition->toString()}");

        $this->processFactory
            ->get([self::COMMAND_MKDOSFS, '-F', $numberOfFats, $partition->toString()])
            ->mustRun();
    }

    /**
     * Format a partition as an NTFS filesystem.
     *
     * @param AbstractPartition $partition
     */
    public function formatNtfsPartition(AbstractPartition $partition)
    {
        $this->logger->debug("PTS0006 Formatting NTFS partition: {$partition->toString()}");

        $this->processFactory
            ->get([self::COMMAND_MKNTFS, '--quick', $partition->toString()])
            ->mustRun();
    }

    /**
     * Read an image's partition table and return a partition object
     *
     * @param string $imageFile path of the image to read the partition table of
     * @return AbstractPartition[] a collection of partition objects
     */
    public function readPartitionTable(string $imageFile): array
    {
        $partitions = [];
        $sfdiskOutputJson = $this->processFactory
            ->get([self::COMMAND_SFDISK, '--json', '-uS', $imageFile])
            ->mustRun()
            ->getOutput();

        $this->validatePartitionJson($sfdiskOutputJson);
        $sfdiskOutput = json_decode($sfdiskOutputJson, true);
        $partitionTable = $sfdiskOutput['partitiontable'];

        $isMbr = $partitionTable['label'] === self::MBR_LABEL;

        foreach ($partitionTable['partitions'] as $partitionEntry) {
            $partitions[] = $this->getPartition(
                $isMbr,
                $partitionTable['device'],
                $partitionEntry
            );
        }

        return $partitions;
    }

    /**
     * Write a disk's partition table to its' block device
     *
     * @param AbstractDisk $disk disk to write a parition table for
     */
    public function partitionDisk(AbstractDisk $disk)
    {
        if ($disk instanceof MbrDisk) {
            $this->partitionMbrDisk($disk);
        } elseif ($disk instanceof GptDisk) {
            $this->partitionGptDisk($disk);
        }
    }

    /**
     * Use blkid to get the partition's UUID.
     *
     * @param string $partitionDevice
     * @return string
     */
    public function getPartitionUuid(string $partitionDevice)
    {
        $output = $this->processFactory
            ->get(['blkid', '-c', '/dev/null', '-s', 'UUID', '-o', 'value', $partitionDevice])
            ->mustRun()
            ->getOutput();


        return trim($output);
    }

    /**
     * Validate that the necessary partition information is present to read the partition table
     *
     * @param string $partitionJson partition information to validate, in JSON format
     */
    private function validatePartitionJson(string $partitionJson)
    {
        $partitionInfo = json_decode($partitionJson, true);
        $partitionInfoIsValid =
            isset($partitionInfo['partitiontable']['label']) &&
            isset($partitionInfo['partitiontable']['device']) &&
            isset($partitionInfo['partitiontable']['partitions'][0]['type']) &&
            isset($partitionInfo['partitiontable']['partitions'][0]['start']) &&
            isset($partitionInfo['partitiontable']['partitions'][0]['size']);

        if (!$partitionInfoIsValid) {
            throw new Exception('Image\'s partition table is invalid or cannot be read');
        }
    }

    /**
     * Write a MBR disk's partition table to its' block device
     *
     * @param MbrDisk $disk disk to write a partition table for
     */
    private function partitionMbrDisk(MbrDisk $disk)
    {
        $blockDevice = $disk->getBlockDevice();
        $sectorSize = $disk->getSectorSize();

        if ($disk->getPartitionAlignment() !== self::FDISK_PARTITION_ALIGNMENT_IN_BYTES) {
            $message = "Disk's partition alignment does not match FDISK alignment of 1 Mib; cannot create partition";
            $this->logger->error("PTS0007 $message");
            throw new Exception($message);
        }

        $input = [];

        /** @var MbrPartition $partition */
        foreach ($disk->getPartitions() as $index => $partition) {
            $partitionNumber = $index + 1;
            $partitionOffset = $disk->getPartitionOffset($partitionNumber);
            $partitionSize = $disk->getPartitionSize($partitionNumber);
            $firstSector = $partitionOffset / $sectorSize;
            $lastSector = floor(($partitionOffset + $partitionSize - 1) / $sectorSize);

            $input[] = self::FDISK_INPUT_NEW_PARTITION;
            $input[] = MbrType::PRIMARY();
            $input[] = $partitionNumber;
            $input[] = $firstSector;
            $input[] = $lastSector;

            if ($partition->getPartitionType() !== "") {
                $input[] = self::FDISK_INPUT_CHANGE_PARTITION_TYPE;
                if ($partitionNumber > 1) {
                    $input[] = $partitionNumber;
                }
                $input[] = $partition->getPartitionType();
            }

            if ($partitionNumber === 1) {
                $input[] = self::FDISK_INPUT_MAKE_BOOTABLE;
            }
        }

        $input[] = self::FDISK_INPUT_WRITE_CHANGES;

        $inputString = implode(PHP_EOL, $input);

        $this->logger->debug("PTS0010 Creating MBR partition table on $blockDevice");

        $this->processFactory
            ->get([self::COMMAND_FDISK, $blockDevice])
            ->setTimeout(300)
            ->setInput($inputString)
            ->mustRun();

        if ($disk->hasBootablePartition()) {
            $this->addMbrBootRecord($disk->getBlockDevice());
        }
    }

    /**
     * Create a boot record for an MBR partitioned block device
     *
     * @param string $blockDevice
     */
    private function addMbrBootRecord(string $blockDevice)
    {
        $this->processFactory
            ->get([
                'dd',
                'if=/usr/lib/syslinux/mbr/mbr.bin',
                "of=$blockDevice",
                'count=1',
                'conv=notrunc'
            ])
            ->mustRun();
    }

    /**
     * Write a GPT disk's partition table to its' block device
     *
     * @param GptDisk $disk
     */
    private function partitionGptDisk(GptDisk $disk)
    {
        $blockDevice = $disk->getBlockDevice();
        $sectorSize = $disk->getSectorSize();

        // set sector alignment
        $sectorAlignment = $disk->getPartitionAlignment() / $sectorSize;
        $input = [
            self::GDISK_INPUT_EXPERT_FUNCTIONS,
            self::GDISK_INPUT_SECTOR_ALIGNMENT,
            $sectorAlignment,
            self::GDISK_INPUT_MAIN_MENU,
        ];

        /** @var GptPartition $partition */
        foreach ($disk->getPartitions() as $index => $partition) {
            $partitionNumber = $index + 1;
            $partitionOffset = $disk->getPartitionOffset($partitionNumber);
            $firstSector = $partitionOffset / $sectorSize;
            $lastSector = floor(($partitionOffset + $disk->getPartitionSize($partitionNumber) - 1) / $sectorSize);

            $input[] = self::GDISK_INPUT_NEW_PARTITION;
            $input[] = $partitionNumber;
            $input[] = $firstSector;
            $input[] = $lastSector;
            $input[] = $partition->getPartitionType();
        }

        $input[] = self::GDISK_INPUT_WRITE_CHANGES;
        $input[] = self::GDISK_INPUT_CONFIRM;
        $input[] = self::GDISK_INPUT_ACCEPT_DEFAULT;

        $inputString = implode(PHP_EOL, $input);

        $this->logger->debug("PTS0011 Creating GPT partition table on $blockDevice");

        $this->processFactory
            ->get([self::COMMAND_GDISK, $blockDevice])
            ->setInput($inputString)
            ->setTimeout(300)
            ->mustRun();
    }

    /**
     * Create a single partition of a given block device.
     *
     * @param MbrPartition $partition
     */
    private function createSingleMbrPartition(MbrPartition $partition)
    {
        $blockDevice = $partition->getBlockDevice();
        $inputArray = [];

        if ($partition->isDosCompatible()) {
            $inputArray[] = 'c';
        }

        $inputArray = array_merge(
            $inputArray,
            [
                self::FDISK_INPUT_NEW_PARTITION,
                $partition->getMbrType()->value(),
                $partition->getPartitionNumber(),
                $partition->getFirstSector() ?? self::FDISK_INPUT_ACCEPT_DEFAULT,
                $partition->getLastSector() ?? $partition->getFormattedSize() ?? self::FDISK_INPUT_ACCEPT_DEFAULT
            ]
        );

        if (!empty($partition->getPartitionType())) {
            $inputArray[] = self::FDISK_INPUT_CHANGE_PARTITION_TYPE;
            $inputArray[] = $partition->getPartitionType();
        }

        if ($partition->isBootable()) {
            $inputArray[] = self::FDISK_INPUT_MAKE_BOOTABLE;
            $inputArray[] = $partition->getPartitionNumber();
        }

        $inputArray[] = self::FDISK_INPUT_WRITE_CHANGES;

        $inputString = implode(PHP_EOL, $inputArray);

        $this->logger->debug("PTS0004 Creating single MBR partition on $blockDevice");

        $this->processFactory
            ->get([self::COMMAND_FDISK, $blockDevice])
            ->setInput($inputString)
            ->run();
    }

    /**
     * Create a single GPT partition of a given block device.
     *
     * @param GptPartition $partition
     */
    private function createSingleGptPartition(GptPartition $partition)
    {
        $blockDevice = $partition->getBlockDevice();
        $inputArray = [];

        if (!empty($partition->getSectorAlignment())) {
            $inputArray = array_merge(
                $inputArray,
                [
                    self::GDISK_INPUT_EXPERT_FUNCTIONS,
                    self::GDISK_INPUT_SECTOR_ALIGNMENT,
                    $partition->getSectorAlignment(),
                    self::GDISK_INPUT_MAIN_MENU
                ]
            );
        }

        $inputArray = array_merge(
            $inputArray,
            [
                self::GDISK_INPUT_NEW_PARTITION,
                $partition->getPartitionNumber(),
                $partition->getFirstSector() ?? self::GDISK_INPUT_ACCEPT_DEFAULT,
                $partition->getLastSector() ?? $partition->getFormattedSize() ?? self::GDISK_INPUT_ACCEPT_DEFAULT,
                $partition->getPartitionType(),
                self::GDISK_INPUT_WRITE_CHANGES,
                self::GDISK_INPUT_CONFIRM,
                self::GDISK_INPUT_ACCEPT_DEFAULT
            ]
        );

        $this->logger->debug("PTS0004 Creating single GPT partition on $blockDevice");
        $inputString = implode(PHP_EOL, $inputArray);

        $this->processFactory
            ->get([self::COMMAND_GDISK, $blockDevice])
            ->setInput($inputString)
            ->run();
    }

    /**
     * Zap any GPT/MBR already on the disk
     *
     * @param string $blockDevice
     */
    private function clearPartitionsOnDisk(string $blockDevice)
    {
        $this->logger->debug("PTS0001 Clearing partitions on block device: $blockDevice");

        // This command should normally take almost no time to execute as it
        // performs very little IO, but there are odd cases in the field where
        // it timed out, so rather than excessively extending timeout, use
        // retryHandler here with shorter timeout per attempt
        $this->retryHandler->executeAllowRetry(
            function () use ($blockDevice) {
                $this->processFactory
                    ->get([
                        self::COMMAND_SGDISK,
                        self::SGDISK_ZAP_ALL,
                        $blockDevice
                    ])
                    ->setTimeout(20)
                    ->mustRun();
            }
        );
    }

    /**
     * Wipe any magic labels on the disk
     *
     * @param string $blockDevice
     */
    private function wipeMagicLabelsOnDisk(string $blockDevice)
    {
        $this->logger->debug("PTS0002 Wiping magic labels on block device: $blockDevice");
        $this->processFactory->get(['wipefs', '--all', '--force', $blockDevice])->mustRun();
    }

    /**
     * Fdisk command to create a MBR on the disk
     *
     * @param string $blockDevice
     */
    private function createMasterBootRecordOnDisk(string $blockDevice)
    {
        $this->logger->debug("PTS0003 Creating Master Boot Record on block device: $blockDevice");

        $inputArray = [
            self::FDISK_INPUT_NEW_DOS_PARTITION_TABLE, // Create a new empty DOS partition table
            self::FDISK_INPUT_WRITE_CHANGES // Write table to disk and exit
        ];
        $inputString = implode(PHP_EOL, $inputArray);

        $this->processFactory
            ->get([self::COMMAND_FDISK, $blockDevice])
            ->setInput($inputString)
            ->setTimeout(300)
            ->run();
    }

    /**
     * gdisk command to create a GPT on the disk
     *
     * @param string $blockDevice
     */
    private function createGptOnDisk(string $blockDevice)
    {
        $this->logger->debug("PTS0005 Creating GPT on block device: $blockDevice");

        $inputArray = [
            self::GDISK_INPUT_NEW_GPT_PARTITION_TABLE,
            self::GDISK_INPUT_CONFIRM,
            self::GDISK_INPUT_WRITE_CHANGES,
            self::GDISK_INPUT_CONFIRM,
            self::GDISK_INPUT_ACCEPT_DEFAULT
        ];
        $inputString = implode(PHP_EOL, $inputArray);

        $this->processFactory
            ->get([self::COMMAND_GDISK, $blockDevice])
            ->setInput($inputString)
            ->setTimeout(300)
            ->run();
    }

    /**
     * Inform the operating system about partition table changes
     *
     * @param string $blockDevice
     */
    private function probeForNewPartitions(string $blockDevice)
    {
        $this->processFactory
            ->get([self::COMMAND_PARTPROBE, $blockDevice])
            ->mustRun();
    }

    /**
     * Synchronize cached writes to persistent storages
     *
     * @param string $blockDevice
     */
    private function syncPartitions(string $blockDevice)
    {
        $this->processFactory
            ->get([self::COMMAND_SYNC, $blockDevice])
            ->run();
    }

    /**
     * Given partition entry data, create parition object.
     *
     * @param bool $isMbr
     * @param string $blockDevice
     * @param array $partitionEntry
     * @return AbstractPartition
     */
    private function getPartition(bool $isMbr, string $blockDevice, array $partitionEntry): AbstractPartition
    {
        if ($isMbr) {
            $hasBootableKey = array_key_exists('bootable', $partitionEntry);
            $isBootable = $hasBootableKey && $partitionEntry['bootable'];
            $partition = new MbrPartition(
                $blockDevice,
                AbstractPartition::DEFAULT_PARTITION_NUMBER,
                $isBootable
            );
            $partition->setPartitionType($partitionEntry['type']);
        } else {
            $isBootable = $partitionEntry['type'] === self::EFI_SYSTEM_GUID;
            $partitionType = $isBootable ?
                GptPartition::PARTITION_TYPE_EFI_SYSTEM :
                GptPartition::PARTITION_TYPE_MICROSOFT_BASIC;

            $partition = new GptPartition(
                $blockDevice,
                AbstractPartition::DEFAULT_PARTITION_NUMBER,
                $partitionType,
                $isBootable
            );
        }

        $partition->setFirstSector($partitionEntry['start']);
        $partition->setLastSector($partitionEntry['start'] + $partitionEntry['size'] - 1);

        // The sector size is assumed here since the json output of sfdisk does not include the sector size value.
        // TODO: Correctly calculate this value for future variable sector size support.
        $partition->setSectorSize(AbstractDisk::DEFAULT_SECTOR_SIZE_IN_BYTES);

        return $partition;
    }
}
