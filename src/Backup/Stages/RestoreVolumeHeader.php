<?php

namespace Datto\Backup\Stages;

use Datto\Backup\BackupContext;
use Datto\Common\Utility\Filesystem;

/**
 * This backup stage restores the volume header for NTFS volumes.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class RestoreVolumeHeader extends BackupStage
{
    /** JMP 0x52 NOP NTFS */
    const RESTORED_HEADER = "\xeb\x52\x90NTFS";
    const BYTES_AFTER_HEADER = "\x20\x20\x20\x20";
    const HEADER_BYTE_OFFSET = 32256;
    const NUM_BYTES_IN_HEADER = 7;
    const BYTES_AFTER_HEADER_OFFSET = self::HEADER_BYTE_OFFSET + self::NUM_BYTES_IN_HEADER;
    const BOOT_SECTOR_SIZE_IN_BYTES = 512;

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
            $this->restoreFilesystemHeader($imageLoopsOrFile);
        }
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
    }

    /**
     * Restores the first 7 bytes of the NTFS header that was wiped at backup start.
     *
     * This is when using encryption and to prevent Windows from mounting the iSCSI
     * LUNs, so when the transfer is complete this header needs to be restored. If the
     * header does not look right, it's restored from the HSR file instead.
     *
     * @param string $devMapperBlockDevice
     */
    private function restoreFilesystemHeader(string $devMapperBlockDevice)
    {
        try {
            $fileHandle = $this->filesystem->open($devMapperBlockDevice, 'r+');
            $check = $this->getBytesAfterHeader($fileHandle);
            if ($check === self::BYTES_AFTER_HEADER) {
                $this->restoreIntactHeader($fileHandle, $devMapperBlockDevice);
            } else {
                $this->restoreCorruptHeader($fileHandle, $devMapperBlockDevice);
            }
        } finally {
            $this->filesystem->close($fileHandle);
        }
    }

    /**
     * Get the first four bytes after the header
     *
     * @param resource $fileHandle
     * @return string
     */
    private function getBytesAfterHeader($fileHandle): string
    {
        $this->filesystem->seek($fileHandle, self::BYTES_AFTER_HEADER_OFFSET, SEEK_SET);
        return $this->filesystem->read($fileHandle, 4);
    }

    /**
     * Restore a header that is intact by writing the replaced header values
     *
     * @param resource $fileHandle
     * @param string $devMapperBlockDevice
     */
    private function restoreIntactHeader($fileHandle, string $devMapperBlockDevice)
    {
        $this->filesystem->seek($fileHandle, self::HEADER_BYTE_OFFSET, SEEK_SET);
        $this->filesystem->write($fileHandle, self::RESTORED_HEADER);
        $this->context->getLogger()->debug("BAK3700 Wrote first seven bytes (EB 52 90 \"NTFS\") to " . $devMapperBlockDevice);
    }

    /**
     * Restore a header that appears to be corrupt by copying it from the hrs file
     *
     * @param resource $fileHandle
     * @param string $devMapperBlockDevice
     */
    private function restoreCorruptHeader($fileHandle, string $devMapperBlockDevice)
    {
        $hsrFile = $this->getHsrFileName($devMapperBlockDevice);
        $bootSector = $this->getBootSectorFromHsr($hsrFile);
        if (strlen($bootSector) === self::BOOT_SECTOR_SIZE_IN_BYTES) {
            $this->filesystem->seek($fileHandle, self::HEADER_BYTE_OFFSET, SEEK_SET);
            $this->filesystem->write($fileHandle, $bootSector);
            $this->context->getLogger()->debug("BAK3701 Boot sector was garbage; restored boot sector from HSR file.");
        } else {
            $this->context->getLogger()->warning("BAK3702 Failed to open HSR file ($hsrFile). No action taken to "
                . "make volume accessible; this backup will probably not be usable.");
        }
    }

    /**
     * Get the path to the hsr file
     *
     * @param string $devMapperBlockDevice
     * @return string
     */
    private function getHsrFileName(string $devMapperBlockDevice): string
    {
        $hostname = $this->context->getAsset()->getKeyName();
        $uuid = preg_replace('/-crypt-[a-f0-9]{8}$/', '', basename($devMapperBlockDevice));
        $hsrFile = BackupContext::AGENTS_PATH . $hostname . '/' . $uuid . '.datto_0.hsr';
        return $hsrFile;
    }

    /**
     * Get the boot sector from the hsr file
     *
     * @param string $hsrFile
     * @return string
     */
    private function getBootSectorFromHsr(string $hsrFile): string
    {
        $bootSector = '';
        try {
            $hsrHandle = $this->filesystem->open($hsrFile, 'r');
            if ($hsrHandle) {
                $this->filesystem->seek($hsrHandle, self::BOOT_SECTOR_SIZE_IN_BYTES, SEEK_SET);
                $bootSector = $this->filesystem->read($hsrHandle, self::BOOT_SECTOR_SIZE_IN_BYTES);
            }
        } finally {
            $this->filesystem->close($hsrHandle);
        }

        return $bootSector;
    }
}
