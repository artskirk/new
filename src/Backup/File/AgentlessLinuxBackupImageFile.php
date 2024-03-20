<?php

namespace Datto\Backup\File;

use Datto\Filesystem\MbrPartition;

/**
 *  Creates Agentless Linux specific sparse files for backup.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class AgentlessLinuxBackupImageFile extends WindowsBackupImageFile
{
    /**
     * @inheritdoc
     */
    protected function getFilesystemType(bool $isGpt, string $filesystem): string
    {
        return MbrPartition::PARTITION_TYPE_LINUX;
    }
}
