<?php

namespace Datto\Backup\File;

use Datto\Filesystem\SparseFileService;
use InvalidArgumentException;
use Datto\Log\DeviceLoggerInterface;

/**
 * Represents a sparse file used for storing a backup image that
 * does not do any doctoring of the partition table. It just stores
 * the raw bits of the full disk.
 *
 * @author Chad Barbe <cbarbe@datto.com>
 */
class FullDiskBackupImageFile implements BackupImageFile
{
    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var SparseFileService */
    private $sparseFileService;

    public function __construct(DeviceLoggerInterface $logger, SparseFileService $sparseFileService)
    {
        $this->logger = $logger;
        $this->sparseFileService = $sparseFileService;
    }

    /**
     * @inheritdoc
     */
    public function resizeIfNeeded(
        string $imageFile,
        int $volumeSize,
        string $filesystem,
        bool $useGpt,
        bool $isEncrypted,
        string $encryptionKey = null
    ): bool {
        return false;
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
            $this->createSparseFile($imageFile, $volumeGuid, $volumeSize);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Error creating backup image file '$imageFile'", 0, $e);
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
            $fileSize
        );
    }

    /**
     * @inheritdoc
     */
    public function getBaseSectorOffset(): int
    {
        return 0;
    }

    /**
     * @inheritdoc
     */
    public function getImageOverheadInSectors(): int
    {
        return 0;
    }

    /**
     * Return how many bytes of overhead datto has added for this particular
     * type of OS
     *
     * @return int
     */
    public function getImageOverheadInBytes(): int
    {
        return 0;
    }

    /**
     * Full disk backups have no partition table so this is just a stub method
     * for PrepareAgentVolumes to call on a partition table rewrite
     */
    public function createOrUpdatePartition(
        string $imageFile,
        string $filesystem,
        bool $useGpt,
        bool $isEncrypted,
        ?string $encryptionKey = null
    ): void {
        return;
    }
}
