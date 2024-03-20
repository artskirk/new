<?php

namespace Datto\Backup\File;

use Datto\Asset\Agent\DmCryptManager;
use Datto\Block\LoopManager;
use Datto\Common\Resource\ProcessFactory;
use Datto\Filesystem\GptPartition;
use Datto\Filesystem\MbrPartition;
use Datto\Filesystem\MbrType;
use Datto\Filesystem\PartitionService;
use Datto\Filesystem\SparseFileService;
use Datto\Common\Utility\Filesystem;
use Datto\System\HealthService;
use InvalidArgumentException;
use Datto\Log\DeviceLoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Base class to create OS specific sparse files for backup.
 *
 * @author Chad Barbe <cbarbe@datto.com>
 */
abstract class SingleVolumeBackupImageFile implements BackupImageFile
{
    /**
     * Size we add to an image. This is calculated from:
     * 2048 starting sectors + 20480 BIOS Boot Partition + 33 trailing sectors
     */
    const IMAGE_OVERHEAD_SECTORS = 2048 + 20480 + 33;
    const GPT_END_SECTOR_PADDING = 34;
    const DEFAULT_BASE_SECTOR_OFFSET = 63;

    /** @var Filesystem */
    protected $filesystem;

    /** @var PartitionService */
    private $partitionService;

    /** @var LoopManager */
    private $loopManager;

    /** @var DmCryptManager */
    private $dmCryptManager;

    /** @var DeviceLoggerInterface */
    protected $logger;

    /** @var SparseFileService */
    private $sparseFileService;

    /** @var HealthService */
    private $healthService;

    /**
     * @param DeviceLoggerInterface $logger
     * @param SparseFileService $sparseFileService
     * @param PartitionService $partitionService
     * @param LoopManager|null $loopManager
     * @param DmCryptManager|null $dmCryptManager
     * @param Filesystem|null $filesystem
     */
    public function __construct(
        DeviceLoggerInterface $logger,
        SparseFileService $sparseFileService,
        PartitionService $partitionService,
        HealthService $healthService,
        LoopManager $loopManager = null,
        DmCryptManager $dmCryptManager = null,
        Filesystem $filesystem = null
    ) {
        $this->logger = $logger;
        $this->sparseFileService = $sparseFileService;
        $this->partitionService = $partitionService;
        $this->healthService = $healthService;
        $this->loopManager = $loopManager ?: new LoopManager();
        $this->dmCryptManager = $dmCryptManager ?: new DmCryptManager();
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
    }

    /**
     * @inheritdoc
     */
    public function create(
        string $imageFile,
        int $volumeSize,
        string $filesystem,
        string $volumeGuid,
        bool $useGpt,
        bool $isEncrypted,
        string $encryptionKey = null
    ) {
        if ($isEncrypted && empty($encryptionKey)) {
            throw new InvalidArgumentException('Expected non empty value for encryptionKey when isEncrypted=true');
        }

        try {
            $fileSize = $this->getFileSizeWithOverhead($volumeSize, $useGpt);
            $this->createSparseFile($imageFile, $volumeGuid, $fileSize);
            $this->createOrUpdatePartition($imageFile, $filesystem, $useGpt, $isEncrypted, $encryptionKey);
        } catch (Throwable $e) {
            throw new RuntimeException("Error creating backup image file '$imageFile'", 0, $e);
        }
    }

    /**
     * @inheritdoc
     */
    abstract public function resizeIfNeeded(
        string $imageFile,
        int $volumeSize,
        string $filesystem,
        bool $useGpt,
        bool $isEncrypted,
        string $encryptionKey = null
    ): bool;

    /**
     * @inheritdoc
     */
    public function getBaseSectorOffset(): int
    {
        return self::DEFAULT_BASE_SECTOR_OFFSET;
    }

    /**
     * @inheritdoc
     */
    public function getImageOverheadInBytes(): int
    {
        return self::SECTOR_SIZE_IN_BYTES * self::IMAGE_OVERHEAD_SECTORS;
    }

    /**
     * Determine the filesystem type
     *
     * @param bool $isGpt
     * @param string $filesystem
     * @return string
     */
    abstract protected function getFilesystemType(bool $isGpt, string $filesystem): string;


