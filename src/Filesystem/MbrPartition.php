<?php

namespace Datto\Filesystem;

/**
 * Represents an MBR Partition
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class MbrPartition extends AbstractPartition
{
    const PARTITION_TYPE_LINUX = '83';
    const PARTITION_TYPE_NTFS = '7';
    const PARTITION_TYPE_FAT32 = 'b';

    /** @var MbrType */
    private $mbrType;

    /** @var bool */
    private $isDosCompatible;

    /**
     * @param string $blockDevice
     * @param int $partitionNumber
     * @param bool $bootable
     */
    public function __construct(string $blockDevice, int $partitionNumber, bool $bootable = true)
    {
        parent::__construct($blockDevice, $partitionNumber, self::PARTITION_TYPE_LINUX, $bootable);
        $this->mbrType = MbrType::PRIMARY();
        $this->isDosCompatible = false;
    }

    /**
     * @return MbrType
     */
    public function getMbrType(): MbrType
    {
        return $this->mbrType;
    }

    /**
     * @param MbrType $type
     */
    public function setMbrType(MbrType $type)
    {
        $this->mbrType = $type;
    }

    /**
     * @return bool
     */
    public function isDosCompatible(): bool
    {
        return $this->isDosCompatible;
    }

    /**
     * @param bool $dosCompatible
     */
    public function setDosCompatible(bool $dosCompatible)
    {
        $this->isDosCompatible = $dosCompatible;
    }
}
