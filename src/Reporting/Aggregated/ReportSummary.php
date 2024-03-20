<?php

namespace Datto\Reporting\Aggregated;

/**
 * Summary of a backup and screenshot report.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class ReportSummary
{
    /** @var int */
    private $scheduledBackupCount;

    /** @var int */
    private $successfulScheduledBackupCount;

    /** @var int */
    private $forcedBackupCount;

    /** @var int */
    private $successfulForcedBackupCount;

    /** @var int */
    private $screenshotCount;

    /** @var int */
    private $successfulScreenshotCount;

    /** @var int|null */
    private $latestBackupEpoch;

    /** @var int|null */
    private $latestScreenshotEpoch;

    /**
     * @param int $scheduledBackupCount
     * @param int $successfulScheduledBackupCount
     * @param int $forcedBackupCount
     * @param int $successfulForcedBackupCount
     * @param int $screenshotCount
     * @param int $successfulScreenshotCount
     * @param int|null $latestBackupEpoch
     * @param int|null $latestScreenshotEpoch
     */
    public function __construct(
        int $scheduledBackupCount,
        int $successfulScheduledBackupCount,
        int $forcedBackupCount,
        int $successfulForcedBackupCount,
        int $screenshotCount,
        int $successfulScreenshotCount,
        int $latestBackupEpoch = null,
        int $latestScreenshotEpoch = null
    ) {
        $this->scheduledBackupCount = $scheduledBackupCount;
        $this->successfulScheduledBackupCount = $successfulScheduledBackupCount;
        $this->forcedBackupCount = $forcedBackupCount;
        $this->successfulForcedBackupCount = $successfulForcedBackupCount;
        $this->screenshotCount = $screenshotCount;
        $this->successfulScreenshotCount = $successfulScreenshotCount;
        $this->latestBackupEpoch = $latestBackupEpoch;
        $this->latestScreenshotEpoch = $latestScreenshotEpoch;
    }

    /**
     * @return int
     */
    public function getScheduledBackupCount(): int
    {
        return $this->scheduledBackupCount;
    }

    /**
     * @return int
     */
    public function getSuccessfulScheduledBackupCount(): int
    {
        return $this->successfulScheduledBackupCount;
    }

    /**
     * @return int
     */
    public function getForcedBackupCount(): int
    {
        return $this->forcedBackupCount;
    }

    /**
     * @return int
     */
    public function getSuccessfulForcedBackupCount(): int
    {
        return $this->successfulForcedBackupCount;
    }

    /**
     * @return int
     */
    public function getScreenshotCount(): int
    {
        return $this->screenshotCount;
    }

    /**
     * @return int
     */
    public function getSuccessfulScreenshotCount(): int
    {
        return $this->successfulScreenshotCount;
    }

    /**
     * @return int|null
     */
    public function getLatestBackupEpoch()
    {
        return $this->latestBackupEpoch;
    }

    /**
     * @return int|null
     */
    public function getLatestScreenshotEpoch()
    {
        return $this->latestScreenshotEpoch;
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        return [
            'scheduledBackupCount' => $this->scheduledBackupCount,
            'successfulScheduledBackupCount' => $this->successfulScheduledBackupCount,
            'forcedBackupCount' => $this->forcedBackupCount,
            'successfulForcedBackupCount' => $this->successfulForcedBackupCount,
            'screenshotCount' => $this->screenshotCount,
            'successfulScreenshotCount' => $this->successfulScreenshotCount,
            'latestBackupEpoch' => $this->latestBackupEpoch,
            'latestScreenshotEpoch' => $this->latestScreenshotEpoch
        ];
    }
}
