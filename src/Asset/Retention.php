<?php

namespace Datto\Asset;

use Datto\Billing;

/**
 * Model for the offsite or local retention setting of an agent or share
 * @package Datto\Config\Settings
 */
class Retention
{
    /** @const int NEVER_DELETE indicates backups that should never be deleted */
    const NEVER_DELETE = 240000;

    /** @const int default daily retention */
    const DEFAULT_DAILY = 168;

    /** @const int default weekly retention */
    const DEFAULT_WEEKLY = 336;

    /** @const int default monthly retention */
    const DEFAULT_MONTHLY = 1080;

    /** @const int default maximum retention */
    const DEFAULT_MAXIMUM = 2232;

    /** @const int default free daily retention */
    const FREE_DAILY = self::DEFAULT_DAILY;

    /** @const int default free weekly retention */
    const FREE_WEEKLY = self::DEFAULT_WEEKLY;

    /** @const int default free monthly retention */
    const FREE_MONTHLY = 744;

    /** @const int default free monthly retention */
    const FREE_MAXIMUM = self::FREE_MONTHLY;

    /** @const int time-based daily retention */
    const TIME_BASED_DAILY = 168;

    /** @const int time-based weekly retention */
    const TIME_BASED_WEEKLY = 336;

    /** @const int time-based monthly retention */
    const TIME_BASED_MONTHLY = 1096;

    /** time-based maximum retention is defined by the service plan
        See Billing\Service getTimeBasedRetentionYears method */

    /** @const int Infinite Cloud daily retention */
    const INFINITE_DAILY = 168;

    /** @const int Infinite Cloud weekly retention */
    const INFINITE_WEEKLY =  336;

    /** @const int Infinite Cloud monthly retention */
    const INFINITE_MONTHLY = 1096;

    /** @const int Infinite Cloud maximum retention */
    const INFINITE_MAXIMUM = self::NEVER_DELETE;

    /** @const int Infinite Cloud maximum retention after grace period expiration*/
    const INFINITE_MAXIMUM_AFTER_GRACE = 1488;

    /** @const int Azure local daily retention */
    const AZURE_LOCAL_DAILY = 168;

    /** @const int Azure local weekly retention */
    const AZURE_LOCAL_WEEKLY =  168;

    /** @const int Azure local monthly retention */
    const AZURE_LOCAL_MONTHLY = 168;

    /** @const int Azure local maximum retention */
    const AZURE_LOCAL_MAXIMUM = 168;

    /** @const int One year of time based retention. */
    const TIME_BASED_RETENTION_YEAR = 8760;

    /**
     * @const int One year time based retention accounting for leap year.
     * TODO: leap year calculation is flawed and must be fixed before February 2024
     */
    const TIME_BASED_RETENTION_YEAR_WITH_LEAP = 8766;

    /** @const int Keep all snapshots around for 90 days */
    const SECONDARY_REPLICATION_HOURS = 2160;

    // Allowed values for validation
    private static $allowedValuesDaily = array(24, 48, 72, 96, 120, 144, 168, 336, 504, 744, self::NEVER_DELETE);
    private static $allowedValuesWeekly = array(168, 336, 504, 672, 1008, 1344, 1680, 2184, 4368, self::NEVER_DELETE);
    private static $allowedValuesMonthly = array(731, 1096, 1461, 2192, 2922, 4383, 5844, 7305, 8766, 17532,
        self::NEVER_DELETE);
    private static $allowedValuesMaximum = array(168, 336, 744, 1488, 2232, 2976, 4464, 6696, 8760, 17520, 26280, 35064,
        43830, 52596, 61362, self::NEVER_DELETE);

    private int $daily;
    private int $weekly;
    private int $monthly;
    private int $maximum;

    /**
     * @param int $daily retention duration for daily snapshots
     * @param int $weekly retention duration for weekly snapshots
     * @param int $monthly retention duration for monthly snapshots
     * @param int $maximum maximum retention duration for any snapshot
     */
    public function __construct(int $daily, int $weekly, int $monthly, int $maximum)
    {
        $this->daily = $daily;
        $this->weekly = $weekly;
        $this->monthly = $monthly;
        $this->maximum = $maximum;
    }

    /**
     * @return Retention retention object with default retention durations
     */
    public static function createDefault()
    {
        return new self(
            self::DEFAULT_DAILY,
            self::DEFAULT_WEEKLY,
            self::DEFAULT_MONTHLY,
            self::DEFAULT_MAXIMUM
        );
    }

