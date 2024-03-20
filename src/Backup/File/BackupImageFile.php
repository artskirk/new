<?php

namespace Datto\Backup\File;

/**
 * Interface used to create OS specific sparse files for backup.
 *
 * @author Chad Barbe <cbarbe@datto.com>
 */
interface BackupImageFile
{
    const SECTOR_SIZE_IN_BYTES = 512;

    /**
     * Create the backup sparse file
     *
     * @param string $imageFile
     * @param int $volumeSize
     * @param string $filesystem
     * @param string $volumeGuid
     * @param bool $useGpt
     * @param bool $isEncrypted
     * @param string|null $encryptionKey
     */
    public function create(
        string $imageFile,
        int $volumeSize,
        string $filesystem,
        string $volumeGuid,
        bool $useGpt,
        bool $isEncrypted,
        string $encryptionKey = null
    );

    /**
     * Resize an existing image file if the volume size has increased.
     *
     * @param string $imageFile
     * @param int $volumeSize
     * @param string $filesystem
     * @param bool $useGpt
     * @param bool $isEncrypted
     * @param string|null $encryptionKey
     * @return bool True if the image file was resized
     */
    public function resizeIfNeeded(
        string $imageFile,
        int $volumeSize,
        string $filesystem,
        bool $useGpt,
        bool $isEncrypted,
        string $encryptionKey = null
    ): bool;

    /**
     * Returns the base sector offset after the stuff datto adds for the
     * particular type of os.
     *
     * @return int
     */
    public function getBaseSectorOffset(): int;

    /**
     * Return how many bytes of overhead datto has added for this particular
     * type of OS
     *
     * @return int
     */
    public function getImageOverheadInBytes(): int;

    /**
     * Create or update the partition table in the image file.
     *
     * Note on updates:
     * When a volume is resized, the image file is truncated to make it larger. Then, this function
     * is called to update the partition information to have the correct length of the new volume.
     *
     * @param string $imageFile
     * @param string $filesystem
     * @param bool $useGpt
     * @param bool $isEncrypted
     * @param string|null $encryptionKey
     */
    public function createOrUpdatePartition(
        string $imageFile,
        string $filesystem,
        bool $useGpt,
        bool $isEncrypted,
        string $encryptionKey = null
    ): void;
}
