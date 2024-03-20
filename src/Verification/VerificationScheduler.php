<?php

namespace Datto\Verification;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\RecoveryPoint\FilesystemIntegritySummary;
use Datto\Asset\RecoveryPoint\RecoveryPoint;
use Datto\Asset\RecoveryPoint\RecoveryPointInfo;
use Datto\Asset\RecoveryPoint\RecoveryPointInfoService;
use Datto\Asset\VerificationSchedule;
use Datto\Asset\AssetRemovalService;
use Datto\Cloud\SpeedSync;
use Datto\Config\DeviceConfig;
use Datto\Screenshot\ScreenshotFileRepository;
use Datto\Resource\DateTimeService;
use Datto\Config\AgentConfigFactory;
use Datto\Feature\FeatureService;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;

/**
 * Schedules screenshot verifications for one or all assets based on verification schedule settings.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class VerificationScheduler implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private AgentService $agentService;
    private DeviceConfig $deviceConfig;
    private DateTimeService $dateTimeService;
    private VerificationQueue $verificationQueue;
    private ScreenshotFileRepository $screenshotFileRepository;
    private RecoveryPointInfoService $recoveryPointInfoService;
    private AgentConfigFactory $agentConfigFactory;
    private FeatureService $featureService;
    
    /**
     * @param AgentService $agentService
     * @param DeviceConfig $deviceConfig
     * @param DateTimeService $dateTimeService
     * @param VerificationQueue $verificationQueue
     * @param ScreenshotFileRepository $screenshotFileRepository
     * @param RecoveryPointInfoService $recoveryPointInfoService
     * @param AgentConfigFactory $agentConfigFactory
     * @param FeatureService $featureService
     */
    public function __construct(
        AgentService $agentService,
        DeviceConfig $deviceConfig,
        DateTimeService $dateTimeService,
        VerificationQueue $verificationQueue,
        ScreenshotFileRepository $screenshotFileRepository,
        RecoveryPointInfoService $recoveryPointInfoService,
        AgentConfigFactory $agentConfigFactory,
        FeatureService $featureService
    ) {
        $this->agentService = $agentService;
        $this->deviceConfig = $deviceConfig;
        $this->dateTimeService = $dateTimeService;
        $this->verificationQueue = $verificationQueue;
        $this->screenshotFileRepository = $screenshotFileRepository;
        $this->recoveryPointInfoService = $recoveryPointInfoService;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->featureService = $featureService;
    }

    public function scheduleVerificationsForAllAgents()
    {
        if ($this->areScreenshotsDisabled()) {
            $this->logger->info('VRS2500 Verification disabled, skipping...');

            return;
        }

        $agents = $this->sortByLeastVerified($this->agentService->getAll());
        foreach ($agents as $agent) {
            try {
                $this->scheduleVerifications($agent);
            } catch (\Throwable $e) {
                // Move on, the exception would be logged by the asset logger.
            }
        }
    }

    /**
     * @param Agent $asset
     */
    public function scheduleVerifications(Agent $asset)
    {
        $this->logger->setAssetContext($asset->getKeyName());

        if ($asset->getOriginDevice()->isReplicated()) {
            return; // replicated assets don't perform verifications
        }

        $agentConfig = $this->agentConfigFactory->create($asset->getKeyName());
        if ($agentConfig->has(AssetRemovalService::REMOVING_KEY)) {
            $this->logger->info('VRS2598 Agent is being removed, skipping...');
            return;
        }

        if ($asset->getLocal()->isArchived()) {
            $this->logger->info('VRS2599 Agent is archived, skipping...');
            return;
        }

        if ($this->areScreenshotsDisabled()) {
            $this->logger->info('VRS2500 Verification disabled, skipping...');
            return;
        }

        if (!$asset->getScreenshot()->isSupported()) {
            $this->logger->info('VRS2510 Verification not supported for agent, skipping...');
            return;
        }

        if ($asset->getVerificationSchedule()->getScheduleOption() === VerificationSchedule::NEVER) {
            $this->logger->info('VRS2511 Verification disabled for agent, skipping... ');
            return;
        }

        if ($asset->isDirectToCloudAgent()) {
            $recoveryPoints = $this->getDtcRecoveryPointsInPastDay($asset);
        } else {
            $this->recoveryPointInfoService->refreshCaches($asset);
            $recoveryPoints = $this->recoveryPointInfoService->getAll($asset);
        }

        $this->logger->info('VRS3007 Checking if any snapshots need to be verified for asset ...');

        if (empty($recoveryPoints)) {
            $this->logger->info('VRS2550 No backups taken - nothing to verify');
            return;
        }

        foreach ($recoveryPoints as $recoveryPoint) {
            if ($this->doesSnapshotNeedVerification($asset, $recoveryPoint, $recoveryPoints)) {
                $this->queueScreenshot($asset, $recoveryPoint->getEpoch());
            }
        }
    }

    /**
     * Determine if the backup should be queued for a verification
     *
     * @param Agent $agent
     * @param RecoveryPointInfo $recoveryPoint
     * @param RecoveryPointInfo[] $allRecoveryPoints
     * @return bool True if the backup should be queued for verification
     */
    public function doesSnapshotNeedVerification(
        Agent $agent,
        RecoveryPointInfo $recoveryPoint,
        array $allRecoveryPoints
    ): bool {
        $this->logger->setAssetContext($agent->getKeyName());

        if (!$recoveryPoint->existsLocally()) {
            //Point doesn't exist locally
            return false;
        }

        if ($recoveryPoint->getScreenshotStatus() !== RecoveryPoint::NO_SCREENSHOT) {
            //Point already has a screenshot.
            return false;
        }

        if ($this->dateTimeService->getElapsedTime($recoveryPoint->getEpoch()) > DateTimeService::SECONDS_PER_DAY) {
            //Recovery point created more than 24 hours ago, no need to check for screenshot.
            return false;
        }

        $selectedOption = $agent->getVerificationSchedule()->getScheduleOption();

        if ($selectedOption === VerificationSchedule::OFFSITE) {
            return $this->checkVerifyOffsitePointsSchedule($recoveryPoint);
        }

        // Direct to cloud agents do not have a backup schedule in OS2, so let's skip this check.
        if (!$agent->isDirectToCloudAgent()) {
            // TODO: determine if requiring a backup schedule is really needed and remove if not.

            // check if any backups are scheduled for the day and bail early if not.
            $backupScheduleOnDayOfSnapshot = $this->getBackupScheduleOnDayOfSnapshot($agent, $recoveryPoint->getEpoch());
            if (count($backupScheduleOnDayOfSnapshot) == 0) {
                // There were no backups scheduled for this day, so this had to be a manual backup.
                // Just to keep old behavior
                return false;
            }
        }

        if ($selectedOption === VerificationSchedule::FIRST_POINT) {
            return $this->checkFirstPointSchedule($agent, $recoveryPoint);
        } elseif ($selectedOption === VerificationSchedule::LAST_POINT) {
            return $this->checkLastPointSchedule($agent, $recoveryPoint);
        } elseif ($selectedOption ===  VerificationSchedule::CUSTOM_SCHEDULE) {
            return $this->checkCustomSchedule($agent, $recoveryPoint, $allRecoveryPoints);
        }

        $this->logger->warning('VRS3030 Verification schedule configuration value unknown. Skipping.', ['selectedOption' => $selectedOption]);

        return false;
    }

    /**
     * @param Agent $agent
     * @param RecoveryPointInfo $recoveryPoint
     * @return bool
     */
    private function checkVerifyOffsitePointsSchedule(RecoveryPointInfo $recoveryPoint): bool
    {
        $snapshotTime = $recoveryPoint->getEpoch();
        $needsScreenshot = false;

        if ($recoveryPoint->getOffsiteStatus() !== SpeedSync::OFFSITE_NONE) {
            $needsScreenshot = true;
        }

        if ($needsScreenshot) {
            $this->logger->info('VRS3019 Offsite Point needs screenshot.', ['snapshotTime' => $snapshotTime]);
        } else {
            $this->logger->debug('VRS3020 Offsite Point does not need screenshot.', ['snapshotTime' => $snapshotTime]);
        }

        return $needsScreenshot;
    }

    /**
     * @param Agent $agent
     * @param RecoveryPointInfo $recoveryPoint
     * @return bool
     */
    private function checkFirstPointSchedule(
        Agent $agent,
        RecoveryPointInfo $recoveryPoint
    ): bool {
        $recoveryPointTimestamp = $recoveryPoint->getEpoch();
        $recoveryPoints = $agent->getLocal()->getRecoveryPoints()->getAll();

        foreach ($recoveryPoints as $recoveryPoint2) {
            $timestamp = $recoveryPoint2->getEpoch();

            if ($this->dateTimeService->isSameDay($recoveryPointTimestamp, $timestamp) &&
                $timestamp < $recoveryPointTimestamp) {
                return false;
            }
        }

        $this->logger->info(
            'VRS3029 First Point Schedule does need screenshot',
            ['recoveryPointTimestamp' => $recoveryPointTimestamp]
        );

        return true;
    }

    /**
     * @param Agent $agent
     * @param RecoveryPointInfo $recoveryPoint
     * @return bool
     */
    private function checkLastPointSchedule(Agent $agent, RecoveryPointInfo $recoveryPoint): bool
    {
        $recoveryPointEpoch = $recoveryPoint->getEpoch();

        $recoveryPoints = $agent->getLocal()->getRecoveryPoints();
        $nextRecoveryPoint = $recoveryPoints->getNextNewer($recoveryPointEpoch);
        $nextRecoveryPointEpoch = $nextRecoveryPoint ? $nextRecoveryPoint->getEpoch() : 0;

        $point = $this->dateTimeService->fromTimestamp($recoveryPointEpoch);
        $point->setTime(23, 59, 59); // Round up to the end of the day
        $endOfDay = $point->getTimestamp();

        $isLastPredictedScheduledBackupOfTheDay = $this->isLastPredictedScheduledBackupOfTheDay($agent, $recoveryPoint);
        $stats = ['recoveryPoint' => $recoveryPointEpoch, 'nextRecoveryPoint' => $nextRecoveryPointEpoch, 'endOfDayEpoch' => $endOfDay];

        if ($isLastPredictedScheduledBackupOfTheDay) {
            $this->logger->debug('SCN3079 RecoveryPoint is the last predicted backup of the day.', $stats);
        } else {
            $this->logger->debug('SCN3080 RecoveryPoint is NOT the last predicted backup of the day.', $stats);
        }

        if ($nextRecoveryPointEpoch === 0) {
            // If there's no next recovery point, and we are not in the same day as the given point it means it
            // was the last backup of the day.

            $timestampNow = $this->dateTimeService->getTime();
            $todayIsAnewDay = $this->dateTimeService->isAtLeastNextDay($timestampNow, $recoveryPointEpoch);

            $this->logger->debug(
                'SCN3071 Recovery point was the last backup of the day, no subsequent recovery point found.',
                ['recoveryPointEpoch' => $recoveryPointEpoch,
                    'timestampNow' => $timestampNow,
                    'isDayLater' => $todayIsAnewDay
                ]
            );

            return $todayIsAnewDay || $isLastPredictedScheduledBackupOfTheDay;
        }

        $wasLastBackupOfTheDay = ($nextRecoveryPointEpoch > $endOfDay);

        return $isLastPredictedScheduledBackupOfTheDay || $wasLastBackupOfTheDay;
    }

    /**
     * @param Agent $agent
     * @param RecoveryPointInfo $recoveryPoint
     * @param RecoveryPointInfo[] $allRecoveryPoints
     * @return bool
     */
    private function checkCustomSchedule(
        Agent $agent,
        RecoveryPointInfo $recoveryPoint,
        array $allRecoveryPoints
    ): bool {
        $needsScreenshot = false;
        $snapshotTime = $recoveryPoint->getEpoch();

        $screenshotDate = $this->dateTimeService->getDate('r', $snapshotTime);

        $this->logger->debug(
            'SCN3005 Checking recovery point against custom screenshot schedule.',
            ['snapshotTime' => $snapshotTime, 'screenshotDate' => $screenshotDate]
        );

        $backupOffset = $this->getBackupOffset();
        $backupOffsetSeconds = $backupOffset * 60;

        $backupInterval = $agent->getLocal()->getInterval();

        // get needed info from snapshot timestamp.
        $snapshotHourOfWeek = $this->getWeekHour($snapshotTime + $backupOffsetSeconds);
        $this->logger->debug(
            'SCN3006 The point at this snapshot time is for the give hour of the week.',
            ['snapshotTime' => $snapshotTime, '$snapshotHourOfWeek' => $snapshotHourOfWeek]
        );

        $snapshotMinuteOfHour = (int) $this->dateTimeService->getDate('i', $snapshotTime + $backupOffsetSeconds);
        $this->logger->debug(
            'SCN3007 The point at this snapshot time is at the given minute of the hour.',
            ['snapshotTime' => $snapshotTime, 'snapshotMinuteOfHour' => $snapshotMinuteOfHour]
        );

        $verificationsOnDayOfSnapshot = $this->getVerificationsOnDayOfSnapshot($agent, $snapshotTime + $backupOffsetSeconds);
        $this->logger->debug(
            'SCN3008 Verifications taken for today.',
            ['verificationsOnDayOfSnapshot' => $verificationsOnDayOfSnapshot]
        );
        // Direct to cloud agents do not have a backup schedule in OS2
        $backupScheduleOnDayOfSnapshot = [];
        if (!$agent->isDirectToCloudAgent()) {
            // get the backup schedule hours for the day the snapshot was taken.
            $backupScheduleOnDayOfSnapshot = $this->getBackupScheduleOnDayOfSnapshot($agent, $snapshotTime + $backupOffsetSeconds);
        }

        $screenshotSchedule = $agent->getVerificationSchedule()->getCustomSchedule()->getOldStyleSchedule();
        $screenshotSchedule = array_map('intval', $screenshotSchedule);

        // check if we're in the custom schedule and double-check against
        // backup schedule in case those are out of sync (they shouldn't but
        // just in case they are)
        if (in_array($snapshotHourOfWeek, $screenshotSchedule) &&
            (in_array($snapshotHourOfWeek, $backupScheduleOnDayOfSnapshot) || $agent->isDirectToCloudAgent())
        ) {
            // for a full hr interval just return as it's the only snapshot
            //   otherwise check if it's in the first interval slot of the hour
            if ($backupInterval == 60) {
                $this->logger->debug(
                    'SCN3009 This point was the only backup this hour.',
                    ['snapshotTime' => $snapshotTime]
                );

                $needsScreenshot = true;
            } elseif ($snapshotMinuteOfHour < $backupInterval) {
                $this->logger->debug(
                    'SCN3010 This point was within the backup interval.',
                    ['snapshotTime' => $snapshotTime]
                );

                $needsScreenshot = true;
            } elseif (!in_array($snapshotHourOfWeek, $verificationsOnDayOfSnapshot)) {
                $this->logger->debug(
                    'SCN3011 This point was the in the array of screenshots to be taken today.',
                    ['snapshotTime' => $snapshotTime]
                );

                // in case targeted interval was missed somehow, still
                // enqueue this snapshot.
                $needsScreenshot = true;
            }
        } elseif ($this->needToPerformCloudVerificationForToday($agent, $allRecoveryPoints)) {
            $needsScreenshot = $this->isSnapshotMostRecentInPastDay($agent, $recoveryPoint, $allRecoveryPoints);
        } else {
            $this->logger->debug(
                'SCN3012 This point was not in the backup schedule.',
                ['snapshotTime' => $snapshotTime]
            );
        }

        return $needsScreenshot;
    }

    /**
     * @param Agent $agent
     * @param RecoveryPointInfo[] $allRecoveryPoints
     *
     * @return bool Whether or not we need to consider cloud verification logic
     */
    private function needToPerformCloudVerificationForToday(Agent $agent, array $allRecoveryPoints): bool
    {
        return
            $this->featureService->isSupported(FeatureService::FEATURE_CLOUD_ASSISTED_VERIFICATION_OFFLOADS) &&
            $this->isCurrentTimeInRangeForCustomSchedule($agent) &&
            !$this->isScreenshotAlreadyDoneInPastDay($allRecoveryPoints);
    }

    /**
     * @param RecoveryPointInfo[] $allRecoveryPoints
     *
     * @return bool Has any snapshot been verified already in the past 24 hours
     */
    private function isScreenshotAlreadyDoneInPastDay(array $allRecoveryPoints): bool
    {
        foreach ($allRecoveryPoints as $recoveryPoint) {
            if ($recoveryPoint->getScreenshotStatus() !== RecoveryPoint::NO_SCREENSHOT) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Agent $agent
     * @param RecoveryPointInfo $recoveryPoint
     * @param RecoveryPointInfo[] $allRecoveryPoint
     *
     * @return bool True if the provided snapshot is the most recent one in the past 24 hours
     */
    private function isSnapshotMostRecentInPastDay(
        Agent $agent,
        RecoveryPointInfo $recoveryPoint,
        array $allRecoveryPoints
    ): bool {
        $snapshotTime = $recoveryPoint->getEpoch();

        foreach ($allRecoveryPoints as $otherPoint) {
            if ($otherPoint->getEpoch() > $snapshotTime) {
                return false;
            }
        }

        $this->logger->debug(
            'SCN3014 This point is the most recent in the past day.',
            ['snapshotTime' => $snapshotTime]
        );
        return true;
    }

    /**
     * @param Agent $agent
     *
     * @return bool True if the current time is during the hour for screenshot according to the schedule
     */
    private function isCurrentTimeInRangeForCustomSchedule(Agent $agent): bool
    {
        $timestampNow = $this->dateTimeService->getTime();
        $hourOfWeek = $this->getWeekHour($timestampNow);
        $screenshotSchedule = $agent->getVerificationSchedule()->getCustomSchedule()->getOldStyleSchedule();
        $screenshotSchedule = array_map('intval', $screenshotSchedule);

        return in_array($hourOfWeek, $screenshotSchedule);
    }

    /**
     * @param Agent $agent
     * @param int $snapshotTimestamp
     *
     * @return int[] backup schedule for the day in 'hours of week'
     */
    private function getBackupScheduleOnDayOfSnapshot(Agent $agent, int $snapshotTimestamp): array
    {
        $backupSchedule = $agent->getLocal()->getSchedule()->getOldStyleSchedule();
        $backupSchedule = array_map('intval', $backupSchedule);
        $snapshotDayOfWeek = $this->dateTimeService->getDayOfWeek($snapshotTimestamp);

        // get the backup schedule hours for the day the snapshot was taken.
        $backupScheduleOnDayOfSnapshot = [];
        foreach ($backupSchedule as $hour) {
            $day = (int) ($hour / 24);

            // match snapshot day with schedule day
            if ($day == $snapshotDayOfWeek) {
                $backupScheduleOnDayOfSnapshot[] = $hour;
            }
        }
        sort($backupScheduleOnDayOfSnapshot);

        return $backupScheduleOnDayOfSnapshot;
    }

    /**
     * @param Agent $agent
     * @param int $snapshotTimestamp
     *
     * @return int[] verifications performed for the day in 'hours of week'
     */
    private function getVerificationsOnDayOfSnapshot(Agent $agent, int $snapshotTimestamp): array
    {
        $verificationsOnDayOfSnapshot = [];
        // get screenshots from today and store as hours in backup calendar format
        $allScreenshots = $this->screenshotFileRepository->getAllByKeyName($agent->getKeyName());

        foreach ($allScreenshots as $screenshotFile) {
            // match the day for today.
            if ($this->dateTimeService->isSameDay($snapshotTimestamp, $screenshotFile->getSnapshotEpoch())) {
                // save as hour relative to backup calendar schedule.
                $verificationsOnDayOfSnapshot[] = $this->dateTimeService->getHourOfWeek(
                    $screenshotFile->getSnapshotEpoch()
                );
            }
        }

        return $verificationsOnDayOfSnapshot;
    }

    /**
     * Add the backup to the verification queue
     *
     * @param Agent $agent
     * @param int $snapshotTime
     */
    private function queueScreenshot(Agent $agent, int $snapshotTime)
    {
        $time = $this->dateTimeService->getTime();
        $mostRecentSnapshotPoint = $agent->getLocal()->getRecoveryPoints()->getMostRecentPointWithScreenshot();
        $mostRecentSnapshotEpoch = $mostRecentSnapshotPoint ? $mostRecentSnapshotPoint->getEpoch() : 0;

        $verificationAsset = new VerificationAsset($agent->getKeyName(), $snapshotTime, $time, $mostRecentSnapshotEpoch);
        $this->verificationQueue->add($verificationAsset);

        $this->logger->info('SCN0855 Screenshot for this snapshot added to queue.', ['snapshotTime' => $snapshotTime]);
    }

    /**
     * Get the hour of the week for the given timestamp
     *
     * @param int $timeStamp
     * @return float
     */
    private function getWeekHour(int $timeStamp)
    {
        $weekMinute = $this->dateTimeService->getMinuteOfWeek($timeStamp);
        $weekHour = floor(($weekMinute)/60);

        return $weekHour;
    }

    /**
     * @return int
     */
    private function getBackupOffset(): int
    {
        $backupOffset = 0;
        if ($this->deviceConfig->has('backupOffset')) {
            $backupOffset = (int)$this->deviceConfig->get('backupOffset');
        }

        return $backupOffset;
    }

    /**
     * @return bool
     */
    private function areScreenshotsDisabled(): bool
    {
        return $this->deviceConfig->has('disableScreenshots') && $this->deviceConfig->get('disableScreenshots') === '1';
    }

    /**
     * Predict if a snapshot is the last one scheduled for the day.
     *
     * @param Agent $agent
     * @param RecoveryPoint $recoveryPoint
     * @return bool
     */
    private function isLastPredictedScheduledBackupOfTheDay(Agent $agent, RecoveryPoint $recoveryPoint)
    {
        $backupInterval = $agent
            ->getLocal()
            ->getInterval();

        $recoveryPointEpoch = $recoveryPoint->getEpoch();

        $backupOffset = $this->getBackupOffset();
        $recoveryPointEpoch += ($backupOffset * 60);

        return $agent->getLocal()->getSchedule()->isLastPredictedScheduledBackupOfTheDay(
            $recoveryPointEpoch,
            $backupInterval
        );
    }

    /**
     * @param Agent $agent
     *
     * @return RecoveryPointInfo[] All snapshots within the past 24 hour period
     */
    private function getDtcRecoveryPointsInPastDay(Agent $agent): array
    {
        // FIXME: Refreshing caches and fetching RecoveryPointInfo objects can take a lot of time on cloud devices.
        //       As a performance hack, create the objects here with only the information we depend on.

        $recoveryPointsToCheck = $agent->getLocal()->getRecoveryPoints()->getNewerThan(
            $this->dateTimeService->getTime() - DateTimeService::SECONDS_PER_DAY
        );
        $snapshotsToCheck = array_map(function (RecoveryPoint $recoveryPoint) {
            return $recoveryPoint->getEpoch();
        }, $recoveryPointsToCheck);

        return array_map(function (int $epoch) use ($agent) {
            return new RecoveryPointInfo(
                $epoch,
                true,
                false, // not used
                false, // not used
                $this->recoveryPointInfoService->determineScreenshotStatus($agent, $epoch),
                0, // not used
                0, // not used
                0, // not used
                [], // not used
                [], // not used
                [], // not used
                '',
                FilesystemIntegritySummary::createEmpty() // not used
            );
        }, $snapshotsToCheck);
    }

    /**
     * Sorts agents by the snapshot timestamp of their latest screenshot verification in ascending order.
     * Agents that have never run a screenshot verification will be in the front of the array.
     *
     * @param Agent[] $agents
     * @return Agent[]
     */
    public function sortByLeastVerified(array $agents): array
    {
        usort($agents, function (Agent $agentA, Agent $agentB): int {
            $lastScreenshotPointA = $agentA->getLocal()->getRecoveryPoints()->getMostRecentPointWithScreenshot();
            $lastScreenshotPointB = $agentB->getLocal()->getRecoveryPoints()->getMostRecentPointWithScreenshot();
            $epochA = $lastScreenshotPointA ? $lastScreenshotPointA->getEpoch() : 0;
            $epochB = $lastScreenshotPointB ? $lastScreenshotPointB->getEpoch() : 0;

            // sort in ascending order
            return $epochA <=> $epochB;
        });

        return $agents;
    }
}
