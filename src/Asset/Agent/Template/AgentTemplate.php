<?php

namespace Datto\Asset\Agent\Template;

use Datto\Asset\Retention;
use Datto\Asset\VerificationSchedule;
use Datto\Core\Asset\Configuration\WeeklySchedule;

/**
 * Data class representing an agent template.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class AgentTemplate
{
    const REPLICATION_USE_INTERVAL = "interval";

    /** @var string */
    private $name;

    /** @var int|null */
    private $id;

    /** @var WeeklySchedule */
    private $localBackupSchedule;

    /** @var Retention */
    private $localRetention;

    /** @var int */
    private $backupInterval;

    /** @var int */
    private $snapshotTimeout;

    /** @var WeeklySchedule */
    private $offsiteBackupSchedule;

    /** @var string */
    private $offsitePriority;

    /** @var Retention */
    private $offsiteRetention;

    /** @var int */
    private $nightlyRetentionLimit;

    /** @var int */
    private $onDemandRetentionLimit;

    /** @var string */
    private $replicationSchedule;

    /** @var int|null Custom interval, null when schedule is not custom */
    private $replicationCustomInterval;

    /** @var bool */
    private $ransomwareCheckEnabled;

    /** @var bool */
    private $integrityCheckEnabled;

    /** @var VerificationSchedule */
    private $verificationSchedule;

    /** @var int */
    private $verificationDelay;

    /** @var int */
    private $verificationErrorTime;

    public function __construct(
        string $name,
        WeeklySchedule $localBackupSchedule,
        Retention $localRetention,
        int $backupInterval,
        int $snapshotTimeout,
        WeeklySchedule $offsiteBackupSchedule,
        string $offsitePriority,
        Retention $offsiteRetention,
        int $nightlyRetentionLimit,
        int $onDemandRetentionLimit,
        string $replicationSchedule,
        $replicationCustomInterval, // Can be an int or null, so no type hint
        bool $ransomwareCheckEnabled,
        bool $integrityCheckEnabled,
        VerificationSchedule $verificationSchedule,
        int $verificationDelay,
        int $verificationErrorTime,
        int $id = null
    ) {
        $this->name = $name;
        $this->localBackupSchedule = $localBackupSchedule;
        $this->localRetention = $localRetention;
        $this->backupInterval = $backupInterval;
        $this->snapshotTimeout = $snapshotTimeout;
        $this->offsiteBackupSchedule = $offsiteBackupSchedule;
        $this->offsitePriority = $offsitePriority;
        $this->offsiteRetention = $offsiteRetention;
        $this->nightlyRetentionLimit = $nightlyRetentionLimit;
        $this->onDemandRetentionLimit = $onDemandRetentionLimit;
        $this->replicationSchedule = $replicationSchedule;
        $this->replicationCustomInterval = $replicationCustomInterval;
        $this->ransomwareCheckEnabled = $ransomwareCheckEnabled;
        $this->integrityCheckEnabled = $integrityCheckEnabled;
        $this->verificationSchedule = $verificationSchedule;
        $this->verificationDelay = $verificationDelay;
        $this->verificationErrorTime = $verificationErrorTime;
        $this->id = $id;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return int|null
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return WeeklySchedule
     */
    public function getLocalBackupSchedule(): WeeklySchedule
    {
        return $this->localBackupSchedule;
    }

    /**
     * @return Retention
     */
    public function getLocalRetention(): Retention
    {
        return $this->localRetention;
    }

    /**
     * @return int
     */
    public function getBackupInterval(): int
    {
        return $this->backupInterval;
    }

    /**
     * @return int
     */
    public function getSnapshotTimeout(): int
    {
        return $this->snapshotTimeout;
    }

    /**
     * @return WeeklySchedule
     */
    public function getOffsiteBackupSchedule(): WeeklySchedule
    {
        return $this->offsiteBackupSchedule;
    }

    /**
     * @return string
     */
    public function getOffsitePriority(): string
    {
        return $this->offsitePriority;
    }

    /**
     * @return Retention
     */
    public function getOffsiteRetention(): Retention
    {
        return $this->offsiteRetention;
    }

    /**
     * @return int
     */
    public function getNightlyRetentionLimit(): int
    {
        return $this->nightlyRetentionLimit;
    }

    /**
     * @return int
     */
    public function getOnDemandRetentionLimit(): int
    {
        return $this->onDemandRetentionLimit;
    }

    /**
     * @return string
     */
    public function getReplicationSchedule(): string
    {
        return $this->replicationSchedule;
    }

    /**
     * @return int|null
     */
    public function getReplicationCustomInterval()
    {
        return $this->replicationCustomInterval;
    }

    /**
     * @return bool
     */
    public function isRansomwareCheckEnabled(): bool
    {
        return $this->ransomwareCheckEnabled;
    }

    /**
     * @return bool
     */
    public function isIntegrityCheckEnabled(): bool
    {
        return $this->integrityCheckEnabled;
    }

    /**
     * @return VerificationSchedule
     */
    public function getVerificationSchedule(): VerificationSchedule
    {
        return $this->verificationSchedule;
    }

    /**
     * @return int
     */
    public function getVerificationDelay(): int
    {
        return $this->verificationDelay;
    }

    /**
     * @return int
     */
    public function getVerificationErrorTime(): int
    {
        return $this->verificationErrorTime;
    }
}
