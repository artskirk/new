<?php

namespace Datto\Verification\Stages;

use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Windows\WindowsServiceFactory;
use Datto\Asset\ApplicationResult;
use Datto\Asset\VerificationScriptOutput;
use Datto\Asset\VerificationScriptsResults;
use Datto\Screenshot\LakituStatus;
use Datto\Screenshot\StatusFactory;
use Datto\System\Transaction\TransactionException;
use Datto\Resource\DateTimeService;
use Datto\Common\Resource\Sleep;
use Datto\Verification\Application\ApplicationScriptManager;
use Datto\Verification\VerificationResultType;
use Datto\Verification\VerificationService;
use Throwable;

/**
 * Run custom verification scripts.
 *
 * This class generates logs in the VER0600 to VER0699 range.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 * @author Matt Coleman <mcoleman@datto.com>
 * @author Matt Cheman <mcheman@datto.com>
 * @author Chad Kosie <ckosie@datto.com>
 */
class RunScripts extends VerificationStage
{
    const DETAILS_TOTAL_SCRIPTS = 'TotalScripts';
    const DETAILS_TOTAL_SUCCESSFUL_SCRIPTS = 'TotalSuccessfulScripts';

    /** @var StatusFactory Used to create the Status object */
    private $statusFactory;

    /** @var Sleep */
    private $sleep;

    /** @var DateTimeService */
    private $dateService;

    /** @var ApplicationScriptManager */
    private $applicationScriptManager;

    /** @var AgentService */
    private $agentService;

    /** @var WindowsServiceFactory */
    private $windowsServiceFactory;

    /** @var VerificationService */
    private $verificationService;

    public function __construct(
        StatusFactory $statusFactory,
        Sleep $sleep,
        DateTimeService $dateService,
        ApplicationScriptManager $applicationScriptManager,
        AgentService $agentService,
        WindowsServiceFactory $windowsServiceFactory,
        VerificationService $verificationService
    ) {
        $this->statusFactory = $statusFactory;
        $this->sleep = $sleep;
        $this->dateService = $dateService;
        $this->applicationScriptManager = $applicationScriptManager;
        $this->agentService = $agentService;
        $this->windowsServiceFactory = $windowsServiceFactory;
        $this->verificationService = $verificationService;
    }

