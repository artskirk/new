<?php

namespace Datto\Service\Metrics\Measurement;

use Datto\Asset\RecoveryPoint\RecoveryPoint;
use Datto\Backup\BackupManagerFactory;
use Datto\Service\Metrics\Measurement;
use Datto\Metrics\Collector;
use Datto\Feature\FeatureService;
use Datto\Log\DeviceLoggerInterface;
use Datto\Service\Metrics\MetricsContext;
use Datto\Metrics\Metrics;
use Datto\Resource\DateTimeService;
use Datto\Asset\Agent\Agent;

/**
 * Collects measurements around active DTC agents.
 *
 * @author Sri Ramanujam <sramanujam@datto.com>
 */
class ActiveDtcAgents extends Measurement
{
    const BACKUP_TYPES_ALLOWED = [
        RecoveryPoint::VOLUME_BACKUP_TYPE_DIFFERENTIAL,
        RecoveryPoint::VOLUME_BACKUP_TYPE_INCREMENTAL,
        RecoveryPoint::VOLUME_BACKUP_TYPE_FULL
    ];
    const BACKUP_TYPE_UNKNOWN = 'unknown';
    const UNKNOWN_AGENT_VERSION = 'unknown';

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var BackupManagerFactory */
    private $backupManagerFactory;

    public function __construct(
        Collector $collector,
        FeatureService $featureService,
        DateTimeService $dateTimeService,
        DeviceLoggerInterface $logger,
        BackupManagerFactory $backupManagerFactory
    ) {
        parent::__construct(
            $collector,
            $featureService,
            $logger
        );

        $this->dateTimeService = $dateTimeService;
        $this->backupManagerFactory = $backupManagerFactory;
    }

    /**
     * @inheritDoc
     */
    public function description(): string
    {
        return 'active DTC agent information';
    }

    /**
     * @inheritDoc
     */
    public function enabled(): bool
    {
        return $this->featureService->isSupported(FeatureService::FEATURE_DIRECT_TO_CLOUD_AGENTS);
    }

    /**
     * @inheritdoc
     */
    public function collect(MetricsContext $context)
    {
        $agentsByAgentVersion = $this->segmentAgentsByAgentVersion($context);

        $this->collectNumberOfActiveAgents($agentsByAgentVersion);
        $this->collectRecoveryPointsMetrics($agentsByAgentVersion);
        $this->collectCheckinMetrics($agentsByAgentVersion);
        $this->collectActiveAgentsWithSuccessfulVerification($agentsByAgentVersion);
        $this->collectAgentsByBackupType($agentsByAgentVersion);
    }

    /**
     * This splits out the active direct to cloud agent objects by agent version
     * to avoid repeating work for each metric.
     *
     * @param MetricsContext $context
     *
     * @return array
     */
    private function segmentAgentsByAgentVersion(MetricsContext $context): array
    {
        $agentsByAgentVersion = [];

        foreach ($context->getActiveDirectToCloudAgents() as $agent) {
            $agentVersion = $agent->getDriver()->getAgentVersion() ?: self::UNKNOWN_AGENT_VERSION;

            if (!isset($agentsByAgentVersion[$agentVersion])) {
                $agentsByAgentVersion[$agentVersion] = [];
            }

            $agentsByAgentVersion[$agentVersion][] = $agent;
        }

        return $agentsByAgentVersion;
    }

    /**
     * Splits the number of eligible agents by backup type
     *
     * @param array $agents
     * @return array
     */
    private function segmentAgentsByBackupType(array $agents): array
    {
        $agentsByBackupType = [];
        foreach ($agents as $agent) {
            $backupType = $this->getBackupType($agent);
            if (!isset($agentsByBackupType[$backupType])) {
                $agentsByBackupType[$backupType] = [];
            }
            $agentsByBackupType[$backupType][] = $agent;
        }

        return $agentsByBackupType;
    }

    /**
     * Collect number of active agents (agents with a checkin within the past 168 hours)
     *
     * @param array $agentsByAgentVersion
     * @return void
     */
    private function collectNumberOfActiveAgents(array $agentsByAgentVersion)
    {
        foreach (array_keys($agentsByAgentVersion) as $agentVersion) {
            $tags = [
                'agent_version' => $agentVersion
            ];

            $this->collector->measure(
                Metrics::STATISTIC_DTC_ACTIVE_AGENTS,
                count($agentsByAgentVersion[$agentVersion]),
                $tags
            );
        }
    }

    /**
     * Collect checkin related metrics.
     *
     * @param array $agentsByAgentVersion
     * @return void
     */
    private function collectCheckinMetrics(array $agentsByAgentVersion)
    {
        $now = $this->dateTimeService->getTime();
        $lastDay = $now - DateTimeService::SECONDS_PER_DAY;

        /** @var Agent[] $agents */
        foreach ($agentsByAgentVersion as $agentVersion => $agents) {
            $tags = [
                'agent_version' => $agentVersion
            ];

            $agentsWithCheckinInPastDay = 0;
            foreach ($agents as $agent) {
                $lastCheckin = $agent->getLocal()->getLastCheckin() ?? 0;
                if ($lastCheckin > $lastDay) {
                    $agentsWithCheckinInPastDay++;
                }
            }

            $this->collector->measure(Metrics::STATISTIC_DTC_AGENT_HAS_CHECKIN_IN_LAST_DAY, $agentsWithCheckinInPastDay, $tags);
        }
    }

