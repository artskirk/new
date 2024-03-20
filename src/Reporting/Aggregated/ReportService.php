<?php

namespace Datto\Reporting\Aggregated;

use Datto\Asset\Agent\Agent;
use Datto\Reporting\Screenshots;
use Datto\Reporting\Snapshots;
use Datto\Screenshot\ScreenshotFileRepository;
use Datto\Resource\DateTimeService;

/**
 * Service for aggregating backup and screenshot reports.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class ReportService
{
    const TIMEFRAME_HOUR = 'hour';
    const TIMEFRAME_DAY = 'day';
    const TIMEFRAME_WEEK = 'week';
    const TIMEFRAME_MONTH = 'month';
    const TIMEFRAME_QUARTER = 'quarter';
    const TIMEFRAME_YEAR = 'year';
    const TIMEFRAMES = [
        self::TIMEFRAME_HOUR,
        self::TIMEFRAME_DAY,
        self::TIMEFRAME_WEEK,
        self::TIMEFRAME_MONTH,
        self::TIMEFRAME_QUARTER,
        self::TIMEFRAME_YEAR
    ];

    const TIMEFRAME_INTERVAL_MAP = [
        self::TIMEFRAME_HOUR => DateTimeService::PAST_HOUR,
        self::TIMEFRAME_DAY => DateTimeService::PAST_DAY,
        self::TIMEFRAME_WEEK => DateTimeService::PAST_WEEK,
        self::TIMEFRAME_MONTH => DateTimeService::PAST_MONTH,
        self::TIMEFRAME_QUARTER => DateTimeService::PAST_QUARTER,
        self::TIMEFRAME_YEAR => DateTimeService::PAST_YEAR
    ];

    /** @var Snapshots */
    private $snapshots;

    /** @var Screenshots */
    private $screenshots;

    /** @var DateTimeService */
    private $dateService;

    /** @var ScreenshotFileRepository */
    private $screenshotFileRepository;

    /**
     * @param Snapshots $snapshots
     * @param Screenshots $screenshots
     * @param DateTimeService $dateService
     * @param ScreenshotFileRepository $screenshotFileRepository
     */
    public function __construct(
        Snapshots $snapshots,
        Screenshots $screenshots,
        DateTimeService $dateService,
        ScreenshotFileRepository $screenshotFileRepository
    ) {
        $this->snapshots = $snapshots;
        $this->screenshots = $screenshots;
        $this->dateService = $dateService;
        $this->screenshotFileRepository = $screenshotFileRepository;
    }

    /**
     * Get a summary of screenshots and backups for a given agent.
     *
     * @param Agent $agent
     * @param int|null $earliestEpoch
     * @return ReportSummary
     */
    public function getSummary(Agent $agent, int $earliestEpoch = null): ReportSummary
    {
        $report = $this->getReport($agent, $earliestEpoch);

        $scheduledBackupCount = count($report->getScheduled());
        $successfulScheduledBackupCount = count($report->getSuccessfulScheduled());
        $forcedBackupCount = count($report->getForced());
        $successfulForcedBackupCount = count($report->getSuccessfulForced());
        $screenshotCount = count($report->getScreenshots());
        $successfulScreenshotCount = count($report->getSuccessfulScreenshots());

        $latestBackupEpoch = $this->getLatestBackupEpoch($agent);
        $latestScreenshotEpoch = $this->getLatestSuccessfulScreenshotEpoch($agent);

        return new ReportSummary(
            $scheduledBackupCount,
            $successfulScheduledBackupCount,
            $forcedBackupCount,
            $successfulForcedBackupCount,
            $screenshotCount,
            $successfulScreenshotCount,
            $latestBackupEpoch,
            $latestScreenshotEpoch
        );
    }

    /**
     * Get a report for a given agent.
     *
     * @param Agent $agent
     * @param int|null $earliestEpoch
     * @return Report
     */
    public function getReport(Agent $agent, int $earliestEpoch = null): Report
    {
        return new Report(
            $agent,
            $this->getRecords($agent, $earliestEpoch)
        );
    }

    /**
     * Get records for a given agent.
     *
     * @param Agent $agent
     * @param int|null $earliestEpoch
     * @param string|null $type
     * @return array
     */
    public function getRecords(Agent $agent, int $earliestEpoch = null, string $type = null): array
    {
        if (isset($type)) {
            if ($type === Report::TYPE_FORCED || $type === Report::TYPE_SCHEDULED) {
                $records = $this->getBackups($agent, $earliestEpoch);
                $records = Report::filterByType($records, $type);
            } elseif ($type === Report::TYPE_SCREENSHOTS) {
                $records = $this->getScreenshots($agent, $earliestEpoch);
            } else {
                throw new \Exception('Unsupported record type: ' . $type);
            }
        } else {
            $records = array_merge(
                $this->getScreenshots($agent, $earliestEpoch),
                $this->getBackups($agent, $earliestEpoch)
            );
        }

        foreach ($records as &$record) {
            $record['keyName'] = $agent->getKeyName();
            $record['name'] = $agent->getFullyQualifiedDomainName() ?: $agent->getName();
        }

        return $records;
    }

    /**
     * Get records for a list of agents.
     *
     * @param array $agents
     * @param int $earliestEpoch
     * @param string|null $type
     * @return array
     */
    public function getAllRecords(array $agents, int $earliestEpoch, string $type = null): array
    {
        $records = [];

        foreach ($agents as $agent) {
            $records = array_merge($records, $this->getRecords($agent, $earliestEpoch, $type));
        }

        return $records;
    }

    /**
     * Convert a timeframe into an epoch.
     *
     * @param string $timeframe
     * @return int|null
     */
    public function getEpochFromTimeframe(string $timeframe)
    {
        $timeframe = strtolower($timeframe);

        if (!isset(self::TIMEFRAME_INTERVAL_MAP[$timeframe])) {
            throw new \Exception('Could not determine epoch from timeframe: ' . $timeframe);
        }

        return $this->dateService->stringToTime(self::TIMEFRAME_INTERVAL_MAP[$timeframe]);
    }

    /**
     * @param Agent $agent
     * @param int|null $earliestEpoch
     * @return array
     */
    private function getBackups(Agent $agent, int $earliestEpoch = null)
    {
        $records = $this->snapshots->getLogs($agent->getKeyName());

        if (isset($earliestEpoch)) {
            $records = Report::filterByEarliestEpoch($records, $earliestEpoch);
        }

        return $records;
    }

    /**
     * @param Agent $agent
     * @param int|null $earliestEpoch
     * @return array
     */
    private function getScreenshots(Agent $agent, int $earliestEpoch = null): array
    {
        $records = $this->normalizeScreenshots($this->screenshots->getLogs($agent->getKeyName()));

        if (isset($earliestEpoch)) {
            $records = Report::filterByEarliestEpoch($records, $earliestEpoch);
        }

        return $records;
    }

    /**
     * @param array $screenshots
     * @return array
     */
    private function normalizeScreenshots(array $screenshots): array
    {
        foreach ($screenshots as &$screenshot) {
            $screenshot['success'] = isset($screenshot['result']) && strtolower($screenshot['result']) === 'success';
            $screenshot['start_time'] = (int)$screenshot['start_time'];
        }
        unset($screenshot);

        return $screenshots;
    }

    /**
     * @param Agent $agent
     * @return int|null
     */
    private function getLatestBackupEpoch(Agent $agent)
    {
        $latestRecoveryPoint = $agent->getLocal()->getRecoveryPoints()->getLast();
        $latestBackupEpoch = $latestRecoveryPoint ? $latestRecoveryPoint->getEpoch() : null;

        return $latestBackupEpoch;
    }

    /**
     * @param Agent $agent
     * @return int|null
     */
    private function getLatestSuccessfulScreenshotEpoch(Agent $agent)
    {
        $screenshotFiles = $this->screenshotFileRepository->getAllByKeyName($agent->getKeyName());

        $latestScreenshotEpoch = 0;

        foreach ($screenshotFiles as $screenshotFile) {
            if ($screenshotFile->getExtension() !== ScreenshotFileRepository::EXTENSION_JPG) {
                continue;
            }

            if ($screenshotFile->getSnapshotEpoch() > $latestScreenshotEpoch) {
                $latestScreenshotEpoch = $screenshotFile->getSnapshotEpoch();
            }
        }

        return $latestScreenshotEpoch ?: null;
    }
}
