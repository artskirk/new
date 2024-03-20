<?php

namespace Datto\Verification\Stages;

use Datto\Asset\AssetRemovalService;
use Datto\Asset\AssetRemovalStatus;
use Datto\Common\Resource\PosixHelper;
use Datto\System\Transaction\TransactionException;
use Datto\Resource\DateTimeService;
use Datto\Verification\InProgressVerification;
use Datto\Verification\InProgressVerificationRepository;
use Datto\Verification\VerificationResultType;
use Throwable;

/**
 * Create lock file to prevent other processes from running verification at the same time.
 *
 * Logs messages with the VER prefix in the range 0800-0899.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 * @author Matt Coleman <mcoleman@datto.com>
 * @author Matt Cheman <mcheman@datto.com>
 * @author Daniel Richardson <drichardson@datto.com>
 */
class VerificationLock extends VerificationStage
{
    const LOCK_FILE = '/datto/config/screenshot.inProgress';

    /** @var DateTimeService */
    private $dateService;

    /** @var InProgressVerificationRepository */
    private $inProgressVerificationRepository;

    /** @var PosixHelper */
    private $posixHelper;

    private AssetRemovalService $assetRemovalService;

    public function __construct(
        PosixHelper $posixHelper,
        DateTimeService $dateService,
        InProgressVerificationRepository $inProgressVerificationRepository,
        AssetRemovalService $assetRemovalService
    ) {
        $this->dateService = $dateService;
        $this->posixHelper = $posixHelper;
        $this->inProgressVerificationRepository = $inProgressVerificationRepository;
        $this->assetRemovalService = $assetRemovalService;
    }

    public function commit()
    {
        try {
            $assetKey = $this->context->getAgent()->getKeyName();
            $snapshot = $this->context->getSnapshotEpoch();
            $startedAt = $this->dateService->getTime();
            $delay = $this->context->getScreenshotWaitTime();
            $pid = $this->posixHelper->getCurrentProcessId();
            $timeout = $this->context->getReadyTimeout();

            $inProgress = new InProgressVerification(
                $assetKey,
                $snapshot,
                $startedAt,
                $delay,
                $pid,
                $timeout
            );

            try {
                $this->inProgressVerificationRepository->save($inProgress);

                // This checks if an agent is in the process of being removed, and if so, aborts the verification
                // Once the lock file is in place, the VerificationCleanupManager will use that info to clean up
                // the verification. Checking for AssetRemovalService::REMOVING_KEY after the lock ensures that:
                // The DestroyAgentService will tear the verification down if it started before the removal began.
                // The verification will tear itself down if started after the removal began.

                $removalState = $this->assetRemovalService->getAssetRemovalStatus($assetKey)->getState();
                if (!in_array($removalState, [AssetRemovalStatus::STATE_NONE, AssetRemovalStatus::STATE_ERROR])) {
                    $message = "Verification lock aborted, agent is being removed.";
                    $this->logger->info("VER0812 $message");
                    throw new TransactionException('Verification lock failed. Error message: ' . $message);
                }

                $this->logger->info('VER0810 VM screenshot started', ['snapshot' => $snapshot, 'delay' => $delay]);
                $this->setResult(VerificationResultType::SUCCESS());
            } catch (\Throwable $e) {
                $this->logger->error('VER0811 Failed to create lock file for screenshot', ['lockFile' => static::LOCK_FILE, 'exception' => $e]);
                $message = 'Failed to create lock file :' . static::LOCK_FILE;
                $this->setResult(VerificationResultType::FAILURE_INTERMITTENT(), $message);
                throw new TransactionException('Verification lock failed. Error message: ' . $message, null, $e);
            }
        } catch (TransactionException $e) {
            // This is to intercept TransactionException and leave the result unaltered.
            throw $e;
        } catch (Throwable $e) {
            $result = VerificationResultType::FAILURE_UNRECOVERABLE();
            $this->setResult($result, $e->getMessage(), $e->getTraceAsString());
            throw $e;
        }
    }

    public function cleanup()
    {
        $inProgress = $this->inProgressVerificationRepository->find();

        if ($inProgress) {
            $this->inProgressVerificationRepository->delete($inProgress);
        }
    }
}
