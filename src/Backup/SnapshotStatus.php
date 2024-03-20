<?php

namespace Datto\Backup;

use Datto\Config\JsonConfigRecord;
use Datto\Util\StringUtil;

/**
 * Object for tracking backup queue/scheduler run snapshot progress
 */
class SnapshotStatus extends JsonConfigRecord
{
    const SNAPSHOT_STATUS_KEY = 'snapshotStatus';

    /** @var string */
    private $state;

    /** @var string */
    private $backupId;

    /** @var int */
    private $startTime;

    /** @var int */
    private $endTime;

    /** @var int */
    private $snapshotEpoch;

    /**
     * @param string $state
     * @param string $backupId
     * @param int $startTime
     * @param int $endTime
     * @param int $snapshotEpoch
     */
    public function __construct(
        string $state,
        string $backupId = null,
        int $startTime = null,
        int $endTime = null,
        int $snapshotEpoch = null
    ) {
        $this->state = $state;
        $this->backupId = $backupId ?? StringUtil::generateGuid();
        $this->startTime = $startTime ?? time();
        $this->endTime = $endTime;
        $this->snapshotEpoch = $snapshotEpoch;
    }

    public function getKeyName(): string
    {
        return self::SNAPSHOT_STATUS_KEY;
    }

    /**
     * @return string
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * @param string $state
     */
    public function setState(string $state)
    {
        $this->state = $state;
    }

    /**
     * @return string
     */
    public function getBackupId(): string
    {
        return $this->backupId;
    }

    /**
     * @param string $backupId
     */
    public function setBackupId(string $backupId)
    {
        $this->backupId = $backupId;
    }

    /**
     * @return int
     */
    public function getStartTime(): int
    {
        return $this->startTime;
    }

    /**
     * @param int $startTime
     */
    public function setStartTime(int $startTime)
    {
        $this->startTime = $startTime;
    }

    /**
     * @return int|null
     */
    public function getEndTime()
    {
        return $this->endTime;
    }

    /**
     * @param int|null $endTime
     */
    public function setEndTime($endTime)
    {
        $this->endTime = $endTime;
    }

    /**
     * @return int|null
     */
    public function getSnapshotEpoch()
    {
        return $this->snapshotEpoch;
    }

    /**
     * @param int|null $snapshotEpoch
     */
    public function setSnapshotEpoch($snapshotEpoch)
    {
        $this->snapshotEpoch = $snapshotEpoch;
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            'state' => $this->getState(),
            'backupId' => $this->getBackupId(),
            'startTime' => $this->getStartTime(),
            'endTime' => $this->getEndTime(),
            'snapshotEpoch' => $this->getSnapshotEpoch()
        ];
    }

    /**
     * @inheritdoc
     */
    protected function load(array $vals)
    {
        $this->setState($vals['state']);
        $this->setBackupId($vals['backupId'] ?? StringUtil::generateGuid());
        $this->setStartTime($vals['startTime'] ?? time());
        $this->setEndTime($vals['endTime'] ?? null);
        $this->setSnapshotEpoch($vals['snapshotEpoch'] ?? null);
    }
}
