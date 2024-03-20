<?php

namespace Datto\Backup\File;

use Datto\Filesystem\GptPartition;
use Datto\Filesystem\MbrPartition;

/**
 *  Creates Windows specific sparse files for backup.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class WindowsBackupImageFile extends SingleVolumeBackupImageFile
{
    const FAT32 = 'FAT32';

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
        $wasResized = false;

        if (!$this->filesystem->exists($imageFile)) {
            throw new \Exception('Image file at ' . $imageFile . ' does not exist');
        }

        $expectedFileSize = $this->getFileSizeWithOverhead($volumeSize, $useGpt);
        $actualFileSize = $this->filesystem->getSize($imageFile);

        if ($expectedFileSize > $actualFileSize) {
            $this->logger->debug(
                'BAK5010 Disk Change detected - Expanding image file ' .
                $imageFile .
                ' from [' . $actualFileSize . ']' .
                ' to [' . $expectedFileSize . '].'
            );

            $this->resizeImageFile($imageFile, $expectedFileSize);
            $this->createOrUpdatePartition($imageFile, $filesystem, $useGpt, $isEncrypted, $encryptionKey);
            $wasResized = true;

            $this->logger->debug('BAK5020 Resizing image file ' . $imageFile . ' complete.');
        }

        return $wasResized;
    }

    /**
     * @inheritdoc
     */
    protected function getFilesystemType(bool $isGpt, string $filesystem): string
    {
        if ($isGpt) {
            $filesystemType = GptPartition::PARTITION_TYPE_MICROSOFT_BASIC;
        } else {
            $filesystemType = $filesystem === self::FAT32
                ? MbrPartition::PARTITION_TYPE_FAT32
                : MbrPartition::PARTITION_TYPE_NTFS;
        }

        return $filesystemType;
    }

    /**
     * Resize an image file
     *
     * @param string $imageFile Full path of the image file that is to be resized
     * @param int $expectedFileSize File size to set the image to
     */
    private function resizeImageFile(string $imageFile, int $expectedFileSize)
    {
        $this->logger->debug(
            'BAK5012 Truncating ' . $imageFile . ' to new file size of [' . $expectedFileSize . ']'
        );

        $handle = $this->filesystem->open($imageFile, 'r+');
        $this->filesystem->truncate($handle, $expectedFileSize);
        $this->filesystem->close($handle);
    }
}
