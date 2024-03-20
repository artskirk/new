<?php

namespace Datto\Restore\PushFile\Stages;

use Datto\Asset\Agent\Api\AgentTransferState;
use Datto\Common\Resource\Sleep;
use Datto\Resource\DateTimeService;
use Datto\Restore\PushFile\AbstractPushFileRestoreStage;
use Datto\Restore\PushFile\PushFileRestoreStatus;
use Exception;

/**
 * Transfer the data from the device to the agent.
 *
 * @author Ryan Mack <rmack@datto.com>
 */
class TransferRestoreDataStage extends AbstractPushFileRestoreStage
{
    private Sleep $sleep;

    private DateTimeService $dateTimeService;

    public function __construct(Sleep $sleep, DateTimeService $dateTimeService)
    {
        $this->sleep = $sleep;
        $this->dateTimeService = $dateTimeService;
    }

    public function commit()
    {
        $this->logger->info("TRS0001 Beginning data transfer for push file restore", ['files' => $this->context->getPushFiles()]);

        // Call the API on the agent to initiate the transfer
        $restoreID = $this->context->getAgentApi()->startPushFileRestore($this->context)['restoreId'];

        // Monitor the progress
        $isComplete = false;
        $lastBytesTransferred = 0;
        $lastProgressTime = $this->dateTimeService->getTime();
        while (!$isComplete) {
            // Updating the status will throw if there is an invalid status returned from the API.
            $pushFileRestoreStatus = $this->context->getAgentApi()->getPushFileRestoreStatus($restoreID);

            $percentComplete = 100 * ($pushFileRestoreStatus->getBytesTransferred() / $pushFileRestoreStatus->getTotalSize());
            $this->logger->debug("TRS0002 Transferring data...", ['percentComplete' => $percentComplete]);
            // If it's not active, then it is FAILED or COMPLETE, or we would have already thrown if the status was invalid.
            if ($pushFileRestoreStatus->getStatus() !== AgentTransferState::ACTIVE()) {
                $isComplete = true;
                break;
            }

            // Check if the restore is stuck in the ACTIVE state
            if ($pushFileRestoreStatus->getBytesTransferred() === $lastBytesTransferred &&
                $this->checkForHungTransfer($pushFileRestoreStatus, $lastProgressTime)) {
                $this->context->getAgentApi()->cancelPushFileRestore($pushFileRestoreStatus->getRestoreID());
                throw new Exception("The push file restore job timed out and was cancelled.");
            }
            
            $lastProgressTime = $this->dateTimeService->getTime();
            $lastBytesTransferred = $pushFileRestoreStatus->getBytesTransferred();

            $this->sleep->sleep(2);
        }

        // If there was an error, handle it
        if ($pushFileRestoreStatus->getStatus() === AgentTransferState::FAILED()) {
            $this->logger->error(
                'TRS0003 Failed to transfer restore data to agent',
                [
                    'errorCode' => $pushFileRestoreStatus->getErrorCode(),
                    'errorCodeStr' => $pushFileRestoreStatus->getErrorCodeStr(),
                    'errorMsg' => $pushFileRestoreStatus->getErrorMsg()
                ]
            );
            throw new Exception($pushFileRestoreStatus->getErrorMsg());
        }
    }

    public function cleanup()
    {
        // Nothing
    }

    private function checkForHungTransfer(PushFileRestoreStatus $pushFileRestoreStatus, int $lastProgressTime): bool
    {
        $timeout = $this->context->getAgent()->getLocal()->getTimeout();
        $hasTimedOut = $this->getTimeSinceLastProgress($lastProgressTime) > $timeout;
        $transferActive = $pushFileRestoreStatus->getStatus() === AgentTransferState::ACTIVE();
        if ($hasTimedOut && $transferActive) {
            $this->logger->warning("TRS0004 Push file restore job stalled and was cancelled", ['timeoutSeconds' => $timeout]);
            return true;
        }

        return false;
    }

    private function getTimeSinceLastProgress(int $lastProgressTime): int
    {
        $currentTime = $this->dateTimeService->getTime();
        return $currentTime - $lastProgressTime;
    }
}
