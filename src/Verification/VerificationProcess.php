<?php

namespace Datto\Verification;

use Datto\Alert\AlertManager;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\VerificationScreenshotResult;
use Datto\Config\AgentConfigFactory;
use Datto\Config\AgentState;
use Datto\Config\AgentStateFactory;
use Datto\Resource\DateTimeService;
use Datto\System\Transaction\Transaction;
use Datto\System\Transaction\TransactionException;
use Datto\Verification\Notification\VerificationResults;
use Datto\Verification\Stages\VerificationStage;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * Verification process runs through all the verification stages and then calls all of the
 * notifiers.  This class handles the main orchestration of the verification process.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 * @author Matt Coleman <mcoleman@datto.com>
 * @author Matt Cheman <mcheman@datto.com>
 */
class VerificationProcess
{
    /** @var AgentService */
    private $agentService;

    /** @var Transaction Transaction containing verification stages */
    private $verificationTransaction;

    /** @var VerificationContext */
    private $verificationContext;

    /** @var Transaction Transaction containing post-verification notification stages */
    private $notificationTransaction;

    /** @var NotificationContext */
    private $notificationContext;

    /** @var VerificationResults */
    private $verificationResults;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var string */
    private $resultErrorMessage;

    /** @var VerificationMonitoringService */
    private $verificationMonitoringService;

    /** @var VerificationQueue */
    private $verificationQueue;

    /** @var CloudAssistedVerificationOffloadService */
    private $cloudAssistedVerificationOffloadService;

    /** @var VerificationCancelManager */
    private $verificationCancelManager;

    private AgentStateFactory $agentStateFactory;
    private AgentConfigFactory $agentConfigFactory;
    private DateTimeService $dateTimeService;
    private AlertManager $alertManager;

    public function __construct(
        AgentService $agentService,
        Transaction $verificationTransaction,
        VerificationContext $verificationContext,
        Transaction $notificationTransaction,
        NotificationContext $notificationContext,
        VerificationResults $verificationResults,
        DeviceLoggerInterface $logger,
        VerificationMonitoringService $verificationMonitoringService,
        VerificationQueue $verificationQueue,
        CloudAssistedVerificationOffloadService $cloudAssistedVerificationOffloadService,
        VerificationCancelManager $verificationCancelManager,
        AgentStateFactory $agentStateFactory,
        AgentConfigFactory $agentConfigFactory,
        DateTimeService $dateTimeService,
        AlertManager $alertManager
    ) {
        $this->agentService = $agentService;
        $this->verificationTransaction = $verificationTransaction;
        $this->verificationContext = $verificationContext;
        $this->notificationTransaction = $notificationTransaction;
        $this->notificationContext = $notificationContext;
        $this->verificationResults = $verificationResults;
        $this->logger = $logger;
        $this->verificationMonitoringService = $verificationMonitoringService;
        $this->verificationQueue = $verificationQueue;
        $this->cloudAssistedVerificationOffloadService = $cloudAssistedVerificationOffloadService;
        $this->verificationCancelManager = $verificationCancelManager;
        $this->agentStateFactory = $agentStateFactory;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->dateTimeService = $dateTimeService;
        $this->alertManager = $alertManager;
    }

    /**
     * Runs through the list of verification stages.
     *
     * @param VerificationAsset|null $verificationAgent Verification asset to screenshot
     * @return VerificationResultType|null
     */
    public function execute(VerificationAsset $verificationAgent = null)
    {
        try {
            return $this->executeInternal($verificationAgent);
        } finally {
            // In normal execution, this will already be released at this point.
            // This just ensures that resources are released if anything goes wrong.
            $this->releaseCloudResources();
        }
    }

    /**
     * @param VerificationAsset|null $verificationAgent
     * @return VerificationResultType|null
     */
    private function executeInternal($verificationAgent)
    {
        $this->logger->info(
            'VER2010 Starting verification on connection',
            ['connection' => $this->verificationContext->getConnection()->getName()]
        );

        // Increment attempts before starting the verification to prevent infinite retries.
        // If we waited until the end it would never get removed from the queue when the process has a php fatal error.
        if ($verificationAgent) {
            $this->verificationQueue->incrementAttempts($verificationAgent);
        }

        // Take Screenshot
        $this->logger->debug('VER2013 Executing verification transaction');
        try {
            $this->verificationTransaction->commit();
        } catch (TransactionException $e) {
            $this->logger->error(
                'VER2017 Transaction Exception thrown during verification transaction.',
                ['exception' => $e]
            );
        } catch (Throwable $t) {
            $this->logger->error(
                'VER2018 Exception thrown during verification transaction.',
                ['exception' => $t]
            );
        } finally {
            // We're done with the connection. Release as early as possible.
            $this->releaseCloudResources();
        }
        $this->logger->debug('VER2014 Verification completed');

        if ($this->processWasCancelled()) {
            if ($verificationAgent !== null) {
                $this->verificationQueue->remove($verificationAgent);
            }
            return null;
        }

        try {
            $overallResult = $this->findWorstResult($this->verificationTransaction->getCommittedStages());
        } catch (Throwable $t) {
            $this->logger->error(
                'VER2021 Exception thrown while analyzing results.',
                ['exception' => $t]
            );
            $overallResult = VerificationResultType::FAILURE_UNRECOVERABLE();
        }

        if ($overallResult === VerificationResultType::SUCCESS()) {
            $this->logger->info('VER2011 Verification successful');
        } elseif ($overallResult === VerificationResultType::SKIPPED()) {
            $this->logger->warning('VER2022 Verification skipped');
        } else {
            $this->logger->error('VER2012 Verification failed');
        }

        $requeued = $verificationAgent &&
            $this->verificationQueue->processVerificationResults($verificationAgent, $overallResult, $this->logger);

        if (!$requeued) {
            $this->logger->info('VER2015 Executing notification transaction');

            try {
                $this->updateNotificationContext();
                $this->verificationResults->gatherResults($overallResult);

                // save out verification result
                $verificationScreenshotResult = new VerificationScreenshotResult(
                    $this->verificationResults->getScreenshotSuccessful(),
                    $this->verificationResults->getOsUpdatePending(),
                    $this->verificationResults->getScreenshotAnalysis()
                );
                $snapshot = $this->verificationContext->getSnapshotEpoch();

                // Pull full agent details from agentService, which will now contain verification results
                $agent = $this->agentService->get($this->verificationContext->getAgent()->getKeyName());

                $recoveryPoint = $agent->getLocal()->getRecoveryPoints()->get($snapshot);
                $recoveryPoint->setVerificationScreenshotResult($verificationScreenshotResult);
                $recoveryPoint->setOsUpdatePending($this->verificationContext->getOsUpdatePending());

                $this->agentService->save($agent);

                $this->verificationMonitoringService->dispatchAgentVerificationCompleted(
                    $this->verificationTransaction,
                    $this->verificationContext,
                    $this->verificationResults,
                    $overallResult
                );

                $this->notificationTransaction->commit();
            } catch (TransactionException $e) {
                $this->logger->error(
                    'VER2019 Transaction Exception thrown during notification transaction.',
                    ['exception' => $e]
                );
            } catch (Throwable $t) {
                $this->logger->error(
                    'VER2020 Exception thrown during notification transaction.',
                    ['exception' => $t]
                );
            }

            $this->logger->info('VER2016 Notifications completed');
        }

        $this->writeResultFiles($overallResult);

        return $overallResult;
    }

