<?php

namespace Datto\Verification;

use Datto\Connection\Libvirt\AbstractLibvirtConnection;
use Datto\Events\EventService;
use Datto\Events\VerificationEventFactory;
use Datto\Feature\FeatureService;
use Datto\Log\LoggerFactory;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Datto\Resource\DateTimeService;
use Datto\System\Transaction\Transaction;
use Datto\Verification\Notification\VerificationResults;
use Datto\Log\DeviceLoggerInterface;

/**
 * Class responsible for sending verification-related metrics and events
 */
class VerificationMonitoringService
{
    const METRIC_SCREENSHOT_RESULT_SUCCESS = 'success';
    const METRIC_SCREENSHOT_RESULT_FAILURE = 'failure';
    const METRIC_SCREENSHOT_RESULT_SKIPPED = 'skipped';

    /** @var Collector */
    private $collector;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var LoggerFactory */
    private $loggerFactory;

    /** @var VerificationEventFactory */
    private $eventFactory;

    /** @var EventService */
    private $eventService;

    private $featureService;

    /**
     * @param Collector $collector
     * @param DateTimeService $dateTimeService
     * @param LoggerFactory $loggerFactory
     * @param VerificationEventFactory $eventFactory
     * @param EventService $eventService
     */
    public function __construct(
        Collector $collector,
        DateTimeService $dateTimeService,
        LoggerFactory $loggerFactory,
        VerificationEventFactory $eventFactory,
        EventService $eventService,
        FeatureService $featureService
    ) {
        $this->collector = $collector;
        $this->dateTimeService = $dateTimeService;
        $this->loggerFactory = $loggerFactory;
        $this->eventFactory = $eventFactory;
        $this->eventService = $eventService;
        $this->featureService = $featureService;
    }

    /**
     * @param Transaction $transaction
     * @param VerificationContext $verificationContext
     * @param VerificationResults $verificationResults
     * @param VerificationResultType $overallResult
     */
    public function dispatchAgentVerificationCompleted(
        Transaction $transaction,
        VerificationContext $verificationContext,
        VerificationResults $verificationResults,
        VerificationResultType $overallResult
    ) {
        $agent = $verificationContext->getAgent();
        $logger = $this->loggerFactory->getAsset($agent->getKeyName());
        $this->dispatchMetrics($transaction, $verificationContext, $verificationResults, $overallResult, $logger);
        $this->dispatchEvent($transaction, $verificationContext, $verificationResults, $overallResult, $logger);
    }

    private function dispatchMetrics(
        Transaction $transaction,
        VerificationContext $verificationContext,
        VerificationResults $verificationResults,
        VerificationResultType $overallResult,
        DeviceLoggerInterface $logger
    ) {
        $agent = $verificationContext->getAgent();
        $screenshotResult = $this->buildScreenshotResult($verificationResults);
        if ($screenshotResult === 'success') {
            $logger->info("SCN0834 Screenshot indicates that the protected machine has booted and is healthy (success)");
        } elseif ($screenshotResult === 'failure') {
            $logger->info("SCN0835 Screenshot indicates that the protected machine has not booted or is unhealthy (failure)");
        } else {
            $logger->info("SCN0836 Screenshot process was skipped, likely due to a pending windows update");
        }

        $tags = [
            'result' => $overallResult->key(),
            'screenshot_result' => $screenshotResult,
            'lakitu_injected' => $verificationContext->isLakituInjected(),
            'agent_api_version' => $agent->getDriver()->getApiVersion(),
            'agent_encrypted' => $agent->getEncryption()->isEnabled(),
            'agent_type' => $agent->getPlatform()->value(),
        ];

        $loggerFields = $tags;

        /** @var AbstractLibvirtConnection */
        $connection = $verificationContext->getConnection();
        if (!$connection->isLocal()) {
            $loggerFields['hypervisor_host'] = $connection->getHost();
        }

        $logger->info("VEE0001 Sending agent verification process metric", $loggerFields);
        $this->collector->increment(Metrics::AGENT_VERIFICATION_PROCESS, $tags);

        $verificationDuration = $this->dateTimeService->getElapsedTime(
            (int) $transaction->getStartTime()->format('U')
        );
        $logger->info(
            "VEE0002 Sending agent verification process duration metric in seconds.",
            ['verificationDuration' => $verificationDuration]
        );
        $this->collector->measure(Metrics::AGENT_VERIFICATION_DURATION, $verificationDuration, $tags);
    }

    /**
     * Build the 'screenshot_result' tag that's included with the metrics
     *
     * @param VerificationResults $results
     * @return string 'success' if the screenshot process succeeded and the screenshot shows a successful boot.
     *                'failure' if the screenshot process failed or the screenshot shows a failure state.
     *                'skipped' if the screenshot process was skipped due a pending reboot on a screenshot
     */
    private function buildScreenshotResult(VerificationResults $results): string
    {
        if ($this->featureService->isSupported(FeatureService::FEATURE_SKIP_VERIFICATION) &&
            $results->getOsUpdatePending()) {
            return self::METRIC_SCREENSHOT_RESULT_SKIPPED;
        }
        $screenshotProcessSucceeded = $results->getScreenshotSuccessful();
        $screenshotAnalysis = $results->getScreenshotAnalysis();
        $screenshotLooksGood = $screenshotAnalysis === VerificationResults::SCREENSHOT_ANALYSIS_NO_FAILURE;
        $screenshotIsAllGood = $screenshotProcessSucceeded && $screenshotLooksGood;

        return $screenshotIsAllGood ? self::METRIC_SCREENSHOT_RESULT_SUCCESS : self::METRIC_SCREENSHOT_RESULT_FAILURE;
    }

    private function dispatchEvent(
        Transaction $transaction,
        VerificationContext $verificationContext,
        VerificationResults $verificationResults,
        VerificationResultType $overallResult,
        DeviceLoggerInterface $logger
    ) {
        $event = $this->eventFactory->create($transaction, $verificationContext, $verificationResults, $overallResult);
        $this->eventService->dispatch($event, $logger);
    }
}
