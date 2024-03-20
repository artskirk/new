<?php

namespace Datto\Reporting\Aggregated;

use Datto\Asset\Agent\Agent;

/**
 * Collection of backup and screenshot reports, with useful methods for filtering.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class Report
{
    const TYPE_SCHEDULED = 'scheduled';
    const TYPE_FORCED = 'forced';
    const TYPE_SCREENSHOTS = 'screenshots';

    /** @var Agent */
    private $agent;

    /** @var array */
    private $records;

    /**
     * @param Agent $agent
     * @param array $records
     */
    public function __construct(
        Agent $agent,
        array $records
    ) {
        $this->agent = $agent;
        $this->records = $records;
    }

    /**
     * @return Agent
     */
    public function getAgent(): Agent
    {
        return $this->agent;
    }

    /**
     * @return array
     */
    public function getAll(): array
    {
        return $this->records;
    }

    /**
     * @return array
     */
    public function getScheduled(): array
    {
        return self::filterByType($this->records, self::TYPE_SCHEDULED);
    }

    /**
     * @return array
     */
    public function getSuccessfulScheduled(): array
    {
        return self::filterBySuccess($this->getScheduled(), true);
    }

    /**
     * @return array
     */
    public function getForced(): array
    {
        return self::filterByType($this->records, self::TYPE_FORCED);
    }

    /**
     * @return array
     */
    public function getSuccessfulForced(): array
    {
        return self::filterBySuccess($this->getForced(), true);
    }

    /**
     * @return array
     */
    public function getScreenshots(): array
    {
        return self::filterByType($this->records, self::TYPE_SCREENSHOTS);
    }

    /**
     * @return array
     */
    public function getSuccessfulScreenshots(): array
    {
        return self::filterBySuccess($this->getScreenshots(), true);
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        return [
            'scheduled' => $this->getScheduled(),
            'forced' => $this->getForced(),
            'screenshots' => $this->getScreenshots()
        ];
    }

    /**
     * Filter records based on success.
     *
     * @param array $reports
     * @param bool $success
     * @return array
     */
    public static function filterBySuccess(array $reports, bool $success): array
    {
        $filteredReports = [];

        foreach ($reports as $report) {
            $isMatch = isset($report['success']) && $report['success'] === $success;

            if ($isMatch) {
                $filteredReports[] = $report;
            }
        }

        return $filteredReports;
    }

    /**
     * Filter records based on type.
     *
     * @param array $records
     * @param string $type
     * @return array
     */
    public static function filterByType(array $records, string $type): array
    {
        $filteredReports = [];

        foreach ($records as $record) {
            $isMatch = isset($record['type']) && strtolower($record['type']) === $type;

            if ($isMatch) {
                $filteredReports[] = $record;
            }
        }

        return $filteredReports;
    }

    /**
     * Filter records based on start time.
     *
     * @param array $records
     * @param int $earliestEpoch
     * @return array
     */
    public static function filterByEarliestEpoch(array $records, int $earliestEpoch): array
    {
        $filteredRecords = [];

        foreach ($records as $record) {
            $isMatch = isset($record['start_time']) && $record['start_time'] > $earliestEpoch;

            if ($isMatch) {
                $filteredRecords[] = $record;
            }
        }

        return $filteredRecords;
    }
}
