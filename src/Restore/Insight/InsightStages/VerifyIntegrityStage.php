<?php

namespace Datto\Restore\Insight\InsightStages;

use Datto\Asset\RecoveryPoint\RecoveryPoint;
use Datto\Filesystem\FilesystemCheckResult;
use Datto\Restore\Insight\InsightStatus;

/**
 * Ensure that volumes specified did not fail filesystem integrity at backup time
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class VerifyIntegrityStage extends InsightStage
{
    /**
     * Gather integrity results for all volumes from the two insight snapshot epochs
     */
    public function commit()
    {
        $firstPoint = $this->insight->getAgent()->getLocal()->getRecoveryPoints()->get($this->insight->getFirstPoint());
        $secondPoint = $this->insight->getAgent()->getLocal()->getRecoveryPoints()->get($this->insight->getSecondPoint());

        $this->assertNoIntegrityCheckFailedVolumes($firstPoint);
        $this->assertNoIntegrityCheckFailedVolumes($secondPoint);
    }

    public function cleanup()
    {
        // No cleanup
    }

    public function rollback()
    {
        // No rollback
    }

    private function assertNoIntegrityCheckFailedVolumes(?RecoveryPoint $recoveryPoint)
    {
        if ($recoveryPoint === null) {
            return;
        }

        foreach ($recoveryPoint->getFilesystemCheckResults() as $guid => $checkResult) {
            if ($checkResult->getResultCode() === FilesystemCheckResult::RESULT_FOUND_ERRORS) {
                $this->writeStatus(InsightStatus::STATUS_FAILED, true, true);
                throw new \Exception("$guid failed integrity check, unable to run insights");
            }
        }
    }
}
