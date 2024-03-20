<?php

namespace Datto\Backup;

use Datto\Backup\SnapshotStatus;
use Datto\Config\AgentStateFactory;
use Datto\Resource\DateTimeService;

/**
 * Class for managing tracking of backup queue/scheduler run snapshot progress
 */
class SnapshotStatusService
{
    const STATE_SNAPSHOT_QUEUED = 'QUEUED';
    const STATE_SNAPSHOT_QUEUE_FAILED = 'QUEUE_FAILED';
    const STATE_SNAPSHOT_STARTED = 'STARTED';
    const STATE_SNAPSHOT_COMPLETE = 'COMPLETE';
    const STATE_SNAPSHOT_FAILED = 'FAILED';
    const STATE_SNAPSHOT_NO_STATUS = 'NO_STATUS';

    /** @var AgentStateFactory */
    private $agentStateFactory;

    /** @var DateTimeService */
    private $dateTimeService;

    /**
     * @param AgentStateFactory $agentStateFactory
     * @param DateTimeService $dateTimeService
     */
    public function __construct(
        AgentStateFactory $agentStateFactory,
        DateTimeService $dateTimeService
    ) {
        $this->agentStateFactory = $agentStateFactory;
        $this->dateTimeService = $dateTimeService;
    }

    /**
     * Updates and persists SnapshotStatus object to disk given a valid state
     *
     * @param string $state
     * @param int $snapshotEpoch
     *
     * @return SnapshotStatus
     */
    public function updateSnapshotStatus(string $assetKeyName, string $state, int $snapshotEpoch = null): SnapshotStatus
    {
        $snapshotStatus = new SnapshotStatus($state);

        switch ($snapshotStatus->getState()) {
            case self::STATE_SNAPSHOT_QUEUED:
                $this->saveSnapshotStatus($assetKeyName, $snapshotStatus);
                break;
            case self::STATE_SNAPSHOT_STARTED:
                $currentSnapshotStatus = $this->getSnapshotStatus($assetKeyName);
                if ($currentSnapshotStatus->getState() !== self::STATE_SNAPSHOT_NO_STATUS) {
                    $currentSnapshotStatus->setState($state);

                    $snapshotStatus = $currentSnapshotStatus;
                }

                $this->saveSnapshotStatus($assetKeyName, $snapshotStatus);
                break;
            case self::STATE_SNAPSHOT_QUEUE_FAILED:
            case self::STATE_SNAPSHOT_COMPLETE:
            case self::STATE_SNAPSHOT_FAILED:
                $currentSnapshotStatus = $this->getSnapshotStatus($assetKeyName);
                if ($currentSnapshotStatus->getState() !== self::STATE_SNAPSHOT_NO_STATUS) {
                    $snapshotStatus = $currentSnapshotStatus;
                }

                $snapshotStatus->setState($state);
                $snapshotStatus->setEndTime($this->dateTimeService->getTime());
                $snapshotStatus->setSnapshotEpoch($snapshotEpoch);

                $this->saveSnapshotStatus($assetKeyName, $snapshotStatus);
                break;
        }

        return $snapshotStatus;
    }

    /**
     * Get SnapshotStatus if exists on disk, otherwise return null
     *
     * @param string $assetKeyName
     *
     * @return SnapshotStatus
     */
    public function getSnapshotStatus(string $assetKeyName)
    {
        $agentState = $this->agentStateFactory->create($assetKeyName);
        $snapshotStatus = new SnapshotStatus(self::STATE_SNAPSHOT_NO_STATUS);
        $agentState->loadRecord($snapshotStatus);

        return $snapshotStatus;
    }

    /**
     * Clear out SnapshotStatus from disk if exists
     *
     * @param string $assetKeyName
     */
    public function clearSnapshotStatus(string $assetKeyName)
    {
        $agentState = $this->agentStateFactory->create($assetKeyName);
        $agentState->clear(SnapshotStatus::SNAPSHOT_STATUS_KEY);
    }

    /**
     * Save SnapshotStatus to disk
     *
     * @param string $assetKeyName
     * @param SnapshotStatus $status
     */
    private function saveSnapshotStatus(string $assetKeyName, SnapshotStatus $status)
    {
        $agentState = $this->agentStateFactory->create($assetKeyName);
        $agentState->saveRecord($status);
    }
}