    /**
     * Collect recovery points metrics, including total recovery points.
     *
     * @param array $agentsByAgentVersion
     * @return void
     */
    private function collectRecoveryPointsMetrics(array $agentsByAgentVersion)
    {
        $now = $this->dateTimeService->getTime();
        $lastDay = $now - DateTimeService::SECONDS_PER_DAY;
        $lastWeek = $now - DateTimeService::SECONDS_PER_WEEK;

        /** @var Agent[] $agents */
        foreach ($agentsByAgentVersion as $agentVersion => $agents) {
            $tags = [
                'agent_version' => $agentVersion
            ];

            $agentsWithBackupInPastDay = 0;
            $agentsWithBackupInPastWeek = 0;
            $agentsWithBackupEver = 0;
            $recoveryPointsInLastDay = 0;
            $recoveryPointsInLastWeek = 0;

            foreach ($agents as $agent) {
                if ($agent->getLocal()->getRecoveryPoints()->size() > 0) {
                    $agentsWithBackupEver++;
                }
                $pointsInPastDay = count($agent->getLocal()->getRecoveryPoints()->getNewerThan($lastDay));
                $pointsInPastWeek = count($agent->getLocal()->getRecoveryPoints()->getNewerThan($lastWeek));

                $recoveryPointsInLastDay += $pointsInPastDay;
                $recoveryPointsInLastWeek += $pointsInPastWeek;

                if ($pointsInPastDay > 0) {
                    $agentsWithBackupInPastDay++;
                }
                if ($pointsInPastWeek > 0) {
                    $agentsWithBackupInPastWeek++;
                }
            }

            $this->collector->measure(Metrics::STATISTIC_DTC_AGENT_BACKUPS_IN_LAST_DAY, $recoveryPointsInLastDay, $tags);
            $this->collector->measure(Metrics::STATISTIC_DTC_AGENT_BACKUPS_IN_LAST_WEEK, $recoveryPointsInLastWeek, $tags);
            $this->collector->measure(Metrics::STATISTIC_DTC_AGENT_HAS_BACKUP_IN_LAST_DAY, $agentsWithBackupInPastDay, $tags);
            $this->collector->measure(Metrics::STATISTIC_DTC_AGENT_HAS_BACKUP_IN_LAST_WEEK, $agentsWithBackupInPastWeek, $tags);
            $this->collector->measure(Metrics::STATISTIC_DTC_AGENT_HAS_BACKUP, $agentsWithBackupEver, $tags);
        }
    }

    /**
     * Answers the question of:
     * "Of the set of all of the active agents' most recent screenshot attempts, how many were successful?"
     *
     * We prefer to use the local recovery points over querying RecoveryPointInfoService because the former is an
     * order of magnitude faster to run.
     *
     * @param array $agentsByAgentVersion
     */
    private function collectActiveAgentsWithSuccessfulVerification(array $agentsByAgentVersion)
    {
        foreach ($agentsByAgentVersion as $agentVersion => $agents) {
            $tags = [
                'agent_version' => $agentVersion
            ];

            $agentsWithSuccessfulScreenshot = 0;

            // this block gets all the LOCAL recovery points for the agent, sorts that list by youngest to oldest, and
            // then zooms through the list looking for the first instance of a completed screenshot verification. If
            // the verification succeeded, a counter is incremented, else the loop is broken out of and moves on to
            // the next agent.

            /** @var Agent $agent*/
            foreach ($agents as $agent) {
                $recoveryPoints = $agent->getLocal()->getRecoveryPoints()->getAll();
                krsort($recoveryPoints);
                foreach ($recoveryPoints as $recoveryPoint) {
                    $results = $recoveryPoint->getVerificationScreenshotResult();

                    // if verification is null we haven't run one for this point so continue
                    if ($results !== null) {
                        if ($results->isSuccess()) {
                            $agentsWithSuccessfulScreenshot++;
                        }

                        break;
                    }
                }
            }
            $this->collector->measure(Metrics::STATISTIC_DTC_AGENT_LATEST_SCREENSHOT_SUCCESS, $agentsWithSuccessfulScreenshot, $tags);
        }
    }

    /**
     * How many active agents are there segmented by agent version and backup type
     *
     * Could possibly be combined with or replace collectNumberOfActiveAgents() because
     * this simply further refines the number of active agents but that metric has already
     * been established.
     *
     * @param array $agentsByAgentVersion
     */
    private function collectAgentsByBackupType(array $agentsByAgentVersion)
    {
        foreach (array_keys($agentsByAgentVersion) as $specificAgentVersion) {
            $agentsByBackupType = $this->segmentAgentsByBackupType($agentsByAgentVersion[$specificAgentVersion]);
            foreach (array_keys($agentsByBackupType) as $specificBackupType) {
                $tags = [
                    'agent_version' => $specificAgentVersion,
                    'backup_type' => $specificBackupType
                ];

                $this->collector->measure(
                    Metrics::STATISTIC_DTC_ACTIVE_BACKUP_TYPES,
                    count($agentsByBackupType[$specificBackupType]),
                    $tags
                );
            }
        }
    }

    /**
     * Forces backup type into a set of allowable values.
     *
     * @param Agent $agent
     * @return string
     */
    private function getBackupType(Agent $agent): string
    {
        $backupManager = $this->backupManagerFactory->create($agent);
        $normalized = strtolower($backupManager->getInfo()->getStatus()->getBackupType());
        return in_array($normalized, self::BACKUP_TYPES_ALLOWED) ? $normalized : self::BACKUP_TYPE_UNKNOWN;
    }
}
