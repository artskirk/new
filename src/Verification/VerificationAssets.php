<?php

namespace Datto\Verification;

use Datto\Log\DeviceLogger;
use Datto\Log\LoggerAwareTrait;
use Datto\Resource\DateTimeService;
use Psr\Log\LoggerAwareInterface;

/**
 * This class manages the list of verification assets.
 *
 *  @author Jeffrey Knapp <jknapp@datto.com>
 */
class VerificationAssets implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const SECONDS_IN_TWO_DAYS = 172800; // 24 * 60 * 60 * 2
    const MAX_QUEUE_SIZE = 25;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var VerificationAsset[] Verification assets in the queue */
    private array $verificationAssets = [];

    public function __construct(
        DateTimeService $dateTimeService
    ) {
        $this->dateTimeService = $dateTimeService;
    }

    /**
     * @param VerificationAsset $verificationAsset
     * @return bool if asset exists in the array
     */
    public function exists(VerificationAsset $verificationAsset): bool
    {
        foreach ($this->verificationAssets as $asset) {
            if ($asset->isEqual($verificationAsset)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Add a verification asset to the end of the list if the queued time was less than 2 days ago.
     *
     * @param VerificationAsset $verificationAsset Verification item to be added
     */
    public function add(VerificationAsset $verificationAsset): void
    {
        $queuedTime = $verificationAsset->getQueuedTime();
        if (!isset($queuedTime)) {
            $verificationAsset->setQueuedTime($this->dateTimeService->getTime());
        }

        // Make sure the queued time is less than 2 days old.
        // This prevents old queued verification assets from re-entering the queue.
        if (!$this->isOlderThanTwoDays($verificationAsset)) {
            $this->verificationAssets[] = $verificationAsset;
        }
    }

    /**
     * Remove a verification asset from the list.
     *
     * @param VerificationAsset $verificationAsset Verification asset to be removed
     */
    public function remove(VerificationAsset $verificationAsset)
    {
        $assetsToKeep = array();

        foreach ($this->verificationAssets as $assetToCheck) {
            if (!$verificationAsset->isEqual($assetToCheck)) {
                $assetsToKeep[] = $assetToCheck;
            }
        }

        $this->verificationAssets = $assetsToKeep;
    }

    /**
     * Remove all of the verification assets from the list.
     */
    public function removeAll()
    {
        $this->verificationAssets = [];
    }

    /**
     * Get the list of verification assets
     *
     * @return VerificationAsset[] Verification assets in the queue
     */
    public function get(): array
    {
        return $this->verificationAssets;
    }

    /**
     * Get the next verification asset at the top of the list
     *
     * @return VerificationAsset|null Verification asset at the top of the list
     */
    public function getNext(): ?VerificationAsset
    {
        $verificationAsset = count($this->verificationAssets) > 0 ? $this->verificationAssets[0] : null;
        return $verificationAsset;
    }

    /**
     * Get the number of verification assets in the list.
     *
     * @return int Number of verification assets
     */
    public function getCount(): int
    {
        return count($this->verificationAssets);
    }

    /**
     * Process the list against the queue's business logic.
     * - Remove assets which are more than two days old
     * - Limit the max size of the queue
     *
     * @return True if the verification queue was modified, False otherwise
     */
    public function processList()
    {
        $modified = false;

        // Remove assets which are more than two days old.
        foreach ($this->verificationAssets as $verificationAsset) {
            if ($this->isOlderThanTwoDays($verificationAsset)) {
                $this->remove($verificationAsset);
                $modified = true;
            }
        }

        // Prioritize queue based on time between snapshot and most recent screenshot
        // Assets that have a longer time between snapshot and most recent verification
        // will be given priority
        usort($this->verificationAssets, function (VerificationAsset $assetA, VerificationAsset $assetB): int {
            $timeElapsedA = $assetA->getSnapshotTime() - $assetA->getMostRecentVerificationEpoch();
            $timeElapsedB = $assetB->getSnapshotTime() - $assetB->getMostRecentVerificationEpoch();

            // sort in descending order
            return $timeElapsedB <=> $timeElapsedA;
        });

        // Limit the queue size. We process the queue first in, first out.
        // This is important because the first items in the list are the ones with the
        // most time between snapshot and most recent screenshot
        if (count($this->verificationAssets) > static::MAX_QUEUE_SIZE) {
            // ensure that assets removed are logged
            $removedVerifications = array_splice($this->verificationAssets, static::MAX_QUEUE_SIZE);
            foreach ($removedVerifications as $removedVerification) {
                $this->logger->setAssetContext($removedVerification->getAssetName());
                $this->logger->info(
                    "VQU0007 Queued screenshot exceeds maximum queue size, removing verification from queue",
                    [
                        'agent' => $removedVerification->getAssetName(),
                        'queueTime' => $removedVerification->getQueuedTime(),
                        'snapshotTime' => $removedVerification->getSnapshotTime()
                    ]
                );
            }
            // remove asset context from logger to avoid API logging in UI
            $this->logger->removeFromGlobalContext(DeviceLogger::CONTEXT_ASSET);
            $modified = true;
        }

        return $modified;
    }

    /**
     * Determines whether or not the given asset is more than two days old.
     *
     * @param VerificationAsset $verificationAsset Verification Asset to be checked
     * @return True if the asset is more than two days old, False otherwise
     */
    private function isOlderThanTwoDays(VerificationAsset $verificationAsset)
    {
        return $this->dateTimeService->getTime() > $verificationAsset->getQueuedTime() + self::SECONDS_IN_TWO_DAYS;
    }
}
