<?php
namespace Datto\Asset;

use Datto\Asset\RecoveryPoint\RecoveryPoints;
use Datto\Core\Asset\Configuration\WeeklySchedule;

/**
 * Asset settings related to sending snapshots offsite and storing
 * them in the Datto cloud.
 *
 * Developer note:
 *   Be sure to make all properties injectable through the constructor, so that the
 *   state of the object can be recreated from a config file. Do NOT provide public
 *   setters for properties that could set the object into an inconsistent state,
 *   e.g. don't provide a setEnabled() method.
 *
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class OffsiteSettings
{
    const LOW_PRIORITY = 'low';
    const MEDIUM_PRIORITY = 'normal';
    const HIGH_PRIORITY = 'high';
    const DEFAULT_PRIORITY = self::MEDIUM_PRIORITY;

    const DEFAULT_ON_DEMAND_RETENTION_LIMIT = 10;
    const DEFAULT_NIGHTLY_RETENTION_LIMIT = 300;

    const REPLICATION_CUSTOM = 'custom';
    const REPLICATION_NEVER = 'never';
    const REPLICATION_ALWAYS = 'always';
    const REPLICATION_MINIMUM = 3600;
    const REPLICATION_MAXIMUM = 1814400;
    const REPLICATION_INTERVAL_DAY_IN_SECONDS = 86400;
    const DEFAULT_REPLICATION = self::REPLICATION_INTERVAL_DAY_IN_SECONDS;

    const SECONDS_IN_WEEK = 604800;

    private static $replicationOptions = array(self::REPLICATION_CUSTOM, self::REPLICATION_NEVER,
        self::REPLICATION_ALWAYS, 3600, 7200, 10800, 14400, 21600, 43200, 86400, 172800, 259200, 345600, 432000, 518400,
        604800, 1209600, 1814400, 2419200, 15768000);

    /** @var string Priority level of offsiting backups */
    private $priority;

    /** @var int Max number of snapshots to delete when running offsite retention on demand */
    private $onDemandRetentionLimit;

    /** @var int Max number of snapshots to delete when running nightly offsite retention */
    private $nightlyRetentionLimit;

    /** @var int|string How frequently snapshots are sent offsite; interval in seconds, or string const */
    private $replication;

    /** @var Retention Duration to retain snapshots before deleting the offsite copy, in hours */
    private $retention;

    /** @var WeeklySchedule Snapshots are sent offsite during the selected hours */
    private $schedule;

    /** @var RecoveryPoints Local recovery points that were sent offsite (may no longer exist locally) */
    private $recoveryPoints;

    /** @var RecoveryPoints Local recovery points that were sent offsite (may no longer exist locally) */
    private $recoveryPointsCache;

    /** @var OffsiteMetrics Offsite metrics that tracked queued and completed offsite backups */
    private $offsiteMetrics;

    /**
     * @param string $priority Priority level of offsiting backups
     * @param int $onDemandRetentionLimit Max number of snapshots to delete when running offsite retention on demand
     * @param int $nightlyRetentionLimit Max number of snapshots to delete when running nightly offsite retention
     * @param int $replication How frequently snapshots are sent offsite; interval in seconds, or string const
     * @param Retention $retention Duration to retain snapshots before deleting the offsite copy, in hours
     * @param WeeklySchedule $schedule Snapshots are sent offsite during the selected hours
     * @param RecoveryPoints $recoveryPoints Local recovery points that were sent offsite (may no longer exist locally)
     * @param RecoveryPoints $recoveryPointsCache Cached recovery points
     * @param OffsiteMetrics $offsiteMetrics Offsite metrics that tracked queued and completed offsite backups
     */
    public function __construct(
        $priority = self::DEFAULT_PRIORITY,
        $onDemandRetentionLimit = self::DEFAULT_ON_DEMAND_RETENTION_LIMIT,
        $nightlyRetentionLimit = self::DEFAULT_NIGHTLY_RETENTION_LIMIT,
        $replication = self::DEFAULT_REPLICATION,
        Retention $retention = null,
        WeeklySchedule $schedule = null,
        RecoveryPoints $recoveryPoints = null,
        RecoveryPoints $recoveryPointsCache = null,
        OffsiteMetrics $offsiteMetrics = null
    ) {
        $retention = ($retention) ? $retention : Retention::createDefault();
        $schedule = ($schedule) ?: new WeeklySchedule();
        $recoveryPoints = ($recoveryPoints) ?: new RecoveryPoints();
        $recoveryPointsCache = ($recoveryPointsCache) ?: new RecoveryPoints();
        $offsiteMetrics = ($offsiteMetrics) ?: new OffsiteMetrics();

        $this->setPriority($priority);
        $this->setOnDemandRetentionLimit($onDemandRetentionLimit);
        $this->setNightlyRetentionLimit($nightlyRetentionLimit);
        $this->setReplication($replication);
        $this->setRetention($retention);
        $this->setSchedule($schedule);
        $this->setRecoveryPoints($recoveryPoints);
        $this->setRecoveryPointsCache($recoveryPointsCache);
        $this->setOffsiteMetrics($offsiteMetrics);
    }

    /**
     * @return string
     */
    public function getPriority()
    {
        return $this->priority;
    }

    /**
     * @param string $priority
     */
    public function setPriority($priority): void
    {
        if (in_array($priority, array(OffsiteSettings::LOW_PRIORITY, OffsiteSettings::MEDIUM_PRIORITY, OffsiteSettings::HIGH_PRIORITY))) {
            $this->priority = $priority;
        } else {
            $this->priority = self::DEFAULT_PRIORITY;
        }
    }

    /**
     * @return int|string
     */
    public function getReplication()
    {
        return $this->replication;
    }

    /**
     * @return array Allowed replication values
     */
    public static function getReplicationOptions()
    {
        return self::$replicationOptions;
    }

    /**
     * @param int|string $replication
     */
    public function setReplication($replication): void
    {
        if (in_array($replication, array(OffsiteSettings::REPLICATION_CUSTOM, OffsiteSettings::REPLICATION_NEVER, OffsiteSettings::REPLICATION_ALWAYS))) {
            $this->replication = $replication;
        } elseif (is_numeric($replication)) {
            $this->replication = intval($replication);
        } else {
            $this->replication = self::DEFAULT_REPLICATION;
        }
    }

    /**
     * @return int
     */
    public function getOnDemandRetentionLimit()
    {
        return $this->onDemandRetentionLimit;
    }

    /**
     * @param int $limit
     */
    public function setOnDemandRetentionLimit($limit): void
    {
        if (is_numeric($limit)) {
            $this->onDemandRetentionLimit = intval($limit);
        } else {
            $this->onDemandRetentionLimit = self::DEFAULT_ON_DEMAND_RETENTION_LIMIT;
        }
    }

    /**
     * @return int
     */
    public function getNightlyRetentionLimit()
    {
        return $this->nightlyRetentionLimit;
    }

    /**
     * @param int $limit
     */
    public function setNightlyRetentionLimit($limit): void
    {
        if (is_numeric($limit)) {
            $this->nightlyRetentionLimit = intval($limit);
        } else {
            $this->nightlyRetentionLimit = self::DEFAULT_NIGHTLY_RETENTION_LIMIT;
        }
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
     * @return RecoveryPoints
     */
    public function getRecoveryPointsCache()
    {
        return $this->recoveryPointsCache;
    }

    /**
     * @param RecoveryPoints $recoveryPointsCache
     */
    public function setRecoveryPointsCache(RecoveryPoints $recoveryPointsCache): void
    {
        $this->recoveryPointsCache = $recoveryPointsCache;
    }

    /**
     * @return OffsiteMetrics
     */
    public function getOffsiteMetrics()
    {
        return $this->offsiteMetrics;
    }

    /**
     * @param OffsiteMetrics $offsiteMetrics
     */
    public function setOffsiteMetrics(OffsiteMetrics $offsiteMetrics): void
    {
        $this->offsiteMetrics = $offsiteMetrics;
    }

    /**
     * @param OffsiteSettings $from Another asset's offsite settings object, to be copied
     */
    public function copyFrom(OffsiteSettings $from): void
    {
        $this->setPriority($from->getPriority());
        $this->setNightlyRetentionLimit($from->getNightlyRetentionLimit());
        $this->setOnDemandRetentionLimit($from->getOnDemandRetentionLimit());
        $this->setRetention($from->getRetention());
        $this->setSchedule($from->getSchedule());
        $this->setReplication($from->getReplication());
        $this->setOffsiteMetrics($from->getOffsiteMetrics());
    }

    /**
     * @param int $interval The number of local backups taken for the asset (only used if the replication type is REPLICATION_ALWAYS)
     * @param WeeklySchedule $weeklySchedule the asset's backup schedule (only used if the replication type is REPLICATION_ALWAYS)
     * @return float|int The total number of offsite backups the asset will perform in a week.
     */
    public function calculateWeeklyOffsiteCount($interval, $weeklySchedule)
    {
        if ($this->getReplication() === self::REPLICATION_CUSTOM) {
            return count($this->getSchedule()->getOldStyleSchedule());
        } elseif ($this->getReplication() === self::REPLICATION_NEVER) {
            return 0;
        } elseif ($this->getReplication() === self::REPLICATION_ALWAYS) {
            return $weeklySchedule->calculateBackupCount($interval);
        } else {
            return self::SECONDS_IN_WEEK / $this->getReplication();
        }
    }
}
