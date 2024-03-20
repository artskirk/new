<?php

namespace Datto\Backup\File;

use Datto\Filesystem\MbrPartition;

/**
 *  Creates Linux specific sparse files for backup.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class LinuxBackupImageFile extends SingleVolumeBackupImageFile
{
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
        return MbrPartition::PARTITION_TYPE_LINUX;
    }
}
