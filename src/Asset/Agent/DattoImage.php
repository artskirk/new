<?php

namespace Datto\Asset\Agent;

use Datto\Backup\Stages\PrepareAgentVolumes;
use Datto\Block\BlockDeviceManager;
use Datto\Common\Utility\Filesystem;
use Datto\Restore\FileExclusionService;
use Exception;

/**
 * This class encapsulates the handling of the datto image files.
 * This releases the client from having to have multiple code paths for encrypted and unencrypted image files.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class DattoImage
{
    const EXTENSION_DETTO = '.detto';
    const EXTENSION_DATTO = '.datto';
    const EMPTY_BLOCK_DEVICE = '';

    /** @var Agent */
    private $agent;

    /** @var Volume */
    private $volume;

    /** @var string */
    private $imageDirectory;

    /** @var string */
    private $blockDevice;

    /** @var Filesystem */
    private $filesystem;

    /** @var BlockDeviceManager */
    private $blockDeviceManager;

    /** @var EncryptionService */
    private $encryptionService;

    /** @var int */
    private $referenceCount;

    /**
     * @param Agent $agent
     * @param Volume $volume
     * @param string $imageDirectory
     * @param Filesystem $filesystem
     * @param BlockDeviceManager $blockDeviceManager
     * @param EncryptionService $encryptionService
     */
    public function __construct(
        Agent $agent,
        Volume $volume,
        string $imageDirectory,
        Filesystem $filesystem,
        BlockDeviceManager $blockDeviceManager,
        EncryptionService $encryptionService
    ) {
        $this->agent = $agent;
        $this->volume = $volume;
        $this->imageDirectory = $imageDirectory;
        $this->blockDevice = static::EMPTY_BLOCK_DEVICE;

        $this->filesystem = $filesystem;
        $this->blockDeviceManager = $blockDeviceManager;
        $this->encryptionService = $encryptionService;
        $this->referenceCount = 0;
    }

    /**
     * Release the block device if it has been acquired.
     */
    public function __destruct()
    {
        $this->referenceCount = 0;
        $this->release();
    }

    /**
     * Acquire a block device associated with datto image files in a given directory.
     * The method, release, must be called when usage has completed to clean up underlying
     * device mappers and loops.
     *
     * @param bool $readOnly
     * @param bool $runPartitionScan
     */
    public function acquire(
        bool $readOnly = false,
        bool $runPartitionScan = false
    ) {
        $this->referenceCount++;
        if ($this->blockDevice !== static::EMPTY_BLOCK_DEVICE) {
            return;
        }

        $imageFile = $this->getImageFilePath();
        $this->assertFileExists($imageFile);

        if ($this->agent->getEncryption()->isEnabled()) {
            $encryptionKey = $this->encryptionService->getAgentCryptKey($this->agent->getKeyName());
            $this->blockDevice = $this->blockDeviceManager->acquireForEncryptedFile(
                $imageFile,
                $encryptionKey,
                $this->volume->getGuid(),
                $readOnly,
                $runPartitionScan
            );
        } else {
            $this->blockDevice = $this->blockDeviceManager->acquireForUnencryptedFile(
                $imageFile,
                $readOnly,
                $runPartitionScan
            );
        }
    }

    /**
     * Release the underlying device mappers and loops needed to present the client with the block devices.
     */
    public function release()
    {
        if ($this->referenceCount > 1) {
            $this->referenceCount--;
            return;
        }

        if ($this->blockDevice !== static::EMPTY_BLOCK_DEVICE &&
            $this->filesystem->exists($this->blockDevice)) {
            if ($this->agent->getEncryption()->isEnabled()) {
                $this->blockDeviceManager->releaseForEncryptedFile($this->blockDevice);
            } else {
                $this->blockDeviceManager->releaseForUnencryptedFile($this->blockDevice);
            }
            $this->referenceCount--;
        }
        $this->blockDevice = static::EMPTY_BLOCK_DEVICE;
    }

    /**
     * Determine whether an image file exists.
     *
     * @return bool True if the image file exists, False otherwise.
     */
    public function imageFileExists(): bool
    {
        $imageFile = $this->getImageFilePath();
        return $this->filesystem->exists($imageFile);
    }

    /**
     * @return Volume
     */
    public function getVolume(): Volume
    {
        return $this->volume;
    }

    /**
     * Get the acquired block device.
     * If the block device has not been acquired or has been released,
     * self::EMPTY_BLOCK_DEVICE will be returned.
     *
     * @return string
     */
    public function getBlockDevice(): string
    {
        return $this->blockDevice;
    }

    /**
     * Get path to the specified partition number under this block device
     *
     * @param int $number
     * @return string
     */
    public function getPathToPartition(int $number = 1): string
    {
        return $this->blockDeviceManager->getPathToPartition($this->getBlockDevice(), $number);
    }

    /**
     * Get the full path to the image file.
     *
     * @return string
     */
    public function getImageFilePath(): string
    {
        $imageExtension = $this->getImageFileExtension();
        $imageFile = $this->imageDirectory . '/' . $this->volume->getGuid() . $imageExtension;
        return $imageFile;
    }

    /**
     * Get the full path to the checksum file.
     *
     * @return string
     */
    public function getChecksumFilePath(): string
    {
        $checksumFile = sprintf(
            '%s/%s.%s',
            $this->imageDirectory,
            $this->volume->getGuid(),
            PrepareAgentVolumes::CHECKSUM_EXTENSION
        );

        return $checksumFile;
    }

    /**
     * Get the full path to the exclusion file.
     *
     * @return string
     */
    public function getFileExlusionFilePath(): string
    {
        $exclusionFile = sprintf(
            '%s/%s',
            $this->imageDirectory,
            $this->volume->getGuid()
        );

        return $exclusionFile . FileExclusionService::EXCLUSIONS_FILE_EXTENSION;
    }

    /**
     * Get the image file extension
     *
     * @return string
     */
    private function getImageFileExtension(): string
    {
        return $this->agent->getEncryption()->isEnabled() ? static::EXTENSION_DETTO : static::EXTENSION_DATTO;
    }

    /**
     * Validate that the file exists
     *
     * @param string $imageFile
     */
    private function assertFileExists(string $imageFile): void
    {
        if (!$this->filesystem->exists($imageFile)) {
            throw new Exception('Image file ' . $imageFile . ' does not exist!');
        }
    }
}
