<?php

namespace Datto\Util\Email;

use Datto\Asset\AssetService;
use Datto\Log\LoggerFactory;
use Datto\Util\Email\Generator\CriticalEmailGenerator;
use Datto\Util\Email\Generator\LogReportEmailGenerator;
use Datto\Util\Email\Generator\ScreenshotEmailGenerator;
use Datto\Util\Email\Generator\WarningEmailGenerator;

/**
 * Class: Service to send test emails
 *
 * @author Jack Corrigan <jcorrigan@datto.com>
 */
class TestEmailService
{
    const TEST_MESSAGE = 'This is a test message.';

    /** @var CriticalEmailGenerator */
    private $criticalEmailGenerator;

    /** @var WarningEmailGenerator */
    private $warningEmailGenerator;

    /** @var ScreenshotEmailGenerator */
    private $screenshotEmailGenerator;

    /** @var LogReportEmailGenerator */
    private $logReportEmailGenerator;

    /** @var EmailService */
    private $emailService;

    /** @var AssetService */
    private $assetService;

    /**
     * @param CriticalEmailGenerator $criticalEmailGenerator
     * @param WarningEmailGenerator $warningEmailGenerator
     * @param ScreenshotEmailGenerator $screenshotEmailGenerator
     * @param LogReportEmailGenerator $logReportEmailGenerator
     * @param EmailService $emailService
     * @param AssetService $assetService
     */
    public function __construct(
        CriticalEmailGenerator $criticalEmailGenerator,
        WarningEmailGenerator $warningEmailGenerator,
        ScreenshotEmailGenerator $screenshotEmailGenerator,
        LogReportEmailGenerator $logReportEmailGenerator,
        EmailService $emailService,
        AssetService $assetService
    ) {
        $this->criticalEmailGenerator = $criticalEmailGenerator;
        $this->warningEmailGenerator = $warningEmailGenerator;
        $this->screenshotEmailGenerator = $screenshotEmailGenerator;
        $this->logReportEmailGenerator = $logReportEmailGenerator;
        $this->emailService = $emailService;
        $this->assetService = $assetService;
    }

    /**
     * @param string $assetKeyName
     */
    public function sendTestCritical(string $assetKeyName)
    {
        $email = $this->criticalEmailGenerator->generate($assetKeyName, self::TEST_MESSAGE);
        $this->emailService->sendEmail($email);

        $logger = LoggerFactory::getAssetLogger($assetKeyName);
        $logger->info('TES1022 Test critical email sent.');
    }

    /**
     * @param string $assetKeyName
     */
    public function sendTestWarning(string $assetKeyName)
    {
        $email = $this->warningEmailGenerator->generate($assetKeyName, self::TEST_MESSAGE);
        $this->emailService->sendEmail($email);

        $logger = LoggerFactory::getAssetLogger($assetKeyName);
        $logger->info('TES1024 Test warning (missed) alert email sent.');
    }

    /**
     * @param string $assetKeyName
     */
    public function sendTestScreenshots(string $assetKeyName)
    {
        $asset = $this->assetService->get($assetKeyName);
        $snapshot = $asset->getLocal()->getRecoveryPoints()->getLast()->getEpoch();

        $email = $this->screenshotEmailGenerator->generate($assetKeyName, $snapshot, true, true, true);
        $this->emailService->sendEmail($email);

        $logger = LoggerFactory::getAssetLogger($assetKeyName);
        $logger->info('TES1020 Test screenshot alert email sent.');
    }

    /**
     * @param string $assetKeyName
     */
    public function sendTestLogReport(string $assetKeyName)
    {
        $asset = $this->assetService->get($assetKeyName);
        //adding log item prior to email, so test message shows up in email
        $logger = LoggerFactory::getAssetLogger($assetKeyName);
        $logger->info('TES1023 Test log report email sent.');

        $email = $this->logReportEmailGenerator->generate($asset);
        $this->emailService->sendEmail($email);
    }
}
