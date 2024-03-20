<?php

namespace Datto\Verification;

/**
 * Object model for the verification queue information
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class VerificationAsset
{
    /** @var string Name of the asset to be verified */
    private $assetName;

    /** @var int Snapshot epoch time */
    private $snapshotTime;

    /** @var int Epoch time of when the verification asset was queued */
    private $queuedTime;

    /** @var int Epoch time of when the asset's most recent screenshot was taken */
    private $mostRecentVerificationEpoch;

    /**
     * Create Instance of VerificationAsset.
     *
     * @param string $assetName Name of the asset to be verified
     * @param int $snapshotTime Snapshot epoch time
     * @param int|null $queuedTime Epoch time of when the verification asset was queued
     * @param int|null $mostRecentVerificationEpoch Epoch time of when the asset's most recent screenshot was taken
     */
    public function __construct(
        $assetName,
        $snapshotTime,
        $queuedTime = null,
        $mostRecentVerificationEpoch = null
    ) {
        $this->assetName = $assetName;
        $this->snapshotTime = $snapshotTime;
        $this->queuedTime = $queuedTime;
        $this->mostRecentVerificationEpoch = $mostRecentVerificationEpoch;
    }

    /**
     * @return string Name of the asset to be verified
     */
    public function getAssetName()
    {
        return $this->assetName;
    }

    /**
     * @return int Snapshot epoch time
     */
    public function getSnapshotTime()
    {
        return $this->snapshotTime;
    }

    /**
     * @return int Epoch time of when the verification asset was queued
     */
    public function getQueuedTime()
    {
        return $this->queuedTime;
    }

    /**
     * @param int $queuedTime Epoch time of when the verification asset was queued
     */
    public function setQueuedTime($queuedTime)
    {
        $this->queuedTime = $queuedTime;
    }

    /**
     * @return int Epoch time of when the asset's most recent screenshot was taken
     */
    public function getMostRecentVerificationEpoch()
    {
        return $this->mostRecentVerificationEpoch;
    }

    /**
     * @param int $mostRecentScreenshot Epoch time of when the asset's most recent screenshot was taken
     */
    public function setMostRecentVerificationEpoch($mostRecentVerificationEpoch)
    {
        $this->mostRecentVerificationEpoch = $mostRecentVerificationEpoch;
    }

    /**
     * Determine the given verification asset represents the same as self.
     *
     * @param VerificationAsset $other Verification asset to be checked against
     * @return bool True if they represent the same verification asset
     */
    public function isEqual(VerificationAsset $other)
    {
        return $this->getAssetName() == $other->getAssetName() &&
            $this->getSnapshotTime() == $other->getSnapshotTime();
    }
}
