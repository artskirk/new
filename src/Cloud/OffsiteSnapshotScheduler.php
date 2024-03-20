<?php

namespace Datto\Cloud;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Asset;
use Datto\Asset\AssetService;
use Datto\Asset\AssetType;
use Datto\Asset\OffsiteSettings;
use Datto\Asset\RecoveryPoint\RecoveryPoint;
use Datto\Asset\RecoveryPoint\RecoveryPoints;
use Datto\Asset\Serializer\LegacyOffsiteSettingsSerializer;
use Datto\Config\DeviceConfig;
use Datto\Utility\File\Lock;
use Datto\Utility\File\LockFactory;
use Datto\Log\LoggerFactory;
use Datto\Metrics\Offsite\OffsiteMetricsService;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Exception;
use Throwable;

/**
 * Schedules snapshots of offsiting based on configured offsite policy (interval, custom schedule, or always/never).
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class OffsiteSnapshotScheduler
{
    const OFFSITE_CONTROL_FILE_FORMAT = Agent::KEYBASE . '%s.offsiteControl';
    const SYNC_LOCK_FILE_FORMAT = self::OFFSITE_CONTROL_FILE_FORMAT;
    const HALF_SHORTEST_BACKUP_INTERVAL_SEC = 150; // Half of 5 minute interval

    /** @var AssetService */
    private $assetService;

    /** @var Filesystem */
    private $filesystem;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var OffsiteMetricsService */
    private $offsiteMetricsService;

    /** @var LockFactory */
    private $lockFactory;

    /** @var Lock|null */
    private $lock = null;

    /** @var LoggerFactory */
    private $loggerFactory;

    /** @var SpeedSync */
    private $speedSync;

    public function __construct(
        AssetService $assetService,
        Filesystem $filesystem,
        DateTimeService $dateTimeService,
        OffsiteMetricsService $offsiteMetricsService,
        LockFactory $lockFactory,
        LoggerFactory $loggerFactory,
        SpeedSync $speedSync,
        DeviceConfig $deviceConfig
    ) {
        $this->assetService = $assetService;
        $this->filesystem = $filesystem;
        $this->dateTimeService = $dateTimeService;
        $this->offsiteMetricsService = $offsiteMetricsService;
        $this->lockFactory = $lockFactory;
        $this->loggerFactory = $loggerFactory;
        $this->speedSync = $speedSync;
        $this->deviceConfig = $deviceConfig;
    }

    /**
     * Schedule snapshots for offsiting for all assets.
     */
    public function scheduleSnapshotsForAllAssets()
    {
        $assets = $this->assetService->getAllLocal();
        foreach ($assets as $asset) {
            try {
                $this->scheduleSnapshots($asset);
            } catch (Throwable $e) {
                // Move on, the exception would be logged by the asset logger.
            }
        }
    }

    /**
     * Schedule snapshots for offsiting for a specific asset.
     *
     * @param Asset $asset
     */
    public function scheduleSnapshots(Asset $asset)
    {
        if ($asset->getLocal()->isArchived()) {
            $this->loggerFactory->getAsset($asset->getKeyName())->info('SYN1405 Skipping offsite scheduling for archived asset');
            return; // archived assets do not offsite
        }
        if ($asset->getOriginDevice()->isReplicated()) {
            $this->loggerFactory->getAsset($asset->getKeyName())->info('SYN1406 Skipping offsite scheduling for replicated asset');
            return; // replicated assets don't offsite
        }
        $this->acquireLock($asset);
        $logger = $this->loggerFactory->getAsset($asset->getKeyName());

        $recoveryPoints = $asset->getLocal()->getRecoveryPoints();
        $recoveryPointObjects = $recoveryPoints->getAll();

        if (empty($recoveryPointObjects)) {
            $logger->info("SYN2550 No backups taken - nothing to offsite");
        } else {
            foreach ($recoveryPointObjects as $recoveryPoint) {
                if ($this->isSnapshotReadyToSendOffsite($asset, $recoveryPoint, $recoveryPoints)) {
                    $this->sendSnapshotOffsite($asset, $recoveryPoint);
                }
            }
        }

        $this->releaseLock();
    }

    /**
     * Determine if a snapshot is ready to send offsite.
     *
     * If the snapshot is the latest successful snapshot in the offsite window, send if offsite.
     * If the snapshot matches a custom schedule, send it offsite.
     *
     * @param Asset $asset
     * @param RecoveryPoint $recoveryPoint
     * @param RecoveryPoints $recoveryPoints
     * @return bool
     */
    public function isSnapshotReadyToSendOffsite(
        Asset $asset,
        RecoveryPoint $recoveryPoint,
        RecoveryPoints $recoveryPoints
    ): bool {
        $logger = $this->loggerFactory->getAsset($asset->getKeyName());

        $pointOlderThan24Hours = $this->dateTimeService->getElapsedTime($recoveryPoint->getEpoch()) > DateTimeService::SECONDS_PER_DAY;
        $isDtc = $asset instanceof Agent && $asset->isDirectToCloudAgent();

        if ($pointOlderThan24Hours && !$isDtc) {
            // Recovery point created more than 24 hours ago, no need to check for offsite, unless the agent is DTC.
            return false;
        }

        $offsiteControlFileLocation = sprintf(self::OFFSITE_CONTROL_FILE_FORMAT, $asset->getKeyName());

        if (!$this->filesystem->exists($offsiteControlFileLocation)) {
            $logger->info(
                "SYN1402 Offsite Control file does not exist. Issuing the command for Speedsync offsite and letting Speedsync make offsite determinations.",
                ['offsiteControlFileLocation' => $offsiteControlFileLocation]
            );

            return true;
        }

        $offsiteControl = json_decode(trim($this->filesystem->fileGetContents($offsiteControlFileLocation)), true);
        $latestOffsiteEpoch = (int)($offsiteControl['latestOffsiteSnapshot'] ?? 0);

        $isEarlierThanConnectingPoint = $recoveryPoint->getEpoch() < $latestOffsiteEpoch;
        $isAlreadyOffsite = $recoveryPoint->getEpoch() == $latestOffsiteEpoch;

        if ($isEarlierThanConnectingPoint || $isAlreadyOffsite) {
            if (!$isDtc) {
                $logger->debug(
                    "SYN1403 Skipping offsite for point: {$recoveryPoint->getEpoch()} because: " .
                    ($isEarlierThanConnectingPoint ? "isEarlierThanConnectingPoint" : "isAlreadyOffsite")
                );
            }

            return false;
        }

        $offsiteInterval = (int)($offsiteControl['interval'] ?? 0);

        if ($offsiteInterval === LegacyOffsiteSettingsSerializer::REPLICATION_NEVER) {
            $logger->info(
                "SYN3000 Snapshot for asset won't be sent offsite, per asset offsite configuration.",
                ['snapshot' => $recoveryPoint->getEpoch(), 'asset' => $asset->getKeyName()]
            );

            return false;
        } elseif ($offsiteInterval === LegacyOffsiteSettingsSerializer::REPLICATION_ALWAYS) {
            $logger->info(
                "SYN3001 Snapshot for asset will be sent offsite because always send offsite is enabled.",
                ['snapshot' => $recoveryPoint->getEpoch(), 'asset' => $asset->getKeyName()]
            );
            return true;
        } elseif ($offsiteInterval === LegacyOffsiteSettingsSerializer::REPLICATION_CUSTOM) {
            return $this->checkCustomSchedule($asset, $recoveryPoint, $recoveryPoints, $latestOffsiteEpoch);
        } elseif ($offsiteInterval >= OffsiteSettings::REPLICATION_INTERVAL_DAY_IN_SECONDS) {
            return $this->checkIntervalGreaterThanOneDay($asset, $recoveryPoint, $recoveryPoints, $offsiteControl);
        } elseif ($offsiteInterval < OffsiteSettings::REPLICATION_INTERVAL_DAY_IN_SECONDS && $offsiteInterval > 0) {
            return $this->checkIntervalLessThanOneDay($asset, $recoveryPoint, $offsiteControl);
        } else {
            $logger->error(
                "SYN3001 Invalid offsite interval value",
                ['offsiteInterval' => $offsiteInterval]
            );

            return false;
        }
    }

    /**
     * Predict if a snapshot is the last one scheduled for the day.
     *
     * @param Asset $asset
     * @param RecoveryPoint $recoveryPoint
     * @return bool
     */
    public function isLastPredictedScheduledBackupOfTheDay(Asset $asset, RecoveryPoint $recoveryPoint)
    {
        $backupInterval = $asset
            ->getLocal()
            ->getInterval();

        $recoveryPointEpoch = $recoveryPoint->getEpoch();

        return $asset->getLocal()->getSchedule()->isLastPredictedScheduledBackupOfTheDay(
            $recoveryPointEpoch,
            $backupInterval
        );
    }

    /**
     * Replicate the snapshot offsite via speedsync.
     *
     * @param Asset $asset
     * @param RecoveryPoint $recoveryPoint
     */
    private function sendSnapshotOffsite(Asset $asset, RecoveryPoint $recoveryPoint)
    {
        $isDtc = $asset instanceof Agent && $asset->isDirectToCloudAgent();
        if ($this->speedsyncMirror($asset, $recoveryPoint) || !$isDtc) {
            $this->updateOffsiteControlFile($asset, $recoveryPoint);
        }
    }

    /**
     * Check if a snapshot needs to be sent offsite based on a custom schedule.
     *
     * @param Asset $asset
     * @param RecoveryPoint $recoveryPoint
     * @param RecoveryPoints $recoveryPoints
     * @param int $latestOffsiteEpoch
     * @return bool
     */
    private function checkCustomSchedule(
        Asset $asset,
        RecoveryPoint $recoveryPoint,
        RecoveryPoints $recoveryPoints,
        int $latestOffsiteEpoch
    ) {
        $logger = $this->loggerFactory->getAsset($asset->getKeyName());
        $backupOffset = intval($this->deviceConfig->get('backupOffset', 0)) * DateTimeService::SECONDS_PER_MINUTE;
        $latestOffsiteEpochPlusOffset = $latestOffsiteEpoch + $backupOffset;

        $recoveryPointEpoch = $recoveryPoint->getEpoch() + $backupOffset;
        $recoveryPointDayOfWeek = $this->dateTimeService->getDayOfWeek($recoveryPointEpoch);
        $recoveryPointHourOfWeek = $this->dateTimeService->getHourOfWeek($recoveryPointEpoch);
        $recoveryPointHourOfDay = $recoveryPointHourOfWeek % DateTimeService::HOURS_PER_DAY;

        // Retrieve the time of the latest recovery point and compare with the current recovery point
        $latestRecoveryPointEpoch = $recoveryPoints->getLast() ? $recoveryPoints->getLast()->getEpoch() + $backupOffset : 0;
        $isLatestRecoveryPoint = $recoveryPointEpoch == $latestRecoveryPointEpoch;

        // Retrieve the current datetime
        $now = $this->dateTimeService->getTime() + $backupOffset;
        $dayOfWeek = $this->dateTimeService->getDayOfWeek($now);
        $hourOfWeek = $this->dateTimeService->getHourOfWeek($now);
        $hourOfDay = $hourOfWeek % DateTimeService::HOURS_PER_DAY;

        // If the current recovery point is more than a day old, hour of day comparisons could over-run
        if ($now - $recoveryPointEpoch > DateTimeService::SECONDS_PER_DAY) {
            return false;
        }

        // Check if the current recovery point is the latest for this hour
        $isLastBackupForThisHour = $isLatestRecoveryPoint && $hourOfDay == $recoveryPointHourOfDay;

        $isPreviousHourBackup = $recoveryPointHourOfDay === ($hourOfDay - 1);
        $isLastBackupForPreviousHour = false;
        if (!$isLastBackupForThisHour && $isPreviousHourBackup) {
            $isLastBackupForPreviousHour = true;
            foreach ($recoveryPoints->getAll() as $backup) {
                $backupEpoch = $backup->getEpoch() + $backupOffset;
                if ($now - $backupEpoch > DateTimeService::SECONDS_PER_DAY) {
                    continue;
                }
                $backupHourOfWeek = $this->dateTimeService->getHourOfWeek($backupEpoch);
                $backupHourOfDay = $backupHourOfWeek % DateTimeService::HOURS_PER_DAY;
                // Check if a backup in previous hour occurred later
                if ($backupHourOfDay == $recoveryPointHourOfDay && $recoveryPointEpoch < $backupEpoch) {
                    $isLastBackupForPreviousHour = false;
                    break;
                }
            }
        }

        if (!$isLastBackupForThisHour && !$isLastBackupForPreviousHour) {
            return false;
        }

        $customSchedule = $asset->getOffsite()->getSchedule()->getSchedule();
        $onSchedule = $customSchedule[$recoveryPointDayOfWeek][$recoveryPointHourOfDay];

        $lastOffsiteDayOfWeek = $this->dateTimeService->getDayOfWeek($latestOffsiteEpochPlusOffset);
        $lastOffsiteHourOfWeek = $this->dateTimeService->getHourOfWeek($latestOffsiteEpochPlusOffset);
        $lastOffsiteHourOfDay = $lastOffsiteHourOfWeek % DateTimeService::HOURS_PER_DAY;

        $timeGap = $isLastBackupForPreviousHour ? (2 * DateTimeService::SECONDS_PER_HOUR) : DateTimeService::SECONDS_PER_HOUR;
        $hourToCompare = $isLastBackupForPreviousHour ? $hourOfDay - 1 : $hourOfDay;

        $alreadyHasBackupOffsiteThisHour =
            ($now - $latestOffsiteEpochPlusOffset) < $timeGap &&
            ($lastOffsiteDayOfWeek == $dayOfWeek && $lastOffsiteHourOfDay == $hourToCompare);

        $shouldOffsite = $onSchedule && !$alreadyHasBackupOffsiteThisHour;

        if ($shouldOffsite) {
            $logger->info(
                "SYN3002 Snapshot for asset will be sent offsite because it is the latest backup " .
                "and the custom schedule indicates it is time to send offsite.",
                ['snapshot' => $recoveryPoint->getEpoch(), 'asset' => $asset->getKeyName()]
            );
        }

        return $shouldOffsite;
    }

    /**
     * Check if a snapshot needs to be sent offsite based on an interval greater than or equal to one day.
     *
     * @param Asset $asset
     * @param RecoveryPoint $recoveryPoint
     * @param RecoveryPoints $recoveryPoints
     * @param array $offsiteControl
     * @return bool
     */
    private function checkIntervalGreaterThanOneDay(
        Asset $asset,
        RecoveryPoint $recoveryPoint,
        RecoveryPoints $recoveryPoints,
        array $offsiteControl
    ) {
        $logger = $this->loggerFactory->getAsset($asset->getKeyName());

        $latestOffsiteEpoch = (int)($offsiteControl['latestOffsiteSnapshot'] ?? 0);
        $offsiteInterval = (int)($offsiteControl['interval'] ?? 0);
        $idealNextOffsite = $latestOffsiteEpoch + $offsiteInterval;

        $intervalElapsed =
            $this->dateTimeService->isSameDay($recoveryPoint->getEpoch(), $idealNextOffsite) ||
            $this->dateTimeService->isAtLeastNextDay($recoveryPoint->getEpoch(), $idealNextOffsite);

        $isLastPredictedScheduledBackupOfTheDay = $this->isLastPredictedScheduledBackupOfTheDay($asset, $recoveryPoint);
        $wasLastBackupOfTheDay = $this->wasLastBackupOfTheDay($asset, $recoveryPoint, $recoveryPoints);

        $shouldOffsite = ($wasLastBackupOfTheDay || $isLastPredictedScheduledBackupOfTheDay) && $intervalElapsed;

        $logger->debug(
            "SYN3069 Snapshot: {$recoveryPoint->getEpoch()}: " .
            "IntervalElapsed: " . ($intervalElapsed ? "true" : "false") .
            ", WasLastBackup: " . ($wasLastBackupOfTheDay ? "true" : "false") .
            ", IsLastPredictedBackup: " . ($isLastPredictedScheduledBackupOfTheDay ? "true" : "false")
        );

        if ($shouldOffsite) {
            $logger->info(
                "SYN3005 Snapshot for asset will be sent offsite because " .
                "the interval has elapsed and this was predicted to be the last scheduled backup of the day " .
                "(interval greater than one day).",
                ['snapshot' => $recoveryPoint->getEpoch(), 'asset' => $asset->getKeyName()]
            );
        }

        return $shouldOffsite;
    }

    /**
     * Check if a snapshot needs to be sent offsite based on an interval less than one day.
     *
     * @param Asset $asset
     * @param RecoveryPoint $recoveryPoint
     * @param array $offsiteControl
     * @return bool
     */
    private function checkIntervalLessThanOneDay(
        Asset $asset,
        RecoveryPoint $recoveryPoint,
        array $offsiteControl
    ) {
        $logger = $this->loggerFactory->getAsset($asset->getKeyName());

        $latestOffsiteEpoch = (int)($offsiteControl['latestOffsiteSnapshot'] ?? 0);
        $offsiteInterval = (int)($offsiteControl['interval'] ?? 0);
        $idealNextOffsite = $latestOffsiteEpoch + $offsiteInterval;

        $intervalElapsed = ($idealNextOffsite - self::HALF_SHORTEST_BACKUP_INTERVAL_SEC) <= $recoveryPoint->getEpoch();
        $shouldOffsite = $intervalElapsed;

        if ($shouldOffsite) {
            $logger->info(
                "SYN3006 Snapshot for asset will be sent offsite because the interval " .
                "has elapsed (interval less than one day).",
                ['snapshot' => $recoveryPoint->getEpoch(), 'asset' => $asset->getKeyName()]
            );
        }

        return $shouldOffsite;
    }

    /**
     * Determine if a snapshot was the last backup of the day.
     *
     * @param Asset $asset
     * @param RecoveryPoint $recoveryPoint
     * @param RecoveryPoints $recoveryPoints
     * @return bool
     */
    private function wasLastBackupOfTheDay(Asset $asset, RecoveryPoint $recoveryPoint, RecoveryPoints $recoveryPoints)
    {
        $logger = $this->loggerFactory->getAsset($asset->getKeyName());

        $recoveryPointEpoch = $recoveryPoint->getEpoch();

        $nextRecoveryPoint = $recoveryPoints->getNextNewer($recoveryPointEpoch);
        $nextRecoveryPointEpoch = $nextRecoveryPoint ? $nextRecoveryPoint->getEpoch() : 0;

        $end = $this->dateTimeService->fromTimestamp($recoveryPointEpoch);
        $end->setTime(23, 59, 0); // Round up to the end of the day
        $end = $end->getTimestamp();

        $logger->debug(
            "SYN3070 RecoveryPoint: $recoveryPointEpoch, NextRecoveryPoint: $nextRecoveryPointEpoch" .
            ", EndOfDayEpoch: $end"
        );

        if ($nextRecoveryPointEpoch === 0) {
            // If there's no next recovery point, and we are not in the same day as the given point it means it
            // was the last backup of the day.

            $timestampNow = $this->dateTimeService->getTime();
            $todayIsAnewDay = $this->dateTimeService->isAtLeastNextDay($timestampNow, $recoveryPointEpoch);

            $logger->debug(
                "SYN3071 RecoveryPoint: $recoveryPointEpoch, Moment Now: $timestampNow, IsDayLater: " .
                ($todayIsAnewDay ? "true" : "false")
            );

            return $todayIsAnewDay;
        }

        return $nextRecoveryPointEpoch > $end;
    }


    /**
     * @param Asset $asset
     * @param RecoveryPoint $recoveryPoint
     *
     * @return bool
     */
    private function speedsyncMirror(Asset $asset, RecoveryPoint $recoveryPoint): bool
    {
        $logger = $this->loggerFactory->getAsset($asset->getKeyName());

        $zfsPath = $asset->getDataset()->getZfsPath();
        $recoveryPointEpoch = $recoveryPoint->getEpoch();

        $logger->info(
            "SYN2052 Executing Speedsync mirror for mirror on demand",
            ['zfsPath' => $zfsPath, 'recoveryPointEpoch' => $recoveryPointEpoch]
        );

        try {
            $this->speedSync->add($zfsPath, $asset->getOffsiteTarget());
        } catch (Throwable $throwable) {
            $logger->warning(
                "SYN2053 Failed Speedsync add",
                ['exception' => $throwable]
            );
        }

        $mirrorSuccess = $this->speedSync->sendOffsite($zfsPath, $recoveryPointEpoch);

        $this->offsiteMetricsService->addQueuedPoint($asset->getKeyName(), $recoveryPointEpoch);

        return $mirrorSuccess;
    }

    /**
     * Write the latest snapshot to the offsiteControl file
     *
     * @param Asset $asset
     * @param RecoveryPoint $recoveryPoint
     */
    private function updateOffsiteControlFile(Asset $asset, RecoveryPoint $recoveryPoint)
    {
        $logger = $this->loggerFactory->getAsset($asset->getKeyName());

        $logger->info("SYN1483 Updating offsiteControl file");

        $offsiteControlFileLocation = sprintf(self::OFFSITE_CONTROL_FILE_FORMAT, $asset->getKeyName());
        $offsiteControl = [];

        if ($this->filesystem->exists($offsiteControlFileLocation)) {
            $offsiteControl = json_decode(trim($this->filesystem->fileGetContents($offsiteControlFileLocation)), true);
        }

        $offsiteControl['latestOffsiteSnapshot'] = $recoveryPoint->getEpoch();

        $this->filesystem->filePutContents($offsiteControlFileLocation, json_encode($offsiteControl));
    }

    /**
     * @param Asset $asset
     */
    private function acquireLock(Asset $asset)
    {
        $this->lock = $this->lockFactory->getProcessScopedLock(sprintf(self::SYNC_LOCK_FILE_FORMAT, $asset->getKeyName()));
        if (!$this->lock->exclusive(false)) {
            throw new Exception("Could not acquire offsite snapshot scheduler lock for " . $asset->getKeyName());
        }
    }

    private function releaseLock()
    {
        $this->lock->unlock();
    }
}
