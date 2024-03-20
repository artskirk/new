<?php
namespace Datto\Config\Virtualization;

/**
 * Provides info on what files make up a complete virtual disk usable in VM.
 *
 * Currently the backup/HIR process generate two files during/after backup:
 *  - RAW *.datto file.
 *  - VMDK file that references the *.datto file.
 *
 * In some cases one is used over the other, so this class keeps that
 * relationship info in one spot.
 */
class VirtualDisk
{
    /** @var string */
    private $rawFileName = '';
    /** @var string */
    private $vmdkFileName = '';
    /** @var string */
    private $storageLocation = '';
    /** @var bool */
    private $isGpt;

    /**
     * @param string $rawFileName
     * @param string $vmdkFileName
     * @param string $storageLocation
     * @param bool $isGpt
     */
    public function __construct(
        string $rawFileName,
        string $vmdkFileName,
        string $storageLocation,
        bool $isGpt = false
    ) {
        $this->rawFileName = $rawFileName;
        $this->vmdkFileName = $vmdkFileName;
        $this->storageLocation = $storageLocation;
        $this->isGpt = $isGpt;
    }

    /**
     * Get the name of the RAW disk image file.
     *
     * Usually the backing file for the VMDK.
     *
     * @return string
     */
    public function getRawFileName(): string
    {
        return $this->rawFileName;
    }

    /**
     * Get the VMDK file name.
     *
     * @return string
     */
    public function getVmdkFileName(): string
    {
        return $this->vmdkFileName;
    }

    /**
     * Get the path to a directory of the disk images.
     *
     * @return string
     */
    public function getStorageLocation(): string
    {
        return $this->storageLocation;
    }

    /**
     * Whether the disk is using GPT parition scheme.
     *
     * @return bool
     */
    public function isGpt(): bool
    {
        return $this->isGpt;
    }
}
