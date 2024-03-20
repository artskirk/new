<?php
namespace Datto\Verification\Notification;

use Datto\Feature\FeatureService;
use Datto\Service\Alert\AlertService;
use Datto\Util\Email\EmailService;
use Datto\Util\Email\Generator\ScreenshotEmailGenerator;
use Datto\Common\Utility\Filesystem;

/**
 * EmailNotification sends email notifications for verification results.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 * @author Matt Coleman <mcoleman@datto.com>
 * @author Matt Cheman <mcheman@datto.com>
 */
class EmailNotification extends VerificationNotification
{
    /** @var Filesystem */
    private $filesystem;

    /** @var EmailService */
    private $emailService;

    /** @var ScreenshotEmailGenerator */
    private $screenshotEmailGenerator;

    /** @var AlertService */
    private $alertService;

    private $featureService;

    public function __construct(
        Filesystem $filesystem,
        EmailService $emailService,
        ScreenshotEmailGenerator $screenshotEmailGenerator,
        AlertService $alertService,
        FeatureService $featureService
    ) {
        $this->filesystem = $filesystem;
        $this->emailService = $emailService;
        $this->screenshotEmailGenerator = $screenshotEmailGenerator;
        $this->alertService = $alertService;
        $this->featureService = $featureService;
    }

    public function commit()
    {
        $assetKey = $this->verificationResults->getAgent()->getKeyName();
        $snapshotEpoch = $this->verificationResults->getSnapshotEpoch();
        $screenshotSuccess = $this->verificationResults->getScreenshotSuccessful();
        $scriptSuccess = $this->verificationResults->getScriptSuccess();
        $screenshotFile = $this->verificationResults->getScreenshotFile();
        $screenshotAnalysis = $this->verificationResults->getScreenshotAnalysis();
        $osUpdatePending = $this->verificationResults->getOsUpdatePending();

        if ($this->filesystem->exists($screenshotFile)) {
            $overallSuccess = $screenshotSuccess
                && $scriptSuccess
                && $screenshotAnalysis === VerificationResults::SCREENSHOT_ANALYSIS_NO_FAILURE;

            $this->alertService->sendScreenshotAlert(
                $assetKey,
                $screenshotFile,
                $snapshotEpoch,
                $overallSuccess
            );
            $email = $this->screenshotEmailGenerator->generate(
                $assetKey,
                $snapshotEpoch,
                $overallSuccess,
                $scriptSuccess,
                $osUpdatePending
            );
            $this->emailService->sendEmail($email);
        } elseif ($this->featureService->isSupported(FeatureService::FEATURE_SKIP_VERIFICATION) && $osUpdatePending) {
            $email = $this->screenshotEmailGenerator->generate(
                $assetKey,
                $snapshotEpoch,
                false,
                false,
                $osUpdatePending,
                false,
                true
            );
            $this->emailService->sendEmail($email);
        }
        // todo send email if failure?
    }
}
