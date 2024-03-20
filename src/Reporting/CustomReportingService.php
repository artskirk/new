<?php

namespace Datto\Reporting;

use Datto\Alert\AlertManager;
use Datto\Asset\AssetService;
use Datto\Asset\Agent\AgentService;
use Datto\Log\LoggerFactory;
use Datto\Util\Email\Email;
use Datto\Util\Email\EmailService;
use Datto\Asset\Asset;
use Datto\Asset\Agent\Agent;
use Datto\Util\Email\Generator\AlertSummaryReportGenerator;
use Datto\Util\Email\Generator\LogReportEmailGenerator;
use Datto\Util\Email\Generator\WeeklyReportEmailGenerator;
use Datto\Util\RetryHandler;
use Exception;
use Throwable;

/**
 * CustomReportingService handles the collection of subject strings and message arrays for
 * mailing of custom log reports (for any asset) and weekly reports (for any agent)
 *
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class CustomReportingService
{
    const SEND_EMAIL_ATTEMPTS = 3;
    const MINIMUM_RETRY_WAIT_SECONDS = 30;
    const MAXIMUM_RETRY_WAIT_SECONDS = 120;

    /** @var EmailService */
    private $emailService;

    /** @var WeeklyReportEmailGenerator */
    private $weeklyReportEmailGenerator;

    /** @var AlertSummaryReportGenerator */
    private $alertSummaryReportGenerator;

    /** @var LogReportEmailGenerator */
    private $logReportGenerator;

    /** @var AssetService */
    private $assetService;

    /** @var AgentService */
    private $agentService;

    /** @var AlertManager */
    private $alertManager;

    /** @var $retryHandler */
    private $retryHandler;

    /** @var LoggerFactory */
    private $loggerFactory;

    /**
     * @param EmailService $emailService
     * @param WeeklyReportEmailGenerator $weeklyReportEmailGenerator
     * @param AlertSummaryReportGenerator $alertSummaryReportGenerator
     * @param LogReportEmailGenerator $logReportGenerator
     * @param AssetService $assetService
     * @param AgentService $agentService
     * @param AlertManager $alertManager
     * @param RetryHandler $retryHandler
     * @param LoggerFactory $loggerFactory
     */
    public function __construct(
        EmailService $emailService,
        WeeklyReportEmailGenerator $weeklyReportEmailGenerator,
        AlertSummaryReportGenerator $alertSummaryReportGenerator,
        LogReportEmailGenerator $logReportGenerator,
        AssetService $assetService,
        AgentService $agentService,
        AlertManager $alertManager,
        RetryHandler $retryHandler,
        LoggerFactory $loggerFactory
    ) {
        $this->emailService = $emailService;
        $this->weeklyReportEmailGenerator = $weeklyReportEmailGenerator;
        $this->alertSummaryReportGenerator = $alertSummaryReportGenerator;
        $this->logReportGenerator = $logReportGenerator;
        $this->assetService = $assetService;
        $this->agentService = $agentService;
        $this->alertManager = $alertManager;
        $this->retryHandler = $retryHandler;
        $this->loggerFactory = $loggerFactory;
    }

    /**
     * Sends an email containing the Alert Summary for all assets to configured recipients
     */
    public function sendAlertSummaryReport()
    {
        if (!$this->alertManager->isAdvancedAlertingEnabled()) {
            return;
        }

        $logger = $this->loggerFactory->getDevice();
        $assets = $this->assetService->getAll();
        $emailAddresses = implode(',', $this->alertSummaryReportGenerator->getEmailAddresses($assets));

        // Don't even collect report if not configured to send critical emails
        if ($assets && $emailAddresses) {
            try {
                $email = $this->alertSummaryReportGenerator->generate($assets);
                $this->sendEmail($email);
            } catch (Throwable $throwable) {
                // Log and rethrow
                $logger->error('CRS0030 Failed to send alert summary report email', ['exception' => $throwable]);
                if (!is_null($throwable->getPrevious())) {
                    $logger->debug('CRS0031 Nested exception details', ['exception' => $throwable->getPrevious()]);
                }

                throw $throwable;
            }

            foreach ($assets as $asset) {
                $this->alertManager->clearAllAlerts($asset->getKeyName());
            }
        }
    }

    /**
     * Send weekly report for each agent that is not archived if configured to do so
     */
    public function sendAllWeeklyAgentReports()
    {
        foreach ($this->agentService->getAllActiveLocal() as $agent) {
            $logger = $this->loggerFactory->getAsset($agent->getKeyName());
            try {
                $this->sendWeeklyAgentReport($agent);
            } catch (Throwable $throwable) {
                // Log and continue to next agent
                $logger->error('CRS0010 Failed to send weekly agent report email', ['exception' => $throwable]);
                if (!is_null($throwable->getPrevious())) {
                    $logger->debug('CRS0011 Nested exception details', ['exception' => $throwable->getPrevious()]);
                }
            }
        }
    }

    /**
     * Iterate through all assets and send log reports if configured to do so
     */
    public function sendAllAssetLogsAndReports()
    {
        foreach ($this->assetService->getAllActiveLocal() as $asset) {
            $logger = $this->loggerFactory->getAsset($asset->getKeyName());
            try {
                $this->sendAssetLogsAndReport($asset);
            } catch (Throwable $throwable) {
                // Log and continue to next asset
                $logger->error('CRS0020 Failed to send asset log report email', ['exception' => $throwable]);
                if (!is_null($throwable->getPrevious())) {
                    $logger->debug('CRS0021 Nested exception details', ['exception' => $throwable->getPrevious()]);
                }
            }
        }
    }

    /**
     * Sends an email containing weekly digest on backups/screenshots for an agent to configured recipients
     *
     * @param Agent $agent
     */
    private function sendWeeklyAgentReport(Agent $agent)
    {
        $emailAddresses = implode(',', $agent->getEmailAddresses()->getWeekly());
        // Don't even collect report if not configured to send weekly emails
        if (!empty($emailAddresses)) {
            $email = $this->weeklyReportEmailGenerator->generate($agent);
            $this->sendEmail($email);
        }
    }

    /**
     * Sends an email containing last 500 asset log messages to configured recipients
     *
     * @param Asset $asset
     */
    private function sendAssetLogsAndReport(Asset $asset)
    {
        $emailAddresses = implode(',', $asset->getEmailAddresses()->getLog());
        // Don't even collect logs if not configured to send log reports
        if (!empty($emailAddresses)) {
            $email = $this->logReportGenerator->generate($asset);
            $this->sendEmail($email);
        }
    }

    /**
     * Send a given email with retry attempts.
     *
     * @param Email $email
     */
    private function sendEmail(Email $email)
    {
        $retryDelay = mt_rand(self::MINIMUM_RETRY_WAIT_SECONDS, self::MAXIMUM_RETRY_WAIT_SECONDS);
        $this->retryHandler->executeAllowRetry(
            function () use ($email) {
                $sent = $this->emailService->sendEmail($email);
                if (!$sent) {
                    throw new Exception("device-web failed to queue the email send request");
                }
            },
            self::SEND_EMAIL_ATTEMPTS,
            $retryDelay
        );
    }
}
