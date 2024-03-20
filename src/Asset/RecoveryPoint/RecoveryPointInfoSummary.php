<?php

namespace Datto\Asset\RecoveryPoint;

/**
 * Summarized information for recovery-points.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class RecoveryPointInfoSummary
{
    /** @var int|null */
    private $localUsedSize;

    /** @var int|null */
    private $offsiteUsedSize;

    /** @var int|null */
    private $latestLocalSnapshotEpoch;

    /** @var int|null */
    private $latestOffsiteSnapshotEpoch;

    /** @var int|null */
    private $rateOfChange;

    /** @var int|null */
    private $rateOfGrowthAbsolute;

    /** @var float|null */
    private $rateOfGrowthPercent;

    /**
     * @param int|null $localUsedSize
     * @param int|null $offsiteUsedSize
     * @param int|null $latestLocalSnapshotEpoch
     * @param int|null $latestOffsiteSnapshotEpoch
     * @param int|null $rateOfChange
     * @param int|null $growthAbsolute
     * @param float|null $growthPercent
     */
    public function __construct(
        int $localUsedSize = null,
        int $offsiteUsedSize = null,
        int $latestLocalSnapshotEpoch = null,
        int $latestOffsiteSnapshotEpoch = null,
        int $rateOfChange = null,
        int $growthAbsolute = null,
        float $growthPercent = null
    ) {
        $this->localUsedSize = $localUsedSize;
        $this->offsiteUsedSize = $offsiteUsedSize;
        $this->latestLocalSnapshotEpoch = $latestLocalSnapshotEpoch;
        $this->latestOffsiteSnapshotEpoch = $latestOffsiteSnapshotEpoch;
        $this->rateOfChange = $rateOfChange;
        $this->rateOfGrowthAbsolute = $growthAbsolute;
        $this->rateOfGrowthPercent = $growthPercent;
    }

    /**
     * @return int|null
     */
    public function getRateOfChange()
    {
        return $this->rateOfChange;
    }

    /**
     * @return int|null
     */
    public function getRateOfGrowthAbsolute()
    {
        return $this->rateOfGrowthAbsolute;
    }

    /**
     * @return int|null
     */
    public function getRateOfGrowthPercent()
    {
        return $this->rateOfGrowthPercent;
    }

    /**
     * @return int|null
     */
    public function getLocalUsedSize()
    {
        return $this->localUsedSize;
    }

    /**
     * @return int|null
     */
    public function getOffsiteUsedSize()
    {
        return $this->offsiteUsedSize;
    }

    /**
     * @return int|null
     */
    public function getLatestLocalSnapshotEpoch()
    {
        return $this->latestLocalSnapshotEpoch;
    }

    /**
     * @return int|null
     */
    public function getLatestOffsiteSnapshotEpoch()
    {
        return $this->latestOffsiteSnapshotEpoch;
    }

    /**
     * @return array
     */
    public function toArray()
    {
        return [
            'localUsedSize' => $this->getLocalUsedSize(),
            'offsiteUsedSize' => $this->getOffsiteUsedSize(),
            'latestLocalSnapshotEpoch' => $this->getLatestLocalSnapshotEpoch(),
            'latestOffsiteSnapshotEpoch' => $this->getLatestOffsiteSnapshotEpoch(),
            'rateOfChange' => $this->getRateOfChange(),
            'rateOfGrowthAbsolute' => $this->getRateOfGrowthAbsolute(),
            'rateOfGrowthPercent' => $this->getRateOfGrowthPercent()
        ];
    }
}
