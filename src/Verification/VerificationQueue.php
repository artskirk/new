<?php

namespace Datto\Verification;

use Datto\Config\DeviceConfig;
use Datto\Log\LoggerAwareTrait;
use Datto\Utility\File\Lock;
use Datto\Common\Utility\Filesystem;
use Datto\Utility\File\LockFactory;
use Psr\Log\LoggerAwareInterface;
use Datto\Log\DeviceLoggerInterface;

/**
 * This class manages the verification queue and queue file persistence.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class VerificationQueue implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const VERIFICATION_QUEUE_FILE = 'screenshot.queue';
    const VERIFICATION_RETRY_FILE_TEMPLATE = '/dev/shm/%s-%s.scrotRetries';
    const VERIFICATION_LOCK_FILE = '/dev/shm/verification.lock';
    const MAX_RETRY_COUNT = 3;

    /** @var VerificationAssets List of verification assets */
    private $verificationAssets;

    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var Filesystem */
    private $filesystem;

    /** @var Lock */
    private $verificationLock;

    /**
     * Construct a VerificationQueue object.
     *
     * The parameter $verificationAssets should only be passed in for testing purposes. If it is passed in with
     * any verification assets in its list, they will be removed on the first call to any of this class's public
     * functions, as the screenshot.queue file is read and the list is rebuilt on every function call.
     *
     * The screenshot.queue file is read on every function call due to other parts of the system reading and writing
     * to the file.
     */
    public function __construct(
        DeviceConfig $deviceConfig,
        Filesystem $filesystem,
        VerificationAssets $verificationAssets,
        LockFactory $lockFactory
    ) {
        $this->deviceConfig = $deviceConfig;
        $this->filesystem = $filesystem;
        $this->verificationAssets = $verificationAssets;
        $this->verificationLock = $lockFactory->getProcessScopedLock(static::VERIFICATION_LOCK_FILE);
    }

    /**
     * Add a verification asset to the end of the list and update the queue file.
     * Doesn't add asset if it is already queued.
     *
     * @param VerificationAsset $verificationAsset Verification item to be added
     * @return bool : if the asset was successfully added to the queue
     */
    public function add(VerificationAsset $verificationAsset): bool
    {
        $this->verificationLock->exclusive();
        $addedToQueue = false;
        if ($this->hasAttemptsRemaining($verificationAsset)) {
            $this->logger->info(
                'VQU0000 Adding agent to queue.',
                ['assetName' => $verificationAsset->getAssetName(), 'snapshot' => $verificationAsset->getSnapshotTime()]
            );
            $this->readQueue();

            if ($this->verificationAssets->exists($verificationAsset)) {
                $this->logger->info(
                    'VQU0002 Agent is already queued.',
                    ['assetName' => $verificationAsset->getAssetName(), 'snapshot' => $verificationAsset->getSnapshotTime()]
                );
            } else {
                $this->verificationAssets->add($verificationAsset);
                $this->writeQueue();
                $addedToQueue = true;
            }
        } else {
            $this->logger->info(
                'VQU0004 This screenshot request has exhausted its permitted number of retries. Agent: ',
                ['assetName' => $verificationAsset->getAssetName(), 'snapshot' => $verificationAsset->getSnapshotTime()]
            );
        }
        $this->verificationLock->unlock();
        return $addedToQueue;
    }

    /**
     * Remove a verification asset from the list and update the queue file.
     *
     * @param VerificationAsset $verificationAsset Verification asset to be removed
     */
    public function remove(VerificationAsset $verificationAsset)
    {
        $this->logger->info(
            'VQU0001 Removing agent from queue.',
            ['assetName' => $verificationAsset->getAssetName(), 'snapshot' => $verificationAsset->getSnapshotTime()]
        );
        $this->verificationLock->exclusive();
        $this->readQueue();
        $this->verificationAssets->remove($verificationAsset);
        $this->writeQueue();
        $this->clearRetryCounter($verificationAsset);
        $this->verificationLock->unlock();
    }

    /**
     * Remove all verification assets from the list and update the queue file.
     */
    public function removeAll()
    {
        $this->logger->info('VQU0003 Removing all agents from queue.');

        $this->verificationLock->exclusive();
        $this->readQueue();
        $assets = $this->verificationAssets->get();
        foreach ($assets as $asset) {
            $this->clearRetryCounter($asset);
        }
        $this->verificationAssets->removeAll();
        $this->writeQueue();
        $this->verificationLock->unlock();
    }

    /**
     * Get all verification assets in the queue.
     *
     * @return VerificationAsset[] Verification assets in the queue
     */
    public function getQueue(): array
    {
        $this->readQueue();
        return $this->verificationAssets->get();
    }

    /**
     * Get the next verification asset in the queue.
     *
     * @return VerificationAsset|null Verification asset at the top of the list
     */
    public function getNext()
    {
        $this->readQueue();
        return $this->verificationAssets->getNext();
    }

    /**
     * Get the number of verification assets on the queue.
     *
     * @return int Number of verification assets on the queue.
     */
    public function getCount(): int
    {
        $this->readQueue();
        return $this->verificationAssets->getCount();
    }

    /**
     * After a screenshot attempt, this method will process the result.
     * If the screenshot was successful, or had an unrecoverable error it will be removed from the queue.
     * If the screenshot had an intermittent failure, it will be requeued if it has not exhausted its
     * number of retry attempts.
     *
     * @param VerificationAsset $verificationAsset
     * @param VerificationResultType $verificationResult
     * @param DeviceLoggerInterface $assetLogger
     * @return bool True if the screenshot should be retried, False otherwise
     */
    public function processVerificationResults(
        VerificationAsset $verificationAsset,
        VerificationResultType $verificationResult,
        DeviceLoggerInterface $assetLogger
    ): bool {
        if ($verificationResult === VerificationResultType::FAILURE_INTERMITTENT()) {
            $requeued = $this->requeue($verificationAsset);
            $message = $requeued ?
                'SCN0900 The screenshot VM failed to create/start, putting at the end of queue to retry.' :
                'SCN0902 The screenshot VM failed to create/start, removing from queue since retry limit was reached.';
            $assetLogger->info($message);
            return $requeued;
        } elseif ($verificationResult === VerificationResultType::FAILURE_UNRECOVERABLE()) {
            $assetLogger->info(
                'SCN0901 The screenshot VM failed to create/start in an unrecoverable way so no sense in requeuing.'
            );
            $this->remove($verificationAsset);
        } elseif ($verificationResult === VerificationResultType::SKIPPED()) {
            $assetLogger->info(
                'SCN0890 Screenshot process skipped for asset due to pending Windows update. Dequeuing...'
            );
            $this->remove($verificationAsset);
        } elseif ($verificationResult === VerificationResultType::SUCCESS()) {
            $assetLogger->info(
                'SCN0889 Screenshot process completed successfully. Dequeuing...'
            );
            $this->remove($verificationAsset);
        }

        return false;
    }

    /**
     * Get the number of screenshot attempts for the given VerificationAsset
     *
     * @param VerificationAsset $verificationAsset Verification asset to be checked.
     * @return int The number of attempts for the given VerificationAsset
     */
    public function getAttempts(VerificationAsset $verificationAsset): int
    {
        $counterPath = sprintf(
            static::VERIFICATION_RETRY_FILE_TEMPLATE,
            $verificationAsset->getAssetName(),
            $verificationAsset->getSnapshotTime()
        );

        $count = 0;
        if ($this->filesystem->exists($counterPath)) {
            $count = (int)$this->filesystem->fileGetContents($counterPath);
        }

        return $count;
    }

    /**
     * Increments the number of attempts by one and persists the count in a shared memory file.
     *
     * @param VerificationAsset $verificationAsset Verification asset to be checked.
     */
    public function incrementAttempts(VerificationAsset $verificationAsset)
    {
        $assetName = $verificationAsset->getAssetName();
        $snapshotTime = $verificationAsset->getSnapshotTime();

        $counterPath = sprintf(static::VERIFICATION_RETRY_FILE_TEMPLATE, $assetName, $snapshotTime);

        $count = $this->getAttempts($verificationAsset);
        $count += 1;

        $this->filesystem->filePutContents($counterPath, $count);
        $this->logger->info(
            'VQU0005 Incremented verification retry attempts.',
            ['assetName' => $assetName, 'snapshotTime' => $snapshotTime, 'count' => $count]
        );
    }

    /**
     * Clears the $verificationAsset retry counter
     *
     * @param VerificationAsset $verificationAsset
     */
    private function clearRetryCounter(VerificationAsset $verificationAsset)
    {
        $counterPath = sprintf(
            static::VERIFICATION_RETRY_FILE_TEMPLATE,
            $verificationAsset->getAssetName(),
            $verificationAsset->getSnapshotTime()
        );

        if ($this->filesystem->exists($counterPath)) {
            $this->filesystem->unlink($counterPath);
        }
    }

    /**
     * Read the verification assets from the queue file.
     * This will remove any VerificationAssets that are no longer valid and rewrite the queue.
     */
    private function readQueue()
    {
        $this->verificationAssets->removeAll();

        if ($this->deviceConfig->has(static::VERIFICATION_QUEUE_FILE)) {
            $queue = unserialize($this->deviceConfig->get(static::VERIFICATION_QUEUE_FILE), ['allowed_classes' => false]);
            $queue = is_array($queue) ? $queue : array();

            foreach ($queue as $item) {
                $info = explode(':', $item);
                if (count($info) >= 4) {
                    $snapshotEpoch = intval($info[0]);
                    $assetName = $info[1];
                    $queuedEpoch = intval($info[2]);
                    $mostRecentVerificationEpoch = intval($info[3]);

                    $verificationAsset = new VerificationAsset($assetName, $snapshotEpoch, $queuedEpoch, $mostRecentVerificationEpoch);

                    $this->verificationAssets->add($verificationAsset);
                }
            }

            if ($this->verificationAssets->processList()) {
                $this->writeQueue();
            }
        }
    }

    /**
     * Write the verification assets to the queue file.
     */
    private function writeQueue()
    {
        $verificationAssets = $this->verificationAssets->get();

        $queue = array();
        foreach ($verificationAssets as $verificationAsset) {
            $queueRecord = $verificationAsset->getSnapshotTime() . ':' .
                $verificationAsset->getAssetName() . ':' .
                $verificationAsset->getQueuedTime() . ':' .
                $verificationAsset->getmostRecentVerificationEpoch();

            $queue[] = $queueRecord;
        }

        $serializedQueue = serialize($queue);

        $this->deviceConfig->set(static::VERIFICATION_QUEUE_FILE, $serializedQueue);
    }

    /**
     * Re-queue the verification asset so that it is put on the bottom of the queue.
     * if it was not successfully added, then the asset has exhausted its attempt counter
     * and should be removed from the queue
     *
     * @param VerificationAsset $verificationAsset Verification asset to be requeued
     * @return bool True if the verification asset was requeued, false otherwise.
     */
    private function requeue(VerificationAsset $verificationAsset): bool
    {
        $this->verificationLock->exclusive();
        $this->readQueue();
        $this->verificationAssets->remove($verificationAsset);
        //Reset the queued time
        $verificationAsset->setQueuedTime(null);
        $this->writeQueue();
        $this->verificationLock->unlock();

        $this->logger->info(
            'VQU0006 Attempting to requeue agent to bottom of queue.',
            ['assetName' => $verificationAsset->getAssetName(), 'snapshot' => $verificationAsset->getSnapshotTime()]
        );

        if (!$this->add($verificationAsset)) {
            $this->remove($verificationAsset);
            return false;
        }
        return true;
    }

    /**
     * Check whether the verificationAsset has attempts remaining and can be run
     *
     * @param VerificationAsset $verificationAsset
     * @return bool True if the verification can be run, false if it has exceeded MAX_RETRY_COUNT attempts
     */
    private function hasAttemptsRemaining(VerificationAsset $verificationAsset): bool
    {
        $attempts = $this->getAttempts($verificationAsset);
        return $attempts <= static::MAX_RETRY_COUNT;
    }
}
