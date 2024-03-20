<?php

namespace Datto\Filesystem;

/**
 * @author Jason Lodice <jlodice@datto.com>
 */
class GptPartition extends AbstractPartition
{
    const PARTITION_TYPE_MICROSOFT_BASIC = '0700';
    const PARTITION_TYPE_EFI_SYSTEM = 'EF00';
    const PARTITION_TYPE_HFS = 'af00';

    /** @var int|null */
    private $sectorAlignment;

    /**
     * @param string $blockDevice Path to the block device representing this partition
     * @param int $partitionNumber The number of this partition in a disk
     * @param string $partitionType Partition type value to pass to the gdisk command.
     *   Preference is to use the PARTITION_TYPE constants in this class.
     * @param bool $bootable True if this partition is a boot partition, False otherwise
     */
    public function __construct(string $blockDevice, int $partitionNumber, string $partitionType, bool $bootable = true)
    {
        parent::__construct($blockDevice, $partitionNumber, $partitionType, $bootable);
    }

    /**
     * Optional value used to force sector alignment in gdisk
     *
     * @return int|null
     */
    public function getSectorAlignment()
    {
        return $this->sectorAlignment;
    }

    /**
     * @param int|null $sectorAlignment
     */
    public function setSectorAlignment($sectorAlignment)
    {
        $this->sectorAlignment = $sectorAlignment;
    }
}
