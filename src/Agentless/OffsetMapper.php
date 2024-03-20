<?php

namespace Datto\Agentless;

use DiskChangeExtent;

/**
 * Maps start/end byte locations between partition
 * start and file destination.
 *
 * @author Jason Lodice <JLodice@datto.com>
 */
class OffsetMapper
{
    public const SECTOR_SIZE = 512;

    /*** @var int byte location of partition start */
    private $partitionStart;

    /*** @var int byte location of partition end */
    private $partitionEnd;

    /*** @var int size of partition in bytes */
    private $partitionSize;

    /*** @var int byte location of destination start */
    private $destinationStart;

    /**
     * @param array $partition source partition information
     * @param int $destSectorOffset destination start offset in sectors
     */
    public function __construct($partition, $destSectorOffset)
    {
        $this->partitionEnd = $partition['part_end'];
        $this->partitionStart = $partition['part_start'];
        $this->partitionSize = $partition['part_size'];
        $this->destinationStart = $destSectorOffset * self::SECTOR_SIZE;
    }

    /**
     * Create an OffsetMap from a disk change.
     * If no disk change is given, a map will be created for entire partition
     * The map will clip the change to be within the bounds of the source partition.
     *
     * @param $diskChange|null
     * @return OffsetMap|null
     */
    public function getMap(DiskChangeExtent $diskChange = null)
    {
        if ($diskChange === null) {
            $diskChange = $this->getEntirePartitionChange();
        }

        $clippedChange = $this->getClippedDiskChange($diskChange);
        if ($clippedChange === null) {
            //  disk change was out of bounds, don't return a map
            return null;
        }

        $sourceByteOffset = $clippedChange['changeStart'];
        $destByteOffset = $this->destinationStart + $sourceByteOffset - $this->partitionStart;
        $byteLength = $clippedChange['changeLength'];

        return new OffsetMap($sourceByteOffset, $destByteOffset, $byteLength);
    }

    /**
     * Create a disk change representing the entire partition.
     *
     * @return DiskChangeExtent
     */
    private function getEntirePartitionChange()
    {
        return new DiskChangeExtent($this->partitionStart, $this->partitionSize);
    }

    /**
     * Get changed area info clipped to partition bounds.
     *
     * The changed area information returned from VMware's CBT corresponds to
     * a whole VMDK image so it needs to be clipped to the bounds of the
     * partition that we take the backup of.
     *
     * @param DiskChangeExtent $changedArea
     * @return array|null
     *  null if changedArea falls out of partition bounds that we're trying to
     *  backup.
     */
    private function getClippedDiskChange(DiskChangeExtent $changedArea)
    {
        $changeLength = $changedArea->length;
        $changeStart = $changedArea->start;
        $changeEnd = $changeStart + $changeLength - 1;

        // If start offset is past partition end, break
        if ($changeStart > $this->partitionEnd || $changeEnd < $this->partitionStart) {
            return null;
        }

        // Change starts before partition start, adjust start position.
        if ($changeStart < $this->partitionStart) {
            $changeStart = $this->partitionStart;
        }

        // If the change extends beyond end of partition, trim length.
        if ($changeEnd > $this->partitionEnd) {
            $changeEnd = $this->partitionEnd;
        }

        $length = $changeEnd - $changeStart + 1;

        $ret = array(
            'changeStart' => $changeStart,
            'changeEnd' => $changeEnd,
            'changeLength' => $length,
        );

        return $ret;
    }
}
