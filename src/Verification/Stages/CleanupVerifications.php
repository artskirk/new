<?php
namespace Datto\Verification\Stages;

use Datto\System\Transaction\TransactionException;
use Datto\Verification\VerificationCleanupManager;
use Datto\Verification\VerificationResultType;
use Throwable;

/**
 * Clean up remnants of previous verifications.
 *
 * Logs messages with the VER prefix in the range 0200-0299.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 * @author Matt Coleman <mcoleman@datto.com>
 * @author Matt Cheman <mcheman@datto.com>
 */
class CleanupVerifications extends VerificationStage
{
    /** @var VerificationCleanupManager */
    private $verificationCleanupManager;

    public function __construct(VerificationCleanupManager $verificationCleanupManager)
    {
        $this->verificationCleanupManager = $verificationCleanupManager;
    }

    public function commit()
    {
        try {
            $assetName = $this->context->getAgent()->getKeyName();

            $this->logger->debug('VER0200 Cleaning up verifications for ' . $assetName);

            $isClean = $this->performCleanup();

            $message = null;

            if ($isClean === false) {
                $message = "Aborting verification as it appears that there's another verification process already running.";
                $this->logger->debug('VER0201 ' . $message);
            }
        } catch (Throwable $e) {
            $result = VerificationResultType::FAILURE_UNRECOVERABLE();
            $this->setResult($result, $e->getMessage(), $e->getTraceAsString());
            throw $e;
        }

        $result = $isClean ? VerificationResultType::SUCCESS() : VerificationResultType::FAILURE_INTERMITTENT();
        $this->setResult($result, $message);

        if (!$isClean) {
            throw new TransactionException('Cleanup verifications failed. Error message: ' . $this->result->getErrorMessage());
        }
    }

    public function cleanup()
    {
        // No cleanup required for this stage.
    }

    /**
     * @return bool
     */
    private function performCleanup()
    {
        $agent = $this->context->getAgent();
        $agentKey = $agent->getKeyName();
        $snapshotEpoch = $this->context->getSnapshotEpoch();

        $this->verificationCleanupManager->removeOldResults($agent, $snapshotEpoch);

        $isClean = $this->verificationCleanupManager->cleanupVerifications($agentKey);

        return $isClean;
    }
}
