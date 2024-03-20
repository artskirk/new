<?php

namespace Datto\Restore\Insight\InsightStages;

use Datto\Restore\Insight\InsightStatus;

/**
 * Handles the creation and deletion of zfs clones for insights selected points.
 * Points are cloned and mounted so that files may be downloaded from them.
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class CloneSnapshotStage extends InsightStage
{
    /**
     * Creates zfs clones for each insight epoch with the format:
     * homePool/AGENTKEYNAME-SNAPSHOTEPOCH-mft
     */
    public function commit()
    {
        try {
            $this->createPointClone($this->insight->getSecondPoint());
            $this->createPointClone($this->insight->getFirstPoint());
        } catch (\Throwable $e) {
            $this->writeStatus(InsightStatus::STATUS_FAILED, true, true);
            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup()
    {
       // No cleanup
    }

    /**
     * Unmounts loop devices, removes them, then destroys the zfs clones
     */
    public function rollback()
    {
        $this->destroyClone($this->insight->getFirstPoint());
        $this->destroyClone($this->insight->getSecondPoint());
    }

    /**
     * @param int $snapshotEpoch
     */
    private function destroyClone(int $snapshotEpoch)
    {
        try {
            $this->cloneManager->destroyClone($this->getCloneSpec($snapshotEpoch));
        } catch (\Throwable $e) {
            $this->logger->warning('INS0001 Rollback failed, unable to destroy clones', ['exception' => $e]);
            throw $e;
        }
    }

    /**
     * @param int $snapshotEpoch
     */
    private function createPointClone(int $snapshotEpoch)
    {
        try {
            $this->writeStatus(InsightStatus::STATUS_CLONING);

            $this->cloneManager->createClone($this->getCloneSpec($snapshotEpoch));
        } catch (\Throwable $e) {
            $this->logger->warning('INS0002 Commit failed, unable to create clones', ['exception' => $e]);
            throw $e;
        }
    }
}
