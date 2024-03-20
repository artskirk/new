<?php

namespace Datto\Backup\Stages;

use Datto\Common\Utility\Filesystem;

/**
 * This backup stage clears the filesystem header for NTFS volumes.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class ClearVolumeHeader extends BackupStage
{
    const HEADER_BYTE_OFFSET = 32256;
    const NUM_BYTES_IN_HEADER = 7;

    /** @var Filesystem */
    private $filesystem;

    public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * @inheritdoc
     */
    public function commit()
    {
        $imageLoopsOrFiles = $this->context->getImageLoopsOrFiles();
        foreach ($imageLoopsOrFiles as $imageLoopsOrFile) {
            $this->clearFilesystemHeader($imageLoopsOrFile);
        }
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
    }

    /**
     * Clear the filesystem header.
     * Blank the first seven bytes to force windows not to mount the volume.
     *
     * @param string $devMapperBlockDevice
     */
    private function clearFilesystemHeader(string $devMapperBlockDevice)
    {
        try {
            $fileHandle = $this->filesystem->open($devMapperBlockDevice, 'r+');
            $this->filesystem->seek($fileHandle, self::HEADER_BYTE_OFFSET, SEEK_SET);
            $this->filesystem->write($fileHandle, str_repeat("\x00", self::NUM_BYTES_IN_HEADER));
        } finally {
            $this->filesystem->close($fileHandle);
        }
    }
}
