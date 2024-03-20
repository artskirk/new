<?php

namespace Datto\Asset;

use Datto\Verification\Notification\VerificationResults;

/**
 * Represents the result of screenshot verification on a recovery point.
 */
class VerificationScreenshotResult
{
    /** @var bool `true` if a screenshot was taken */
    private $hasScreenshot;

    /**
     * @var string|null Results from the screenshot analysis
     *
     * `VerificationResults::SCREENSHOT_ANALYSIS_NO_FAILURE` if no failure states were detected.
     */
    private $failureAnalysis;

    /** @var bool `true` if the verification was run and was a failure due to OS update pending */
    private $isOsUpdatePending;

    /**
     * @param bool $hasScreenshot
     * @param bool $isOsUpdatePending
     * @param string|null $failureAnalysis
     */
    public function __construct(
        bool $hasScreenshot,
        bool $isOsUpdatePending,
        string $failureAnalysis = null
    ) {
        $this->hasScreenshot = $hasScreenshot;
        $this->isOsUpdatePending = $isOsUpdatePending;
        $this->failureAnalysis = $failureAnalysis;
    }

    /** Check if the verification transaction captured a screenshot and no failure states were detected */
    public function isSuccess(): bool
    {
        return $this->hasScreenshot && $this->failureAnalysis === VerificationResults::SCREENSHOT_ANALYSIS_NO_FAILURE;
    }

    public function hasScreenshot(): bool
    {
        return $this->hasScreenshot;
    }

    /** @return string|null */
    public function getFailureAnalysis()
    {
        return $this->failureAnalysis;
    }

    public function isOsUpdatePending(): bool
    {
        return $this->isOsUpdatePending;
    }
}