    /**
     * {@inheritdoc}
     */
    public function commit()
    {
        try {
            if ($this->context->hasScreenshotFailed()) {
                $this->setResult(VerificationResultType::SKIPPED());
                return;
            }

            $agent = $this->context->getAgent();
            $totalScripts = count($agent->getScriptSettings()->getScripts());

            $this->logger->debug("VER0603 Total custom scripts: " . $totalScripts);

            // Execute scripts
            $results = null;
            if ($this->context->isLakituInjected()) {
                try {
                    $results = $this->executeScripts();
                } catch (\Throwable $e) {
                    $this->logger->warning('VER0609 Unable to execute scripts', ['exception' => $e]);
                }
            }

            // Process results
            $hasResults = isset(
                $results['verificationScriptResults'],
                $results['applicationResults'],
                $results['serviceResults']
            );
            if ($hasResults) {
                $verificationScriptsResults = $results['verificationScriptResults'];
                $applicationResults = $results['applicationResults'];
                $serviceResults = $results['serviceResults'];
            } else {
                $verificationScriptsResults = new VerificationScriptsResults(false);
                $applicationResults = [];
                $serviceResults = [];
            }
            $totalSuccessfulScripts = $verificationScriptsResults->getSuccessfulScriptCount();

            // Handle missing and save results
            $applicationResults = $this->appendUnknownApplicationResults($applicationResults);
            $serviceResults = $this->appendUnknownServiceResults($serviceResults);
            $this->saveRecoveryPointResults($verificationScriptsResults, $applicationResults, $serviceResults);

            // Only log successful script count if scripts should have been run.
            $this->logger->debug("VER0604 Total successful custom scripts: " . $totalSuccessfulScripts);
        } catch (Throwable $e) {
            $result = VerificationResultType::FAILURE_UNRECOVERABLE();
            $this->setResult($result, $e->getMessage(), $e->getTraceAsString());
            throw $e;
        }

        if ($totalSuccessfulScripts === $totalScripts) {
            $this->setResult(VerificationResultType::SUCCESS());
        } else {
            $this->setResult(VerificationResultType::FAILURE_INTERMITTENT(), 'At least one script has failed!');
        }

        $resultDetails = $this->getResult()->getDetails();
        $resultDetails->setDetail(static::DETAILS_TOTAL_SCRIPTS, $totalScripts);
        $resultDetails->setDetail(static::DETAILS_TOTAL_SUCCESSFUL_SCRIPTS, $totalSuccessfulScripts);

        if (!$this->result->didSucceed()) {
            throw new TransactionException('Run scripts failed. Error message: ' . $this->result->getErrorMessage());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup()
    {
        // There are no cleanup actions for this stage.
    }

    /**
     * @return array
     */
    private function executeScripts()
    {
        $this->logger->debug("VER0600 Now running diagnostic scripts...");

        $vm = $this->context->getVirtualMachine();

        $status = $this->statusFactory->create($this->context->getAgent(), $vm);

        $timeout = $this->context->getScriptsTimeout();
        $status->startScripts();

        $waitedSeconds = 0;
        while (true) {
            $startSeconds = $this->dateService->getTime();
            $lakituScriptStatus = $status->checkScriptStatus();
            $lakituScriptCompleted = $lakituScriptStatus['complete'] ?? false;

            if ($lakituScriptCompleted) {
                $this->logger->debug("VER0601 Diagnostic scripts completed.", $lakituScriptStatus);
                return $this->processScriptStatuses(true, $lakituScriptStatus);
            }

            if ($waitedSeconds >= $timeout) {
                $this->logger->warning(
                    'VER0602 Diagnostic scripts failed to complete timeout',
                    array_merge($lakituScriptStatus, ['timeoutInSeconds' => $timeout])
                );
                return $this->processScriptStatuses(false, $lakituScriptStatus);
            }

            $waitedSeconds = $this->determineWaitedSeconds($waitedSeconds, $startSeconds);
        }
    }

    /**
     * @param bool $complete
     * @param array $lakituScriptStatus
     * @return array
     */
    private function processScriptStatuses(bool $complete, array $lakituScriptStatus)
    {
        $verificationScriptsResults = new VerificationScriptsResults($complete);
        $applicationResults = [];
        $serviceResults = [];

        $statuses = $lakituScriptStatus['statuses'] ?? [];
        foreach ($statuses as $scriptFilePath => $scriptResult) {
            $scriptName = $this->parseScriptName($scriptFilePath);
            $exitCode = $scriptResult['exitCode'];
            $output = $scriptResult['output'];
            $state = $scriptResult['state'];

            if (!$scriptName) {
                $this->logger->warning('SVR0004 Unable to read verification script output', ['scriptName' => $scriptName]);
                continue;
            }

            if ($this->applicationScriptManager->isApplicationScriptName($scriptName)) {
                $this->logger->debug("APP0006 Application detection script detected ...");

                $applicationResults = $this->handleApplicationScriptResult(
                    $applicationResults,
                    $scriptName,
                    $exitCode,
                    trim($output)
                );
            } elseif ($this->applicationScriptManager->isNetStartServiceEnumerationScriptName($scriptName) ||
                    $this->applicationScriptManager->isGetServicesServiceEnumerationScriptName($scriptName)) {
                $this->logger->debug("APP0005 Service enumeration script detected ...");

                $serviceResults = $this->handleServiceEnumerationResult(
                    $serviceResults,
                    $exitCode,
                    trim($output),
                    $scriptName
                );
            } else {
                $verificationScriptsResults = $this->handleCustomScriptResult(
                    $verificationScriptsResults,
                    $scriptName,
                    $exitCode,
                    $output,
                    $state
                );
            }
        }

        return [
            'verificationScriptResults' => $verificationScriptsResults,
            'applicationResults' => $applicationResults,
            'serviceResults' => $serviceResults
        ];
    }

    /**
     * @param ApplicationResult[] $serviceResults
     * @param int $exitCode
     * @param string $output
     * @param string $scriptName
     * @return ApplicationResult[]
     */
    private function handleServiceEnumerationResult(
        array $serviceResults,
        int $exitCode,
        string $output,
        string $scriptName
    ): array {
        try {
            if ($this->applicationScriptManager->isNetStartServiceEnumerationScriptName($scriptName)) {
                $runningServices = $this->windowsServiceFactory->createFromNetStart($output);
            } else {
                $runningServices = $this->windowsServiceFactory->createFromGetServices($output);
            }

            $agentKeyName = $this->context->getAgent()->getKeyName();
            $expectedServices = $this->verificationService->getExpectedServices($agentKeyName);

            foreach ($expectedServices as $expectedService) {
                if ($exitCode === LakituStatus::EXIT_UNKNOWN_ERROR) {
                    $status = ApplicationResult::ERROR_NOT_EXECUTED;
                } elseif (!isset($runningServices[$expectedService->getId()])) {
                    $status = ApplicationResult::NOT_RUNNING;
                } else {
                    $status = ApplicationResult::RUNNING;
                }

                $this->logger->debug("APP0004 Expected service result: " . var_export([
                    'serviceId' => $expectedService->getId(),
                    'status' => $status
                ], true));

                $resultName = $expectedService->getDisplayName() ?? $expectedService->getServiceName();
                $serviceResults[$expectedService->getId()] = new ApplicationResult($resultName, $status);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('APP0007 Unable to handle service enumeration results', ['exception' => $e]);
        }

        return $serviceResults;
    }

    /**
     * @param ApplicationResult[] $applicationResults
     * @param string $scriptName
     * @param int $exitCode
     * @param string $output
     * @return ApplicationResult[]
     */
    private function handleApplicationScriptResult(
        array $applicationResults,
        string $scriptName,
        int $exitCode,
        string $output
    ): array {
        try {
            $applicationId = $this->applicationScriptManager->getApplicationIdFromScriptName($scriptName);

            if (!$this->wasApplicationScriptExecuted($exitCode, $output)) {
                $this->logger->debug("APP0001 Application script was not executed: " . var_export([
                    'scriptName' => $scriptName,
                    'exitCode' => $exitCode,
                    'output' => $output
                ], true));

                $status = ApplicationResult::ERROR_NOT_EXECUTED;
            } elseif ($exitCode !== LakituStatus::EXIT_SCRIPT_SUCCESS) {
                $this->logger->debug("APP0002 Application script executed but did not pass: " . var_export([
                    'scriptName' => $scriptName,
                    'exitCode' => $exitCode,
                    'output' => $output
                ], true));

                $status = ApplicationResult::NOT_RUNNING;
            } else {
                $this->logger->debug("APP0003 Application script executed and passed: " . var_export([
                    'scriptName' => $scriptName,
                    'exitCode' => $exitCode,
                    'output' => $output
                ], true));

                $status = ApplicationResult::RUNNING;
            }

            $applicationResults[$applicationId] = new ApplicationResult(
                $applicationId,
                $status
            );
        } catch (\Throwable $e) {
            $this->logger->warning('APP0008 Unable to determine application name from script name', ['scriptName' => $scriptName]);
        }

        return $applicationResults;
    }

    /**
     * @param VerificationScriptsResults $verificationScriptsResults
     * @param string $scriptName
     * @param int $exitCode
     * @param string $output
     * @param int $state
     * @return VerificationScriptsResults
     */
    private function handleCustomScriptResult(
        VerificationScriptsResults $verificationScriptsResults,
        string $scriptName,
        int $exitCode,
        string $output,
        int $state
    ): VerificationScriptsResults {
        $verificationScriptsResults->appendOutput(new VerificationScriptOutput(
            $scriptName,
            $state,
            $output,
            $exitCode
        ));

        return $verificationScriptsResults;
    }

    /**
     * Determine any applications that were expected to be checked, but we did not receive any results for.
     *
     * @param ApplicationResult[] $applicationResults
     * @return ApplicationResult[]
     */
    private function appendUnknownApplicationResults(array $applicationResults): array
    {
        $agent = $this->context->getAgent();
        $expectedApplications = $agent->getScreenshotVerification()->getExpectedApplications();

        $expectedApplicationsWithoutResults = array_diff($expectedApplications, array_keys($applicationResults));
        if (!empty($expectedApplicationsWithoutResults)) {
            $this->logger->warning(
                'APP0009 Some application scripts were expected but not executed',
                $expectedApplicationsWithoutResults
            );
            foreach ($expectedApplicationsWithoutResults as $applicationName) {
                $applicationResults[$applicationName] = new ApplicationResult(
                    $applicationName,
                    ApplicationResult::ERROR_NOT_EXECUTED
                );
            }
        }

        return $applicationResults;
    }

    /**
     * Determine any services that were expected to be checked, but we did not receive any results for.
     *
     * @param ApplicationResult[] $serviceResults
     * @return ApplicationResult[]
     */
    private function appendUnknownServiceResults(array $serviceResults): array
    {
        $agent = $this->context->getAgent();
        $expectedServiceIds = $agent->getScreenshotVerification()->getExpectedServices();

        $expectedServicesWithoutResults = array_diff($expectedServiceIds, array_keys($serviceResults));
        if (!empty($expectedServicesWithoutResults)) {
            $this->logger->warning(
                "VER0608 Some services were expected to be verified but were not executed.",
                $expectedServicesWithoutResults
            );
            foreach ($expectedServicesWithoutResults as $serviceId) {
                $serviceResults[$serviceId] = new ApplicationResult(
                    $serviceId,
                    ApplicationResult::ERROR_NOT_EXECUTED
                );
            }
        }

        return $serviceResults;
    }

    /**
     * @param VerificationScriptsResults $verificationScriptsResults
     * @param ApplicationResult[] $applicationResults
     * @param ApplicationResult[] $serviceResults
     */
    private function saveRecoveryPointResults(
        VerificationScriptsResults $verificationScriptsResults,
        array $applicationResults,
        array $serviceResults
    ) {
        $agent = $this->agentService->get($this->context->getAgent()->getKeyName());
        $snapshotEpoch = $this->context->getSnapshotEpoch();

        $recoveryPoint = $agent->getLocal()
            ->getRecoveryPoints()
            ->get($snapshotEpoch);

        if ($recoveryPoint) {
            $recoveryPoint->setVerificationScriptsResults($verificationScriptsResults);
            $recoveryPoint->setApplicationResults($applicationResults);
            $recoveryPoint->setServiceResults($serviceResults);
        } else {
            $this->logger->warning('VER0610 Unable to save script verification results, recovery point does not exist.');
        }

        $this->agentService->save($agent);
    }

    /**
     * @param string $scriptFilePath
     * @return string
     */
    private function parseScriptName(string $scriptFilePath): string
    {
        $scriptFilePath = str_replace('\\', '/', $scriptFilePath);
        $scriptName = basename($scriptFilePath);

        return $scriptName ?: '';
    }

    /**
     * @param int $waitedSeconds
     * @param int $startSeconds
     * @return int
     */
    private function determineWaitedSeconds(int $waitedSeconds, int $startSeconds): int
    {
        // Account for time taken to query lakitu
        $duration = $this->dateService->getTime() - $startSeconds;

        // Make sure it takes at least 5 seconds
        $wait = floor(5 - $duration);
        $waitedLongEnough = $wait <= 0;
        if (!$waitedLongEnough) {
            $this->sleep->sleep($wait);
        }

        return $waitedSeconds + ($wait + $duration);
    }

    /**
     * Determine if a application verification script was executed (may or may not have "passed").
     *
     * @param int $exitCode
     * @param string $output
     * @return bool
     */
    private function wasApplicationScriptExecuted(int $exitCode, string $output): bool
    {
        return ($exitCode === LakituStatus::EXIT_SCRIPT_SUCCESS || $exitCode === LakituStatus::EXIT_SCRIPT_ERROR)
            && $output !== ApplicationScriptManager::ERROR_UNSUPPORTED_VERSION;
    }
}
