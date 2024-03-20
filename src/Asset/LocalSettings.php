<?php

namespace Datto\Asset;

use Datto\Asset\RecoveryPoint\RecoveryPoints;
use Datto\Core\Asset\Configuration\WeeklySchedule;
use RuntimeException;

/**
 * Asset settings related to taking and storing snapshots on the device.
 *
 * Developer note:
 *   Be sure to make all properties injectable through the constructor, so that the
 *   state of the object can be recreated from a config file. Do NOT provide public
 *   setters for properties that could set the object into an inconsistent state,
 *   e.g. don't provide a setEnabled() method.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class LocalSettings
{
    const DEFAULT_INTERVAL = 60;
    const DEFAULT_TIMEOUT = 900;
    const DEFAULT_PAUSE = false;
    const DEFAULT_ARCHIVED = false;
    const DEFAULT_RANSOMWARE_ENABLED = true;
    const DEFAULT_RANSOMWARE_SUSPENSION_END_TIME = 0;
    const DEFAULT_INTEGRITY_CHECK_ENABLED = true;
    const DEFAULT_PAUSE_UNTIL = null;
    const DEFAULT_PAUSE_WHILE_METERED = false;
    const DEFAULT_MAX_BANDWIDTH = null;
    const DEFAULT_MAX_THROTTLED_BANDWIDTH = null;
    const DEFAULT_LAST_CHECKIN = null;
    const DEFAULT_MIGRATING = false;

    /** @var string Asset Key */
    private $assetKey;

    /** @var bool Whether backups are paused */
    private $paused;

    /** @var bool The value of paused that this class was constructed with */
    private $originalPaused;

    /** @var int|null timestamp to pause backups until */
    private $pauseUntil;

    /** @var bool Whether the DTC asset should pause backups when it's on a metered network connection */
    private $pauseWhileMetered;

    /** @var bool Whether asset is archived */
    private $archived;

    /** @var int Interval between taking snapshots, in minutes */
    private $interval;

    /** @var int Amount of time to spend taking a snapshot, in seconds */
    private $timeout;

    /** @var bool Whether ransomware checks are enabled */
    private $ransomwareCheckEnabled;

    /** @var int The epoch time (in seconds) at which the asset's ransomware reporting suspension end(s|ed) */
    private $ransomwareSuspensionEndTime;

    /** @var bool Whether integrity checks are enabled or not */
    private $integrityCheckEnabled;

    /** @var WeeklySchedule The snapshot schedule; snapshots are taken in the selected hours */
    private $schedule;

    /** @var Retention Durations for retaining local snapshots before deletion, in hours */
    private $retention;

    /** @var RecoveryPoints List of local recovery points */
    private $recoveryPoints;

    /** @var int|null Last time the DTC agent checked into this device. */
    private $lastCheckin;

    /** @var int|null The max bandwidth the DTC agent can use to backup */
    private $maximumBandwidthInBits;

    /** @var int|null The max throttled bandwidth the DTC agent can use to backup */
    private $maximumThrottledBandwidthInBits;

    /** @var bool Whether the asset's migration has been expedited */
    private $expediteMigration;

    /** @var bool Whether or not the asset is migrating */
    private $migrationInProgress;

    /**
     * @param string $assetKey Asset Key
     * @param bool $paused Whether backups are paused
     * @param int $interval Interval between taking snapshots, in minutes
     * @param int $timeout Amount of time to spend taking a snapshot, in seconds
     * @param bool $ransomwareCheckEnabled Whether ransomware checks are enabled,
     * @param int $ransomwareSuspensionEndTime The epoch time (in seconds) at which the asset's ransomware reporting
     * suspension end(s|ed)
     * @param WeeklySchedule|null $schedule The snapshot schedule; snapshots are taken in the selected hours
     * @param Retention|null $retention Durations for retaining local snapshots before deletion, in hours
     * @param RecoveryPoints $recoveryPoints List of local recovery points
     * @param bool $archived Whether asset is archived
     * @param bool $integrityCheckEnabled
     * @param int $pauseUntil timestamp to pause backups until, only set is $paused is true
     * @param bool $pauseWhileMetered
     * @param int|null $lastCheckin
     * @param int|null $maximumBandwidthInBits
     * @param int|null $maximumThrottledBandwidthInBits
     * @param bool $expediteMigration
     */
    public function __construct(
        $assetKey,
        $paused = self::DEFAULT_PAUSE,
        $interval = self::DEFAULT_INTERVAL,
        $timeout = self::DEFAULT_TIMEOUT,
        $ransomwareCheckEnabled = self::DEFAULT_RANSOMWARE_ENABLED,
        $ransomwareSuspensionEndTime = self::DEFAULT_RANSOMWARE_SUSPENSION_END_TIME,
        WeeklySchedule $schedule = null,
        Retention $retention = null,
        RecoveryPoints $recoveryPoints = null,
        bool $archived = self::DEFAULT_ARCHIVED,
        bool $integrityCheckEnabled = self::DEFAULT_INTEGRITY_CHECK_ENABLED,
        ?int $pauseUntil = self::DEFAULT_PAUSE_UNTIL,
        ?bool $pauseWhileMetered = null,
        ?int $lastCheckin = null,
        ?int $maximumBandwidthInBits = self::DEFAULT_MAX_BANDWIDTH,
        ?int $maximumThrottledBandwidthInBits = self::DEFAULT_MAX_THROTTLED_BANDWIDTH,
        bool $expediteMigration = self::DEFAULT_MIGRATING,
        bool $migrationInProgress = false
    ) {
        $this->assetKey = $assetKey;
        $this->paused = $paused;
        $this->originalPaused = $paused;
        $this->interval = $interval;
        $this->timeout = $timeout;
        $this->ransomwareCheckEnabled = $ransomwareCheckEnabled;
        $this->ransomwareSuspensionEndTime = $ransomwareSuspensionEndTime;
        $this->schedule = ($schedule) ?: new WeeklySchedule();
        $this->retention = ($retention) ?: Retention::createDefault();
        $this->recoveryPoints = ($recoveryPoints) ?: new RecoveryPoints();
        $this->archived = $archived;
        $this->integrityCheckEnabled = $integrityCheckEnabled;
        $this->pauseUntil = $paused ? $pauseUntil : self::DEFAULT_PAUSE_UNTIL;
        $this->pauseWhileMetered = $pauseWhileMetered ?? self::DEFAULT_PAUSE_WHILE_METERED;
        $this->lastCheckin = $lastCheckin ?? self::DEFAULT_LAST_CHECKIN;
        $this->maximumBandwidthInBits = $maximumBandwidthInBits ?? self::DEFAULT_MAX_BANDWIDTH;
        $this->maximumThrottledBandwidthInBits = $maximumThrottledBandwidthInBits ?? self::DEFAULT_MAX_THROTTLED_BANDWIDTH;
        $this->expediteMigration = $expediteMigration;
        $this->migrationInProgress = $migrationInProgress;
    }

    /**
     * @return boolean
     */
    public function isPaused()
    {
        return $this->paused;
    }

    /**
     * @param boolean $paused
     */
    public function setPaused($paused): void
    {
        if ($paused && $this->archived) {
            throw new RuntimeException('Pausing of archived agents is not allowed');
        }

        $this->paused = $paused;

        if (!$this->paused) {
            $this->pauseUntil = self::DEFAULT_PAUSE_UNTIL;
        }
    }

    /**
     * @return bool Whether the paused value has changed since the object was constructed
     */
    public function hasPausedChanged(): bool
    {
        return $this->paused !== $this->originalPaused;
    }

    /**
     * @return int|null
     */
    public function getPauseUntil()
    {
        return $this->pauseUntil;
    }

    /**
     * @param int|null $pauseUntil
     */
    public function setPauseUntil(int $pauseUntil = null): void
    {
        if ($this->isPaused()) {
            $this->pauseUntil = $pauseUntil;
        }
    }

    /**
     * FIXME this field does not apply to both agents and shares so it does not belong in LocalSettings
     * @return bool
     */
    public function isPauseWhileMetered(): bool
    {
        return $this->pauseWhileMetered;
    }

    /**
     * @param bool $pauseWhileMetered
     */
    public function setPauseWhileMetered(bool $pauseWhileMetered): void
    {
        $this->pauseWhileMetered = $pauseWhileMetered;

        if ($pauseWhileMetered && $this->archived) {
            throw new RuntimeException('Pausing of archived agents is not allowed');
        }
    }

    /**
     * FIXME this field does not apply to both agents and shares so it does not belong in LocalSettings
     * @return int|null
     */
    public function getMaximumBandwidthInBits()
    {
        return $this->maximumBandwidthInBits;
    }

    /**
     * @param int|null $bandwidthInBits
     */
    public function setMaximumBandwidth(int $bandwidthInBits = null): void
    {
        $this->maximumBandwidthInBits = $bandwidthInBits;
    }

    /**
     * FIXME this field does not apply to both agents and shares so it does not belong in LocalSettings
     * @return int|null
     */
    public function getMaximumThrottledBandwidthInBits()
    {
        return $this->maximumThrottledBandwidthInBits;
    }

    /**
     * @param int|null $bandwidthInBits
     */
    public function setMaximumThrottledBandwidth(int $bandwidthInBits = null): void
    {
        $this->maximumThrottledBandwidthInBits = $bandwidthInBits;
    }

    /**
     * FIXME this field does not apply to both agents and shares so it does not belong in LocalSettings
     * @return bool
     */
    public function isMigrationExpedited(): bool
    {
        return $this->expediteMigration;
    }

    /**
     * @param bool $expedited
     */
    public function setMigrationExpedited(bool $expedited): void
    {
        $this->expediteMigration = $expedited;
    }

    /**
     * @return bool Whether or not the asset is migrating
     */
    public function isMigrationInProgress(): bool
    {
        return $this->migrationInProgress;
    }

    /**
     * @param bool $migrationInProgress
     */
    public function setMigrationInProgress(bool $migrationInProgress): void
    {
        $this->migrationInProgress = $migrationInProgress;
    }

    /**
     * FIXME this field does not apply to both agents and shares so it does not belong in LocalSettings
     * @return bool
     */
    public function isArchived(): bool
    {
        return $this->archived;
    }

    /**
     * @param bool $archived
     */
    public function setArchived(bool $archived): void
    {
        $this->archived = $archived;
        $this->paused = self::DEFAULT_PAUSE;
        $this->pauseWhileMetered = self::DEFAULT_PAUSE_WHILE_METERED;
        $this->pauseUntil = self::DEFAULT_PAUSE_UNTIL;
    }

    /**
     * @return int
     */
    public function getInterval()
    {
        return $this->interval;
    }

    /**
     * @param int $interval
     */
    public function setInterval($interval): void
    {
        $this->interval = $interval;
    }

    /**
     * @return int
     */
    public function getTimeout()
    {
        return $this->timeout;
    }

    /**
     * @param int $timeout
     */
    public function setTimeout($timeout): void
    {
        $this->timeout = $timeout;
    }

    /**
     * FIXME this field does not apply to both agents and shares so it does not belong in LocalSettings
     * @return boolean
     */
    public function isRansomwareCheckEnabled()
    {
        return $this->ransomwareCheckEnabled;
    }

    /**
     * Enable Ransomware Check
     */
    public function enableRansomwareCheck(): void
    {
        $this->ransomwareCheckEnabled = true;
    }

    /**
     * Disable Ransomware Check
     */
    public function disableRansomwareCheck(): void
    {
        $this->ransomwareCheckEnabled = false;
    }

    /**
     * FIXME this field does not apply to both agents and shares so it does not belong in LocalSettings
     * @return bool
     */
    public function isIntegrityCheckEnabled(): bool
    {
        return $this->integrityCheckEnabled;
    }

    /**
     * @param bool $integrityCheckEnabled
     */
    public function setIntegrityCheckEnabled(bool $integrityCheckEnabled): void
    {
        $this->integrityCheckEnabled = $integrityCheckEnabled;
    }

    /**
     * Suspend an agent's ransomware reporting until the provided end time.
     *
     * @param int $epochTime The epoch time (in seconds) at which the ransomware reporting suspension is to end.
     */
    public function setRansomwareSuspensionEndTime($epochTime): void
    {
        $this->ransomwareSuspensionEndTime = $epochTime;
    }

    /**
     * FIXME this field does not apply to both agents and shares so it does not belong in LocalSettings
     * @return int The epoch time (in seconds) at which the ransomware reporting suspension
     * for this agent is set to end.
     */
    public function getRansomwareSuspensionEndTime()
    {
        return $this->ransomwareSuspensionEndTime;
    }

    /**
     * @return WeeklySchedule
     */
    public function getSchedule()
    {
        return $this->schedule;
    }

    /**
     * @param WeeklySchedule $schedule
     */
    public function setSchedule(WeeklySchedule $schedule): void
    {
        $this->schedule = $schedule;
    }

    /**
     * @return Retention
     */
    public function getRetention()
    {
        return $this->retention;
    }

    /**
     * @param Retention $retention
     */
    public function setRetention(Retention $retention): void
    {
        $this->retention = $retention;
    }

    /**
     * @return RecoveryPoints
     */
    public function getRecoveryPoints()
    {
        return $this->recoveryPoints;
    }

    /**
     * @param RecoveryPoints $recoveryPoints
     */
    public function setRecoveryPoints(RecoveryPoints $recoveryPoints): void
    {
        $this->recoveryPoints = $recoveryPoints;
    }

    /**
     * FIXME this field does not apply to both agents and shares so it does not belong in LocalSettings
     * @return int|null
     */
    public function getLastCheckin()
    {
        return $this->lastCheckin;
    }

    /**
     * @param int $lastCheckin
     */
    public function setLastCheckin(int $lastCheckin): void
    {
        $this->lastCheckin = $lastCheckin;
    }

    /**
     * @param LocalSettings $from Another asset's local settings object, to be copied
     */
    public function copyFrom(LocalSettings $from): void
    {
        $this->setPaused(false);// pause is a special case - we don't want to copy an asset into paused state
        $this->setArchived(false);
        $this->setSchedule($from->getSchedule());
        $this->setInterval($from->getInterval());
        $this->setTimeout($from->getTimeout());
        if ($from->isRansomwareCheckEnabled()) {
            $this->enableRansomwareCheck();
        } else {
            $this->disableRansomwareCheck();
        }
        $this->setIntegrityCheckEnabled($from->isIntegrityCheckEnabled());
        $this->setRetention($from->getRetention());
    }
}