    /**
     * Create a default instance for Infinite Cloud Retention (ICR).
     */
    public static function createDefaultInfinite(Billing\Service $billingService): Retention
    {
        $gracePeriodExpired = $billingService->hasInfiniteRetentionGracePeriodExpired();
        return new self(
            self::INFINITE_DAILY,
            self::INFINITE_WEEKLY,
            self::INFINITE_MONTHLY,
            $gracePeriodExpired ? self::INFINITE_MAXIMUM_AFTER_GRACE : self::INFINITE_MAXIMUM
        );
    }

    /**
     * Create an instance of Infinite Cloud Retention (ICR) without taking into account the grace period.
     */
    public static function createInfinite(): Retention
    {
        return new self(
            self::INFINITE_DAILY,
            self::INFINITE_WEEKLY,
            self::INFINITE_MONTHLY,
            self::INFINITE_MAXIMUM
        );
    }

    /**
     * Create a default instance for azure local retention.
     */
    public static function createDefaultAzureLocal(): Retention
    {
        return new self(
            self::AZURE_LOCAL_DAILY,
            self::AZURE_LOCAL_WEEKLY,
            self::AZURE_LOCAL_MONTHLY,
            self::AZURE_LOCAL_MAXIMUM
        );
    }

    /**
     * Create a default instance for Time Based Retention (TBR).
     *
     * @param Billing\Service $billingService
     * @return Retention
     */
    public static function createDefaultTimeBased(Billing\Service $billingService)
    {
        $timeBasedRetentionYears = $billingService->getTimeBasedRetentionYears();
        return self::createTimeBased($timeBasedRetentionYears);
    }

    /**
     * @param int $timeBasedRetentionYears
     * @return Retention
     */
    public static function createTimeBased(int $timeBasedRetentionYears): Retention
    {
        if ($timeBasedRetentionYears <= 3) {
            $maximum = $timeBasedRetentionYears * static::TIME_BASED_RETENTION_YEAR;
        } else {
            $maximum = $timeBasedRetentionYears * static::TIME_BASED_RETENTION_YEAR_WITH_LEAP;
        }

        return new self(
            self::TIME_BASED_DAILY,
            self::TIME_BASED_WEEKLY,
            self::TIME_BASED_MONTHLY,
            $maximum
        );
    }

    /**
     * Creates a default instance for use on primary replication cloud devices.
     *
     * @return Retention
     */
    public static function createDefaultSecondary()
    {
        return new self(
            self::SECONDARY_REPLICATION_HOURS,
            self::SECONDARY_REPLICATION_HOURS,
            self::SECONDARY_REPLICATION_HOURS,
            self::SECONDARY_REPLICATION_HOURS
        );
    }

    /**
     * Create an applicable default instance based on information provided by the billing service.
     */
    public static function createApplicableDefault(Billing\Service $billingService): Retention
    {
        if ($billingService->isInfiniteRetention()) {
            return self::createDefaultInfinite($billingService);
        }

        if ($billingService->isTimeBasedRetention()) {
            return self::createDefaultTimeBased($billingService);
        }

        return self::createDefault();
    }

    /** @return bool true if settings match Infinite Cloud Retention values */
    public function isInfiniteRetention()
    {
        return ($this->daily === self::INFINITE_DAILY &&
                $this->weekly === self::INFINITE_WEEKLY &&
                $this->monthly === self::INFINITE_MONTHLY &&
                $this->maximum === self::INFINITE_MAXIMUM);
    }

    /** @return bool true if settings match Infinite Cloud Retention after grace period values */
    public function isInfiniteRetentionExpired()
    {
        return ($this->daily === self::INFINITE_DAILY &&
                $this->weekly === self::INFINITE_WEEKLY &&
                $this->monthly === self::INFINITE_MONTHLY &&
                $this->maximum === self::INFINITE_MAXIMUM_AFTER_GRACE);
    }

    /** @return int hours to retain daily backups */
    public function getDaily()
    {
        return $this->daily;
    }

    /** @return int hours to retain weekly backups */
    public function getWeekly()
    {
        return $this->weekly;
    }

    /** @return int hours to retain monthly backups */
    public function getMonthly()
    {
        return $this->monthly;
    }

    /** @return int maximum duration to retain a backup, in hours */
    public function getMaximum()
    {
        return $this->maximum;
    }

    /**
     * @param Retention $other
     * @return bool
     */
    public function equals(Retention $other): bool
    {
        return $this->getDaily() === $other->getDaily()
            && $this->getWeekly() === $other->getWeekly()
            && $this->getMonthly() === $other->getMonthly()
            && $this->getMaximum() === $other->getMaximum();
    }
}
