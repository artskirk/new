<?php

namespace Datto\Backup\File;

use Datto\Filesystem\GptPartition;

/**
 *  Creates Mac specific sparse files for backup.
 *
 * @deprecated Mac agents are essentially not supported. Do not worry about maintaining code in here. It is ok to
 * completely remove the Mac code if it becomes an obstacle to future features/fixes, just ensure we have release notes
 * for the removal.
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class MacBackupImageFile extends SingleVolumeBackupImageFile
{
    const MAC_BASE_SECTOR_OFFSET = 2048;

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
    protected function getFilesystemType(bool $isGpt, string $filesystem): string
    {
        return GptPartition::PARTITION_TYPE_HFS;
    }

    /**
     * @inheritdoc
     */
    public function getBaseSectorOffset(): int
    {
        return self::MAC_BASE_SECTOR_OFFSET;
    }
}
