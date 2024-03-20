<?php

namespace Datto\Verification\Notification;

use Datto\Asset\Agent\Agent;
use Datto\Verification\NotificationContext;
use Datto\Verification\Stages\TakeScreenshot;
use Datto\Verification\VerificationContext;
use Datto\Verification\VerificationResultType;

/**
 * Verification results contains the aggregated results of the verification stages for use
 * in the notifications.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 * @author Matt Coleman <mcoleman@datto.com>
 * @author Matt Cheman <mcheman@datto.com>
 */
class VerificationResults
{
    const VER_STAGE_SCREENSHOT = 'TakeScreenshot';
    const VER_STAGE_SCRIPT = 'RunScripts';

    /** Indicates that the screenshot stage was successful */
    const SCREENSHOT_PROCESS_SUCCESS = null;

    /** Indicates that no failure was found during screenshot analysis */
    const SCREENSHOT_ANALYSIS_NO_FAILURE = null;

    /** @var VerificationContext Context used by all notifications for verification. */
    protected $verificationContext;

    /** @var NotificationContext Context used by all notifications. */
    protected $notificationContext;

    /** @var Agent Agent that was verified */
    protected $agent;

    /** @var integer Epoch time of the snapshot */
    protected $snapshotEpoch;

    /** @var boolean True if the lakitu ready step succeeded */
    protected $readyStateDetected;

    /** @var string Full path of the screenshot file */
    protected $screenshotFile;

    /** @var boolean True if the take screenshot step succeeded */
    protected $screenshotSuccess;

    /**
     * @var string|null Error message from the take screenshot step
     *
     * `self::SCREENSHOT_PROCESS_SUCCESS` if no errors occurred.
     */
    protected $screenshotError;

    /**
     * @var string|null Results from the screenshot analysis
     *
     * `self::SCREENSHOT_ANALYSIS_NO_FAILURE` if no failure states were detected.
     */
    protected $screenshotAnalysis;

    /** @var boolean True if the run scripts step succeeded */
    protected $scriptSuccess;

    /** @var VerificationResultType The worst result type associated with these results */
    protected $worstResultType;

    public function __construct(
        VerificationContext $verificationContext,
        NotificationContext $notificationContext
    ) {
        $this->verificationContext = $verificationContext;
        $this->notificationContext = $notificationContext;
    }

    /**
     * Generate the verification stage results.
     * @param VerificationResultType $worstResult The worst result type that was found during the verification process
     */
    public function gatherResults(VerificationResultType $worstResult)
    {
        $this->worstResultType = $worstResult;

        $this->gatherPreflightStage();
        $this->gatherScreenshotStage();
        $this->gatherScriptStage();
    }

    public function getVerificationContext(): VerificationContext
    {
        return $this->verificationContext;
    }

    public function getAgent(): Agent
    {
        return $this->agent;
    }

    public function getSnapshotEpoch(): int
    {
        return $this->snapshotEpoch;
    }

    public function getScreenshotFile(): string
    {
        return $this->screenshotFile;
    }

    public function getScreenshotSuccessful(): bool
    {
        return $this->screenshotSuccess;
    }

    /**
     * @return string|null
     */
    public function getScreenshotError()
    {
        return $this->screenshotError;
    }

    /**
     * @return string|null
     */
    public function getScreenshotAnalysis()
    {
        return $this->screenshotAnalysis;
    }

    public function getScriptSuccess(): bool
    {
        return $this->scriptSuccess;
    }

    /**
     * @see VerificationContext::getOsUpdatePending()
     */
    public function getOsUpdatePending(): bool
    {
        return $this->verificationContext->getOsUpdatePending();
    }

    /**
     * Set results from the preflight stage
     */
    private function gatherPreflightStage()
    {
        $this->agent = $this->verificationContext->getAgent();
        $this->snapshotEpoch = $this->verificationContext->getSnapshotEpoch();
    }

    /**
     * Set results from the screenshot stage
     */
    private function gatherScreenshotStage()
    {
        $this->screenshotFile = $this->verificationContext->getScreenshotImagePath();
        if ($this->notificationContext->exists(static::VER_STAGE_SCREENSHOT)) {
            $this->screenshotSuccess = $this->notificationContext
                ->getResults(static::VER_STAGE_SCREENSHOT)
                ->didSucceed();
            $this->screenshotError = $this->notificationContext
                ->getResults(static::VER_STAGE_SCREENSHOT)
                ->getErrorMessage();
            $takeScreenshotDetails = $this->notificationContext
                ->getResults(static::VER_STAGE_SCREENSHOT)
                ->getDetails();
            $this->screenshotAnalysis = $takeScreenshotDetails
                ->getDetail(TakeScreenshot::DETAILS_ANALYSIS_RESULT);
        } else {
            $this->screenshotSuccess = false;
            $this->screenshotError = 'Failed to commit TakeScreenshot, cannot gather results';
            $this->screenshotAnalysis = self::SCREENSHOT_ANALYSIS_NO_FAILURE;
        }
    }

    /**
     * Set results from the script stage
     */
    private function gatherScriptStage()
    {
        if ($this->notificationContext->exists(static::VER_STAGE_SCRIPT)) {
            $this->scriptSuccess = $this->notificationContext->getResults(static::VER_STAGE_SCRIPT)->didSucceed();
        } else {
            $this->scriptSuccess = true;
        }
    }
}
