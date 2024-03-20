<?php

namespace Datto\Asset\Agent;

/**
 * Class to represent a disk/drive of an agent.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class Volume
{
    const FILESYSTEM_REFS = 'ReFS';

    /** @var int Number of hidden sectors, e.g. 2048 */
    private $hiddenSectors;

    /** @var string Local mount point of this drive on the agent, e.g. C:\ */
    private $mountpoint;

    /** @var string Partition scheme of the underlying disk, e.g. MBR or GPT */
    private $partScheme;

    /** @var int Total space available in bytes, e.g. 26736590848 */
    private $spaceTotal;

    /** @var int Free space in bytes, e.g. 6295453696 */
    private $spaceFree;

    /** @var int Space used in bytes, e.g. 20441137152 */
    private $spaceUsed;

    /** @var int Size of a disk sector in bytes, e.g. 512 */
    private $sectorSize;

    /** @var string Unique identifier of a disk/partition, e.g. 08d485d3942f11e5bfe0080027d1d2c3 */
    private $guid;

    /** @var string */
    private $diskUuid;

    /** @var bool Flag to indicate whether this partition is an OS volume */
    private $osVolume;

    /** @var int Cluster size in bytes, e.g. 4096 */
    private $clusterSize;

    /** @var string Real partition scheme of the underlying disk, e.g. MBR or GPT */
    private $realPartScheme;

    /** @var bool Flag to indicate that this is a reserved partition, e.g. true for "System Reserved" */
    private $sysVolume;

    /** @var int Serial number of the disk, e.g. 3789808428 */
    private $serialNumber;

    /** @var string Type of the volume, e.g. 'basic' */
    private $volumeType; // TODO What is this?

    /** @var string Label of the partition, e.g. "Windows Partition" */
    private $label;

    /** @var bool Flag to indicate if the disk is removable */
    private $removable;

    /** @var string Flag to indicate the partition filesystem, e.g. "NTFS" */
    private $filesystem;

    /** @var string Block device (used by Linux agent) */
    private $blockDevice;

    /** @var bool whether this volume is included in snapshots */
    private $included;

    /** @var string[] Array with all mountpoints for the volume */
    private $mountpointsArray;

    /**
     * @param int $hiddenSectors Number of hidden sectors, e.g. 2048
     * @param string $mountpoint Local mountpoint of this drive on the agent, e.g. C:\
     * @param string $partScheme Partition scheme of the underlying disk, e.g. MBR or GPT
     * @param int $spaceTotal Total space available in bytes, e.g. 26736590848
     * @param int $spaceFree Free space in bytes, e.g. 6295453696
     * @param int $spaceUsed Space used in bytes, e.g. 20441137152
     * @param int $sectorSize Size of a disk sector in bytes, e.g. 512
     * @param string $guid Unique identifier of a disk/partition, e.g. 08d485d3942f11e5bfe0080027d1d2c3
     * @param string $diskUuid
     * @param bool $osVolume Flag to indicate whether this partition is an OS volume
     * @param int $clusterSize Cluster size in bytes, e.g. 4096
     * @param string $realPartScheme Real partition scheme of the underlying disk, e.g. MBR or GPT
     * @param bool $sysVolume Flag to indicate that this is a reserved partition, e.g. true for "System Reserved"
     * @param int $serialNumber Serial number of the disk, e.g. 3789808428
     * @param string $volumeType Type of the volume, e.g. 'basic'
     * @param string $label Label of the partition, e.g. "Windows Partition"
     * @param bool $removable Flag to indicate if the disk is removable
     * @param string $filesystem Flag to indicate the partition filesystem, e.g. "NTFS"
     * @param string $blockDevice Block device (used by Linux agent)
     * @param bool $included whether the volume is included in backups
     * @param array $mountpointsArray Array of mountpoint strings
     */
    public function __construct(
        $hiddenSectors,
        $mountpoint,
        $partScheme,
        $spaceTotal,
        $spaceFree,
        $spaceUsed,
        $sectorSize,
        $guid,
        $diskUuid,
        $osVolume,
        $clusterSize,
        $realPartScheme,
        $sysVolume,
        $serialNumber,
        $volumeType,
        $label,
        $removable,
        $filesystem,
        $blockDevice,
        $included,
        array $mountpointsArray = []
    ) {
        $this->hiddenSectors = $hiddenSectors;
        $this->mountpoint = $mountpoint;
        $this->partScheme = $partScheme;
        $this->spaceTotal = $spaceTotal;
        $this->spaceFree = $spaceFree;
        $this->spaceUsed = $spaceUsed;
        $this->sectorSize = $sectorSize;
        $this->guid = $guid;
        $this->diskUuid = $diskUuid;
        $this->osVolume = $osVolume;
        $this->clusterSize = $clusterSize;
        $this->realPartScheme = $realPartScheme;
        $this->sysVolume = $sysVolume;
        $this->serialNumber = $serialNumber;
        $this->volumeType = $volumeType;
        $this->label = $label;
        $this->removable = $removable;
        $this->filesystem = $filesystem;
        $this->blockDevice = $blockDevice;
        $this->included = $included;
        $this->mountpointsArray = $mountpointsArray;
    }

    /**
     * @return int
     */
    public function getHiddenSectors()
    {
        return $this->hiddenSectors;
    }

    /**
     * @return string
     */
    public function getMountpoint()
    {
        return $this->mountpoint;
    }

    /**
     * @return string
     */
    public function getPartScheme()
    {
        return $this->partScheme;
    }

    /**
     * @return int
     */
    public function getSpaceTotal()
    {
        return $this->spaceTotal;
    }

    /**
     * @return int
     */
    public function getSpaceFree()
    {
        return $this->spaceFree;
    }

    /**
     * @return int
     */
    public function getSpaceUsed()
    {
        return $this->spaceUsed;
    }

    /**
     * @return int
     */
    public function getSectorSize()
    {
        return $this->sectorSize;
    }

    /**
     * @return string
     */
    public function getGuid()
    {
        return $this->guid;
    }

    /**
     * @return string
     */
    public function getDiskUuid()
    {
        return $this->diskUuid;
    }

    /**
     * @return boolean
     */
    public function isOsVolume()
    {
        return $this->osVolume;
    }

    /**
     * @return int
     */
    public function getClusterSize()
    {
        return $this->clusterSize;
    }

    /**
     * @return string
     */
    public function getRealPartScheme()
    {
        return $this->realPartScheme;
    }

    /**
     * @return boolean
     */
    public function isSysVolume()
    {
        return $this->sysVolume;
    }

    /**
     * @return int
     */
    public function getSerialNumber()
    {
        return $this->serialNumber;
    }

    /**
     * @return string
     */
    public function getVolumeType()
    {
        return $this->volumeType;
    }

    /**
     * @return string
     */
    public function getLabel()
    {
        return $this->label;
    }

    /**
     * @return boolean
     */
    public function isRemovable()
    {
        return $this->removable;
    }

    /**
     * @return string
     */
    public function getFilesystem()
    {
        return $this->filesystem;
    }

    /**
     * @return string
     */
    public function getBlockDevice()
    {
        return $this->blockDevice;
    }

    /**
     * @return bool
     */
    public function isIncluded()
    {
        return $this->included;
    }

    public function getMountpointsArray(): array
    {
        return $this->mountpointsArray;
    }

    public function toArray(): array
    {
        return [
            'hiddenSectors' => $this->hiddenSectors,
            'mountpoints' => $this->mountpoint,
            'partScheme' => $this->partScheme,
            'spaceTotal' => $this->spaceTotal,
            'spaceFree' => $this->spaceFree,
            'spaceUsed' => $this->spaceUsed,
            'sectorSize' => $this->sectorSize,
            'guid' => $this->guid,
            'diskUuid' => $this->diskUuid,
            'OSVolume' => $this->osVolume,
            'clusterSize' => $this->clusterSize,
            'realPartScheme' => $this->realPartScheme,
            'sysVolume' => $this->sysVolume,
            'serialNumber' => $this->serialNumber,
            'volumeType' => $this->volumeType,
            'label' => $this->label,
            'removable' => $this->removable,
            'filesystem' => $this->filesystem,
            'blockDevice' => $this->blockDevice,
            'included' => $this->included,
            'mountpointsArray' => $this->mountpointsArray
        ];
    }
}