    /**
     * Check to see if the process was cancelled
     * @return bool returns if the verification process was cancelled
     */
    public function processWasCancelled(): bool
    {
        return $this->verificationCancelManager->wasCancelled();
    }

    /**
     * Update the notification context.
     */
    private function updateNotificationContext()
    {
        $committedStages = $this->verificationTransaction->getCommittedStages();
        foreach ($committedStages as $committedStage) {
            /** @var VerificationStage $committedStage */
            $name = $committedStage->getName();
            $stageResult = $committedStage->getResult();
            if ($stageResult) {
                $this->notificationContext->add($name, $stageResult);
            }
        }
    }

    /**
     * Finds the most severe error that occurred in $stages.
     *
     * @param VerificationStage[] $stages
     * @return VerificationResultType worst error
     */
    private function findWorstResult($stages)
    {
        $this->resultErrorMessage = '';
        $worstResult = VerificationResultType::SUCCESS();
        foreach ($stages as $stage) {
            $result = $stage->getResult();

            // If the stage did not set a result,
            // assume the worst (an exception was thrown before it was set)
            $resultType = isset($result) ? $result->getResultType() :
                VerificationResultType::FAILURE_UNRECOVERABLE();

            if ($resultType->value() > $worstResult->value()) {
                $worstResult = $resultType;
                $this->resultErrorMessage = isset($result)
                    ? $result->getErrorMessage()
                    : 'Verification stage incomplete: ' . $stage->getName();
            }
        }
        return $worstResult;
    }

    private function writeResultFiles(VerificationResultType $overallResult)
    {
        $agentKey = $this->verificationContext->getAgent()->getKeyName();

        $agentState = $this->agentStateFactory->create($agentKey);
        $assetConfig = $this->agentConfigFactory->create($agentKey);
        $screenshotFailed = $this->verificationContext->hasScreenshotFailed();

        if ($overallResult === VerificationResultType::SUCCESS()) {
            $agentState->clear(AgentState::KEY_SCREENSHOT_FAILED);
            $assetConfig->setRaw('lastScreenshotTime', $this->dateTimeService->getTime());
            $this->logger->info("SCN0831 VM verification successful");
            $this->alertManager->clearAlerts($agentKey, ['SCR0909', 'SCN0831', 'VER0119']);
        } elseif ($screenshotFailed && ($overallResult === VerificationResultType::SKIPPED())) {
            $agentState->clear(AgentState::KEY_SCREENSHOT_FAILED);
            $this->logger->warning('SCN0837 VM verification skipped because screenshot has failed');
            $agentState->set(AgentState::KEY_SCREENSHOT_SKIPPED, 'VM verification skipped - screenshot has failed');
        } elseif (!$this->processWasCancelled() && ($overallResult === VerificationResultType::SKIPPED())) {
            $agentState->clear(AgentState::KEY_SCREENSHOT_FAILED);
            $this->logger->warning('SCN0833 VM verification skipped because pending windows reboot');
            $agentState->set(AgentState::KEY_SCREENSHOT_SKIPPED, 'Pending reboot detected for Windows - skip verification as it will likely fail');
        } elseif (!$this->processWasCancelled()) {
            $screenshotError = $this->resultErrorMessage;
            $this->logger->error("SCN0832 VM verification failed with error", ['error' => $screenshotError]);
            $agentState->set(AgentState::KEY_SCREENSHOT_FAILED, $screenshotError);
        }
    }

    private function releaseCloudResources()
    {
        if ($this->verificationContext->isCloudResourceReleaseRequired()) {
            // Since the hyperv connection used for this verification was fetched from deviceweb, we need
            // to notify deviceweb that we're done so the connection can be used by another verification.
            $this->cloudAssistedVerificationOffloadService->releaseConnection(
                $this->verificationContext->getAgent()
            );

            $this->verificationContext->setCloudResourceReleaseRequired(false);
        }
    }
}
