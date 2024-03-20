<?php

namespace Datto\Asset\RecoveryPoint;

/**
 * Results of a local or offsite snapshot deletion request.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class DestroySnapshotResults
{
    /** @var int[] */
    private $destroyedSnapshotEpochs;

    /** @var int[] */
    private $failedSnapshotEpochs;

    /**
     * @param int[] $destroyedSnapshotEpochs
     * @param int[] $failedSnapshotEpochs
     */
    public function __construct(array $destroyedSnapshotEpochs, array $failedSnapshotEpochs)
    {
        $this->destroyedSnapshotEpochs = $destroyedSnapshotEpochs;
        $this->failedSnapshotEpochs = $failedSnapshotEpochs;
    }

    /**
     * @return int[]
     */
    public function getDestroyedSnapshotEpochs(): array
    {
        return $this->destroyedSnapshotEpochs;
    }

    /**
     * @return int[]
     */
    public function getFailedSnapshotEpochs(): array
    {
        return $this->failedSnapshotEpochs;
    }

    /**
     * @return int[][]
     */
    public function toArray(): array
    {
        return [
            'destroyed' => $this->destroyedSnapshotEpochs,
            'failed' => $this->failedSnapshotEpochs
        ];
    }
}