    /**
     * Get the size the of image file with the overhead sectors and gpt sector padding added.
     *
     * @param int $volumeSize
     * @param bool $useGpt
     * @return int
     */
    protected function getFileSizeWithOverhead(int $volumeSize, bool $useGpt): int
    {
        $partitionOverheadSectors = ($this->getBaseSectorOffset() + ($useGpt ? static::GPT_END_SECTOR_PADDING : 0));
        $fileSize = $volumeSize + ($partitionOverheadSectors * self::SECTOR_SIZE_IN_BYTES);
        return $fileSize;
    }

    public function createOrUpdatePartition(
        string $imageFile,
        string $filesystem,
        bool $useGpt,
        bool $isEncrypted,
        string $encryptionKey = null
    ): void {
        $blockDevicePath = null;
        $loopInfo = null;

        try {
            if ($isEncrypted) {
                $blockDevicePath = $this->dmCryptManager->attach($imageFile, $encryptionKey);
            } else {
                $loopInfo = $this->loopManager->create($imageFile);
                $blockDevicePath = $loopInfo->getPath();
            }

            if ($useGpt) {
                $this->partitionService->createGptBlockDevice($blockDevicePath);
                $partition = new GptPartition($blockDevicePath, 1, GptPartition::PARTITION_TYPE_MICROSOFT_BASIC);
                $partition->setSectorAlignment($this->getBaseSectorOffset());
            } else {
                $this->partitionService->createMasterBootRecordBlockDevice($blockDevicePath);
                $partition = new MbrPartition($blockDevicePath, 1);
                $partition->setMbrType(MbrType::PRIMARY());
                $partition->setDosCompatible(true);
                $partition->setIsBootable(true);
            }

            $partition->setFirstSector($this->getBaseSectorOffset());
            $partition->setPartitionType($this->getFilesystemType($useGpt, $filesystem));

            $this->partitionService->createSinglePartition($partition, false);
        } catch (Throwable $throwable) {
            $this->logger->critical(
                'BAK2040 Error creating or repairing partition for image file',
                ['imageFile' => $imageFile, 'exception' => $throwable->getMessage()]
            );
            $healthScores = $this->healthService->calculateHealthScores();
            $this->logger->debug(
                'BAK2044 System health scores after failure to write partition info',
                [
                    'cpuHealth' => $healthScores->getCpuHealthScore(),
                    'iopsHealth' => $healthScores->getIopsHealthScore(),
                    'memoryHealth' => $healthScores->getMemoryHealthScore(),
                    'zpoolHealth' => $healthScores->getZpoolHealthScore(),
                ]
            );
            throw $throwable;
        } finally {
            $this->cleanup($imageFile, $isEncrypted, $blockDevicePath, $loopInfo);
        }
    }

    /**
     * Create the backup image's sparse file.
     *
     * @param string $imageFile
     * @param string $volumeGuid
     * @param int $fileSize
     */
    private function createSparseFile(string $imageFile, string $volumeGuid, int $fileSize)
    {
        $this->logger->debug("BAK2020 Creating new sparse image for fs guid: $volumeGuid, size $fileSize");

        $this->sparseFileService->create(
            $imageFile,
            $fileSize,
            self::SECTOR_SIZE_IN_BYTES
        );
    }

    /**
     * Attempt cleanup after image file creation
     *
     * @param string $imageFile
     * @param bool $isEncrypted
     * @param $blockDevicePath
     * @param $loopInfo
     */
    private function cleanup(
        string $imageFile,
        bool $isEncrypted,
        $blockDevicePath,
        $loopInfo
    ) {
        if ($isEncrypted && !empty($blockDevicePath)) {
            try {
                $this->dmCryptManager->detach($blockDevicePath);
            } catch (Throwable $throwable) {
                $message = "Error occurred removing DmCrypt block device '$blockDevicePath'"
                    . " while creating image file '$imageFile'";
                throw new RuntimeException($message, 0, $throwable);
            }
        }

        if (!empty($loopInfo)) {
            try {
                $this->loopManager->destroy($loopInfo);
            } catch (Throwable $throwable) {
                $message = "Error occurred removing loop block device '$blockDevicePath'"
                    . " while creating image file '$imageFile'";
                throw new RuntimeException($message, 0, $throwable);
            }
        }
    }
}
