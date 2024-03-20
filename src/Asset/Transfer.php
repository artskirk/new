<?php

namespace Datto\Asset;

/**
 * A record for tracking the number of bytes transferred during a backup.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class Transfer
{
    /** @var int */
    private $snapshotEpoch;

    /** @var int */
    private $transferSize;

    /**
     * @param int $snapshotEpoch
     * @param int $transferSize
     */
    public function __construct(int $snapshotEpoch, int $transferSize)
    {
        $this->snapshotEpoch = $snapshotEpoch;
        $this->transferSize = $transferSize;
    }

    /**
     * @return int
     */
    public function getSnapshotEpoch(): int
    {
        return $this->snapshotEpoch;
    }

    /**
     * @return int
     */
    public function getSize(): int
    {
        return $this->transferSize;
    }
}
