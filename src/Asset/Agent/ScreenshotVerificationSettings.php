<?php

namespace Datto\Asset\Agent;

use Datto\Verification\Application\ApplicationScriptManager;

/**
 * Class ScreenshotVerificationSettings: stores screenshot verification settings for an agent.
 *
 * @author Christopher R. Wicks <cwicks@datto.com>
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class ScreenshotVerificationSettings
{
    const DEFAULT_ERROR_TIME = 0;
    const DEFAULT_WAIT_TIME = 0;
    const DEFAULT_EXPECTED_APPLICATIONS = [];
    const DEFAULT_EXPECTED_SERVICES = [];

    const VALID_WAIT_TIMES = [0, 15, 30, 45, 60, 120, 180, 240, 300, 360, 420, 480, 540, 600, 900, 1200, 1800, 2700, 3600];

    /** @var int */
    private $errorTime;

    /** @var int */
    private $waitTime;

    /** @var string[] */
    protected $expectedApplications;

    /** @var string[] */
    protected $expectedServices;

    /**
     * ScreenshotVerificationSettings constructor.
     *
     * @param int $waitTime The additional amount of time (seconds) to wait after booting the snapshot before taking a screenshot
     * @param int $errorTime the amount of time (hours) to wait after a scheduled screenshot has not been taken before reporting an error
     * @param string[] $expectedApplications
     * @param string[] $expectedServices Expected service IDs
     */
    public function __construct(
        $waitTime = null,
        $errorTime = null,
        array $expectedApplications = null,
        array $expectedServices = null
    ) {
        $this->setWaitTime(($waitTime !== null) ? $waitTime : self::DEFAULT_WAIT_TIME);
        $this->errorTime = ($errorTime !== null) ? $errorTime : self::DEFAULT_ERROR_TIME;
        $this->expectedApplications = $expectedApplications ?: self::DEFAULT_EXPECTED_APPLICATIONS;
        $this->expectedServices = $expectedServices ?: self::DEFAULT_EXPECTED_SERVICES;
    }

    /**
     * @return int The additional amount of time (seconds) to wait after booting the snapshot before taking a screenshot
     */
    public function getWaitTime()
    {
        return $this->waitTime;
    }

    /**
     * @param int $time
     */
    public function setWaitTime($time): void
    {
        $this->waitTime = $time;
    }

    /**
     * @return int amount of time (hours) after a scheduled screenshot has not been taken before reporting an error
     */
    public function getErrorTime()
    {
        return $this->errorTime;
    }

    /**
     * @param int $hours
     */
    public function setErrorTime($hours): void
    {
        $this->errorTime = $hours;
    }

    /**
     * @param ScreenshotVerificationSettings $settings
     */
    public function copyFrom(ScreenshotVerificationSettings $settings): void
    {
        $this->setWaitTime($settings->getWaitTime());
        $this->setErrorTime($settings->getErrorTime());
    }

    /**
     * @return bool
     */
    public function hasExpectedApplications(): bool
    {
        return !empty($this->expectedApplications);
    }

    /**
     * List of expected applications to check during screenshot verification.
     *
     * @return string[]
     */
    public function getExpectedApplications(): array
    {
        return $this->expectedApplications;
    }

    /**
     * Returns whether the passed application specified by its key, is expected or not.
     *
     * @param string $applicationId example \Datto\Verification\Application\ApplicationScriptManager::APPLICATION_DHCP
     * @return bool
     */
    public function isApplicationExpected(string $applicationId): bool
    {
        return in_array(
            $applicationId,
            $this->expectedApplications
        );
    }

    /**
     * @param string[] $applicationIds
     */
    public function setExpectedApplications(array $applicationIds): void
    {
        $this->expectedApplications = array_intersect(
            $applicationIds,
            array_keys(ApplicationScriptManager::APPLICATION_SCRIPT_MAP)
        );
    }

    /**
     * @return bool
     */
    public function hasExpectedServices(): bool
    {
        return !empty($this->expectedServices);
    }

    /**
     * @return string[] List of expected service IDs
     */
    public function getExpectedServices(): array
    {
        return $this->expectedServices;
    }

    /**
     * @param string $serviceId
     * @return bool
     */
    public function isServiceExpected(string $serviceId): bool
    {
        return in_array(
            $serviceId,
            $this->expectedServices
        );
    }

    /**
     * @param string[] $serviceIds
     */
    public function setExpectedServices(array $serviceIds): void
    {
        $this->expectedServices = $serviceIds;
    }
}
