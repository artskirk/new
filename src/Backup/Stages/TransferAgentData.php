<?php

namespace Datto\Backup\Stages;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\Api\AgentApiException;
use Datto\Asset\Agent\Api\AgentTransferResult;
use Datto\Asset\Agent\Api\AgentTransferState;
use Datto\Asset\Agent\Api\BackupApiContext;
use Datto\Asset\Agent\Api\BackupApiContextFactory;
use Datto\Asset\Agent\DiffMergeService;
use Datto\Asset\Agent\Job\BackupJobStatus;
use Datto\Asset\Agent\Log\LogService;
use Datto\Asset\Agent\RepairService;
use Datto\Asset\AssetType;
use Datto\Backup\BackupCancelledException;
use Datto\Backup\BackupCancelManager;
use Datto\Backup\BackupErrorContext;
use Datto\Backup\BackupException;
use Datto\Backup\BackupStatusService;
use Datto\Backup\TranslatableExceptionService;
use Datto\Backup\Transport\BackupTransportFactory;
use Datto\Backup\Transport\EncryptedShadowSnapTransport;
use Datto\Backup\Transport\SambaTransport;
use Datto\License\ShadowProtectLicenseManagerFactory;
use Datto\Reporting\Backup\BackupReport;
use Datto\Reporting\Backup\BackupReportManager;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Datto\Common\Resource\Sleep;
use Exception;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * This backup stage transfers data from the agent to the live dataset on the device.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class TransferAgentData extends BackupStage
{
    /*
     * Immediately retrying a backup request doesn't give the agent enough time to stop the previous backup job.
     * First wait 45 seconds, then 90 seconds to give the agent time to settle.
     */
    const FINAL_RETRY_WAIT_TIME_SECONDS = 90;
    const RETRY_WAIT_TIME_SECONDS = 45;

    /** @var int Number of exceptions to tolerate in backup loop. */
    const MAX_TRANSFER_ATTEMPTS = 3;

    /** Amount of time to sleep after setting up the backup transport */
    const TRANSPORT_SETUP_SLEEP_IN_SECONDS = 7;

    /** @var int Number of seconds to wait before logging another status update message */
    const STATUS_LOG_MIN_INTERVAL_SECONDS = 10;

    /** @var int Number of lines to retrieve from the backup logs on the agent */
    const AGENT_LOG_LINE_COUNT = 10;

    /** @var int Log level severity to retrieve from the backup logs on the agent */
    const AGENT_LOG_SEVERITY = 3;

    /** @var int Log level severity to retrieve logs after a backup completes before dumping into device logs */
    const AGENT_LOG_BACKUP_SEVERITY = 2;

    /** @var int Number of log lines we retrieve from the agent following a backup before dumping into device logs */
    const AGENT_LOG_BACKUP_LINE_COUNT = 500;

    private DateTimeService $dateTimeService;
    private Filesystem $filesystem;
    private BackupApiContextFactory $backupApiContextFactory;
    private BackupTransportFactory $backupTransportFactory;
    private Sleep $sleep;
    private RepairService $repairService;
    private ShadowProtectLicenseManagerFactory $licenseManagerFactory;
    private BackupCancelManager $backupCancelManager;
    private TranslatableExceptionService $translatableExceptionService;
    private LogService $logService;
    private int $currentTransferAttempt;
    private AgentTransferResult $attemptResult;
    private string $jobUuid;
    private bool $transportSetupFailure = false;
    private int $lastProgressTime;
    private int $lastLoggedProgressTime = 0;
    private BackupReportManager $backupReportManager;
    private DiffMergeService $diffMergeService;

    public function __construct(
        DateTimeService $dateTimeService,
        Filesystem $filesystem,
        BackupApiContextFactory $backupApiContextFactory,
        BackupTransportFactory $backupTransportFactory,
        Sleep $sleep,
        RepairService $repairService,
        ShadowProtectLicenseManagerFactory $licenseManagerFactory,
        BackupCancelManager $backupCancelManager,
        TranslatableExceptionService $translatableExceptionService,
        LogService $logService,
        DiffMergeService $diffMergeService,
        BackupReportManager $backupReportManager
    ) {
        $this->dateTimeService = $dateTimeService;
        $this->filesystem = $filesystem;
        $this->backupApiContextFactory = $backupApiContextFactory;
        $this->backupTransportFactory = $backupTransportFactory;
        $this->sleep = $sleep;
        $this->repairService = $repairService;
        $this->licenseManagerFactory = $licenseManagerFactory;
        $this->backupCancelManager = $backupCancelManager;
        $this->translatableExceptionService = $translatableExceptionService;
        $this->logService = $logService;
        $this->diffMergeService = $diffMergeService;
        $this->backupReportManager = $backupReportManager;
    }

    /**
     * @inheritdoc
     */
    public function commit(): void
    {
        try {
            $succeededOrCancelled = false;
            $this->context->getAgentApi()->initialize();
            $this->cancelBackupJobIfRunning();
            $this->context->clearAlert('BAK1430');
            $this->attemptResult = AgentTransferResult::NONE();
            $currentAttempt = 1;
            $this->startBackupReport();

            while (!$succeededOrCancelled && $currentAttempt <= self::MAX_TRANSFER_ATTEMPTS) {
                $succeededOrCancelled = $this->attemptTransfer($currentAttempt);
                $currentAttempt++;
                if (!$succeededOrCancelled) {
                    $wait = $currentAttempt === self::MAX_TRANSFER_ATTEMPTS ? self::FINAL_RETRY_WAIT_TIME_SECONDS : self::RETRY_WAIT_TIME_SECONDS;
                    $waited = 0;
                    $this->context->getLogger()->info('BAK3333 Waiting before next attempt', ['seconds' => $wait]);

                    while ($waited < $wait) {
                        if ($this->backupCancelManager->isCancelling($this->context->getAsset())) {
                            $this->context->getLogger()->warning('BAK3005 Backup was cancelled by user, skip remaining attempts');
                            break 2; // Break out of both while loops. We don't want to attempt another backup
                        }
                        $this->sleep->sleep(1);
                        $waited += 1;
                    }
                }
            }
        } finally {
            $this->collectAgentLogs();
            $this->finishBackupReport();
        }
        $this->context->reloadAsset();
    }

    /**
     * @inheritdoc
     */
    public function cleanup(): void
    {
    }

    /**
     * Cancel any backup jobs that may be running
     */
    private function cancelBackupJobIfRunning(): void
    {
        $backupUuidFile = $this->context->getBackupJobUuidFile();
        if ($this->filesystem->exists($backupUuidFile)) {
            $jobUuid = $this->filesystem->fileGetContents($backupUuidFile);
            $this->cancelBackupJob($jobUuid);
            $this->filesystem->unlink($backupUuidFile);
        }
    }

    /**
     * Start the backup report
     */
    private function startBackupReport(): void
    {
        $backupType = $this->context->isForced() ? BackupReport::FORCED_BACKUP : BackupReport::SCHEDULED_BACKUP;
        $time = $this->dateTimeService->getTime();
        $backupReportContext = $this->context->getBackupReportContext();
        $this->backupReportManager->startBackupReport($backupReportContext, $time, $backupType);
    }

    /**
     * Finalize the backup report
     */
    private function finishBackupReport(): void
    {
        $backupReportContext = $this->context->getBackupReportContext();
        $this->backupReportManager->finishBackupReport($backupReportContext);
    }

    /**
     * Attempt to transfer the data from the agent to the device
     *
     * @param int $currentAttempt
     * @return bool True if the attempt was successful or was cancelled by the user
     */
    private function attemptTransfer(int $currentAttempt): bool
    {
        $this->currentTransferAttempt = $currentAttempt;

        $this->context->getLogger()->debug(
            'BAK3002 Backup transfer attempt',
            ['attempt' => $this->currentTransferAttempt, 'max_attempts' => self::MAX_TRANSFER_ATTEMPTS]
        );

        $backupReportContext = $this->context->getBackupReportContext();
        $this->backupReportManager->beginBackupAttempt($backupReportContext);

        $succeededOrCancelled = false;
        try {
            $this->determineBackupTransport();
            $this->setUpTransport();
            $this->startAssetBackup();
            $this->monitorBackupProgress();
            $this->backupReportManager->endSuccessfulBackupAttempt($backupReportContext, "BAK3300");
            $this->context->updateBackupStatus(BackupStatusService::STATE_PREPARING_ENVIRONMENT);
            $this->context->getCurrentJob()->cleanup();
            $succeededOrCancelled = true;
        } catch (Throwable $throwable) {
            $wasCancelled = $throwable instanceof BackupCancelledException;
            $context = $throwable instanceof BackupException ? $throwable->getContext() : [];
            $logger = $this->context->getLogger();

            if ($wasCancelled) {
                $logger->warning("BAK3007 Backup was cancelled");
            } else {
                $logger->error("BAK3001 Backup error occurred", array_merge($context, ['exception' => $throwable]));
            }

            if (isset($this->jobUuid)) {
                $this->cancelBackupJob($this->jobUuid);
            }

            $this->backupReportManager->endFailedBackupAttempt(
                $backupReportContext,
                $throwable->getCode(),
                $throwable->getMessage()
            );

            if ($wasCancelled) {
                $succeededOrCancelled = true;
            }

            /** @var Agent $agent */
            $agent = $this->context->getAsset();
            $platform = $agent->getPlatform();

            // CP-15536: Cancel backup if agent reports backup transaction no longer exists, such as on restart.
            if (!$wasCancelled && $this->attemptResult === AgentTransferResult::FAILURE_BAD_REQUEST()) {
                $message = 'The agent failed to report the backup status. ' .
                    'The protected system may have rebooted during backup.';
                $logger->error("BAK0309 $message");
                if ($this->currentTransferAttempt >= self::MAX_TRANSFER_ATTEMPTS) {
                    throw new BackupException($message);
                }
            }

            if (!$wasCancelled &&
                $platform === AgentPlatform::SHADOWSNAP() &&
                $this->context->getBackupEngineUsed() === BackupApiContextFactory::BACKUP_TYPE_STC) {
                $this->checkForIncrementalProblemInAgentLog();
            }

            if (!$wasCancelled &&
                $this->currentTransferAttempt >= self::MAX_TRANSFER_ATTEMPTS) {
                $licenseManager = $this->licenseManagerFactory->create($this->context->getAsset()->getKeyName());
                if ($platform === AgentPlatform::SHADOWSNAP() &&
                    $throwable instanceof AgentApiException &&
                    $throwable->getHttpCode() === Response::HTTP_UNAUTHORIZED &&
                    $licenseManager->canReleaseLicense()) {
                    try {
                        $licenseManager->release();
                        $this->repairService->repair($agent->getKeyName());
                        $logger->debug('BAK3003 ShadowProtect license released and communication repaired');
                    } catch (Exception $licenseReleaseException) {
                        $logger->critical(
                            'BAK3004 Automatic ShadowProtect license reprovisioning failed',
                            ['exception' => $licenseReleaseException]
                        );
                    }
                }

                $message = $this->translatableExceptionService->translateException(
                    $throwable,
                    $platform,
                    "Critical backup failure during data transfer"
                );

                if (!isset($context['partnerAlertMessage'])) {
                    $context['partnerAlertMessage'] = $message;
                }

                $logger->critical(
                    "BAK3000 Critical backup failure during data transfer",
                    array_merge($context, ['error' => $message])
                );
                $logger->debug(sprintf(
                    'BAK0205 End of backup attempt %d of %d',
                    $this->currentTransferAttempt,
                    self::MAX_TRANSFER_ATTEMPTS
                ));

                throw $throwable;
            }

            if (!$wasCancelled && $platform === AgentPlatform::SHADOWSNAP()) {
                $this->repairService->autoRepair($agent->getKeyName());
            }
        } finally {
            $backupUuidFile = $this->context->getBackupJobUuidFile();
            if ($this->filesystem->exists($backupUuidFile)) {
                $this->filesystem->unlink($backupUuidFile);
            }

            $this->cleanupTransport();
        }

        return $succeededOrCancelled;
    }

    /**
     * Create the backup transport and update the context
     */
    private function determineBackupTransport(): void
    {
        $backupTransport = $this->backupTransportFactory->createBackupTransport(
            $this->context->getAsset(),
            $this->currentTransferAttempt,
            $this->attemptResult,
            $this->transportSetupFailure
        );

        $this->context->setBackupTransport($backupTransport);
    }

    /**
     * Initializes the transport and creates luns for volumes and checksum files.
     */
    private function setUpTransport(): void
    {
        $imageLoopsOrFiles = $this->context->getImageLoopsOrFiles();
        $checksumFiles = $this->context->getChecksumFiles();
        $allVolumes = $this->context->getAllVolumes();

        $backupTransport = $this->context->getBackupTransport();

        // todo: handle backup status on a per stage basis. this is a hack to match existing functionality
        if ($backupTransport instanceof SambaTransport ||
            $backupTransport instanceof EncryptedShadowSnapTransport) {
            $this->context->updateBackupStatus(BackupStatusService::STATE_SAMBA);
        }

        try {
            $this->context->getLogger()->debug('BAK2006 Attempting to start backup');
            $backupTransport->setup($imageLoopsOrFiles, $checksumFiles, $allVolumes);

            $this->context->getLogger()->info(
                "BAK0031 Waiting " .
                self::TRANSPORT_SETUP_SLEEP_IN_SECONDS .
                " seconds for backup transport to setup targets"
            );
            $this->sleep->sleep(self::TRANSPORT_SETUP_SLEEP_IN_SECONDS);
        } catch (Throwable $exception) {
            // If setup fails, we should try to fall back to a different method.
            $this->transportSetupFailure = true;
            throw $exception;
        }
    }

    /**
     * Start the agent data transfer
     */
    private function startAssetBackup(): void
    {
        $this->context->updateBackupStatus(BackupStatusService::STATE_VSS);
        $backupApiContext = $this->backupApiContextFactory->create(
            $this->context,
            $this->currentTransferAttempt,
            $this->attemptResult,
            $this->context->getBackupImageFile(),
            $this->diffMergeService
        );

        $this->logEngineFallback($backupApiContext);
        $this->context->setBackupEngineConfigured($backupApiContext->getBackupEngineConfigured());
        $this->context->setBackupEngineUsed($backupApiContext->getBackupEngineUsed());

        // Reset the job uuid in case it was set from a previous attempt
        unset($this->jobUuid);
        try {
            $this->jobUuid = $this->context->getAgentApi()->startBackup($backupApiContext);
        } catch (AgentApiException $agentApiException) {
            if ($agentApiException->getCode() === AgentApiException::INVALID_JOB_ID) {
                $code = ($this->currentTransferAttempt === self::MAX_TRANSFER_ATTEMPTS) ? 'BAK2202' : 'BAK2212';
                $message = "Backup attempt failed as job was unable to be assigned";
                $this->context->getLogger()->critical($code . ' ' . $message);
            }
            $this->attemptResult = AgentTransferResult::FAILURE_UNKNOWN();
            throw $agentApiException;
        }
        $this->context->clearAlerts(['BAK2202', 'BAK2212']);

        $backupUuidFile = $this->context->getBackupJobUuidFile();
        $this->filesystem->filePutContents($backupUuidFile, $this->jobUuid);
        $this->context->getCurrentJob()->saveUuid($this->jobUuid);
    }

    /**
     * Main backup loop... we repeatedly poll the agent until it
     * tells us the backup has finished (either success or failure)
     */
    private function monitorBackupProgress(): void
    {
        $jobUuid = $this->jobUuid;
        $isComplete = false;
        $this->lastProgressTime = $this->dateTimeService->getTime();
        $lastSentAmount = 0;

        // Set in case none of the status calls succeed, set to ACTIVE for hung backup check
        $backupStatus = new BackupJobStatus($this->dateTimeService);
        $backupStatus->setTransferState(AgentTransferState::ACTIVE());

        while (!$isComplete) {
            // Normally, the cancellation is handled by the transaction,
            // but since this is a long running stage, we have a check here
            // to bail out of the transfer if the backup is cancelled.
            if ($this->backupCancelManager->isCancelling($this->context->getAsset())) {
                throw new BackupCancelledException('Backup was cancelled by the user');
            }

            try {
                $backupStatus = $this->context->getAgentApi()->updateBackupStatus($jobUuid, $backupStatus);
            } catch (\Throwable $e) {
                // Do nothing, because the check for hung backups will
                // eventually let the backup time out
            }

            if ($backupStatus->getSent() === $lastSentAmount) {
                $this->checkForHungBackup($backupStatus);
            } else {
                $this->lastProgressTime = $backupStatus->getLastUpdateTime();
                $lastSentAmount = $backupStatus->getSent();
            }

            $this->processBackupStatus($backupStatus);
            $isComplete = $backupStatus->isBackupComplete();
            $this->sleep->sleep(2);
        }
    }

    /**
     * Collects and dumps agent logs for the corresponding backup into log file
     */
    private function collectAgentLogs(): void
    {
        /** @var Agent $agent */
        $agent = $this->context->getAsset();
        $platform = $agent->getPlatform();
        try {
            $agentLogs = $this->logService->get(
                $agent,
                self::AGENT_LOG_BACKUP_LINE_COUNT,
                self::AGENT_LOG_BACKUP_SEVERITY
            );
        } catch (Throwable $throwable) {
            $agentLogs = [];
        }

        if (!empty($agentLogs)) {
            $this->context->getLogger()->debug("BAK3400 Agent logs from this backup attempt below:");

            // There's a ticket to allow filtering on the API side AGENTS-1468
            foreach ($agentLogs as $log) {
                // ShadowSnap and the Mac Agent return datetime Strings instead of ints
                if ($platform === AgentPlatform::SHADOWSNAP() ||
                    $platform === AgentPlatform::DATTO_MAC_AGENT()
                ) {
                    $timestamp = strtotime($log->getTimestamp());
                } else {
                    $timestamp = $log->getTimestamp();
                }

                if ($timestamp >= $this->context->getStartTime()) {
                    $this->context->getLogger()->debug(
                        "BAK3401 " . $log->getTimestamp() . " [" . $log->getCode() . "]: " . $log->getMessage()
                    );
                }
            }
        } else {
            $this->context->getLogger()->warning("BAK3402 Couldn't retrieve logs from the agent");
        }
    }

    /**
     * Cancel a running backup job
     *
     * @param string $jobUuid
     */
    private function cancelBackupJob(string $jobUuid): void
    {
        $this->context->getLogger()->debug("BAK2100 Cancelling running backup: $jobUuid ...");
        $result = $this->context->getAgentApi()->cancelBackup($jobUuid);
        $cancelSuccess = $result['success'] ?? false;
        if ($cancelSuccess) {
            $this->context->getCurrentJob()->cleanup();
            $this->context->getLogger()->debug("BAK2102 Successfully cancelled backup job: $jobUuid");
        }
        $this->context->getLogger()->info("BAK2101 Waiting 15 seconds after cancelling...");
        $this->sleep->sleep(15);
    }

    /**
     * Cleanup the backup transport
     */
    private function cleanupTransport(): void
    {
        try {
            $this->context->getBackupTransport()->cleanup();
        } catch (Exception $e) {
            $this->context->getLogger()->critical(
                'BAK3006 Error occurred while cleaning up backup transport. Backup may be left in bad state.'
            );
        }
    }

    /**
     * Handle the backup status response from the agent
     *
     * @param BackupJobStatus $backupStatus
     */
    private function processBackupStatus(BackupJobStatus $backupStatus): void
    {
        $assetKeyName = $this->context->getAsset()->getKeyName();

        switch ($backupStatus->getTransferState()) {
            case AgentTransferState::ACTIVE():
                $this->processActiveStatus($backupStatus);
                break;

            case AgentTransferState::FAILED():
                $this->processFailedStatus($backupStatus);
                break;

            case AgentTransferState::COMPLETE():
                $this->processFinishedStatus($backupStatus, $assetKeyName);
                break;

            case AgentTransferState::ROLLBACK():
                $this->processRollbackStatus($backupStatus);
                break;

            default:
                break;
        }
    }

    /**
     * Cancel the backup if it has been stalled longer than the timeout limit.
     *
     * @param BackupJobStatus $backupStatus
     */
    private function checkForHungBackup(BackupJobStatus $backupStatus): void
    {
        $timeout = $this->context->getAsset()->getLocal()->getTimeout();
        $hasTimedOut = $this->getTimeSinceLastProgress() > $timeout;
        $transferActive = $backupStatus->getTransferState() === AgentTransferState::ACTIVE();
        if ($hasTimedOut && $transferActive) {
            $message = "The backup job stalled for longer than $timeout seconds and was cancelled.";
            $this->context->getLogger()->warning("BAK1430 $message");
            throw new BackupException($message);
        }
    }

    /**
     * Handle an active backup status response.
     * Update the data transfer metrics.
     *
     * @param BackupJobStatus $backupStatus
     */
    private function processActiveStatus(BackupJobStatus $backupStatus): void
    {
        $this->context->updateBackupStatus(BackupStatusService::STATE_TRANSFER, $backupStatus->getBackupStatusAsArray());

        $currentTime = $this->dateTimeService->getTime();
        if (($currentTime - $this->lastLoggedProgressTime) >= static::STATUS_LOG_MIN_INTERVAL_SECONDS) {
            $this->logDataSent($backupStatus);
            $this->lastLoggedProgressTime = $currentTime;
        }
    }

    /**
     * Handle a failed backup status response.
     *
     * @param BackupJobStatus $backupStatus
     */
    private function processFailedStatus(BackupJobStatus $backupStatus): void
    {
        $this->attemptResult = $backupStatus->getTransferResult();

        $message = 'Agent Export error occurred mid transfer';
        $partnerAlertMessage = $message . ' (' . $backupStatus->getErrorCodeStr() . ' - ' . $backupStatus->getErrorMsg() . ')';
        $context = (new BackupErrorContext(
            $backupStatus->getErrorCode(),
            $backupStatus->getErrorCodeStr(),
            $backupStatus->getErrorMsg(),
            false
        ))->toArray();
        $context['partnerAlertMessage'] = $partnerAlertMessage;

        $this->context->getLogger()->error("BAK0201 $message");
        $this->context->getLogger()->debug(sprintf(
            'BAK0204 End of backup attempt %d of %d',
            $this->currentTransferAttempt,
            self::MAX_TRANSFER_ATTEMPTS
        ));

        $asset = $this->context->getAsset();
        if ($asset->isType(AssetType::WINDOWS_AGENT) ||
            $asset->isType(AssetType::LINUX_AGENT) ||
            $asset->isType(AssetType::MAC_AGENT)
        ) {
            /** @var Agent $asset */
            throw $this->logService->generateException(
                $asset,
                self::AGENT_LOG_LINE_COUNT,
                self::AGENT_LOG_SEVERITY,
                $message,
                $context
            );
        }

        throw new BackupException($message, 0, null, $context);
    }

    /**
     * Handle a finished backup status response
     *
     * @param BackupJobStatus $backupStatus
     * @param string $hostname
     */
    private function processFinishedStatus(BackupJobStatus $backupStatus, string $hostname): void
    {
        $this->logDataSent($backupStatus);
        $this->attemptResult = $backupStatus->getTransferResult();
        $this->context->getLogger()->info(
            "BAK2007 Block level backup of \"{$hostname}\" is complete -- backup attempt $this->currentTransferAttempt of " . self::MAX_TRANSFER_ATTEMPTS
        );
        $this->context->setAmountTransferred($backupStatus->getSent());
        $this->updateVolumeBackupTypes($backupStatus);

        // TODO: Remove code BAK3201. The alert associated with it has already been removed.
        // This clear for BAK3201 was left in to support agents which already had the banner present before the upgrade
        $this->context->clearAlerts(['BAK3201', 'BAK0203', 'BAK0201', 'BAK0309', 'BAK3000', 'BAK1450']);
    }

    /**
     * Handle an active backup status response.
     * Update the data transfer metrics.
     *
     * @param BackupJobStatus $backupStatus
     */
    private function processRollbackStatus(BackupJobStatus $backupStatus): void
    {
        $this->attemptResult = $backupStatus->getTransferResult();
        $message = 'RollBack - The backup job was unable to complete. ' .
            'One or more volumes may have failed to be backed up';
        $this->context->getLogger()->error("BAK1450 $message");
        throw new BackupException($message);
    }

    /**
     * Write log with data transfer size metrics
     *
     * @param BackupJobStatus $backupStatus
     */
    private function logDataSent(BackupJobStatus $backupStatus): void
    {
        $sent = $backupStatus->getSent();
        $total = $backupStatus->getTotal();
        $percent = $backupStatus->getPercentComplete();
        $dataAge = $this->getTimeSinceLastProgress();
        $timeout = $this->context->getAsset()->getLocal()->getTimeout();
        $this->context->getLogger()->debug(
            'BAK2000 Transferring data, ' . $sent . '/' . $total . ' [ ' . $percent . ' ]   ' .
            'Timeout Countdown: ' . $dataAge . '-' . $timeout
        );
    }

    /**
     * Update the context with the volume backup types.
     *
     * @param BackupJobStatus $backupStatus
     */
    private function updateVolumeBackupTypes(BackupJobStatus $backupStatus): void
    {
        $volumeBackupTypes = $backupStatus->getVolumeBackupTypes();
        foreach ($volumeBackupTypes as $volume => $type) {
            $this->context->getLogger()->debug(
                'BAK2011 Detected ' .
                $type .
                ' backup type for volume ' .
                $volume
            );
        }
        $this->context->setVolumeBackupTypes($volumeBackupTypes);
    }

    /**
     * If we are falling back to a different backup engine on this backup, log it.
     *
     * @param BackupApiContext $backupApiContext
     */
    private function logEngineFallback(BackupApiContext $backupApiContext): void
    {
        $previousBackupEngine = $this->context->getBackupEngineUsed();
        $isFallingBack = !empty($previousBackupEngine) &&
            $previousBackupEngine != $backupApiContext->getBackupEngineUsed();
        if ($isFallingBack) {
            $this->context->getLogger()->error(sprintf(
                'BAK0203 %s Export error occurred mid-transfer on previous attempt; falling back to %s',
                $previousBackupEngine,
                $backupApiContext->getBackupEngineUsed()
            ));
        }
    }

    /**
     * @return int
     */
    private function getTimeSinceLastProgress(): int
    {
        $currentTime = $this->dateTimeService->getTime();
        return $currentTime - $this->lastProgressTime;
    }

    /**
     * If an incremental problem log entry is present in the agent logs, set the agent to perform a diff merge to fix
     * the incremental tracker.
     */
    private function checkForIncrementalProblemInAgentLog(): void
    {
        $numberOfLines = 2;
        $severityLevel = 3;
        try {
            $agentLog = $this->context->getAgentApi()->getAgentLogs($severityLevel, $numberOfLines);
        } catch (Throwable $e) {
            $this->context->getLogger()->error('BAK0230 Problem getting agent logs. ' . $e->getMessage());
            return;
        }

        $agentLogEntries = [];
        if (is_array($agentLog['log'])) {
            $agentLogEntries = $agentLog['log'];
        }

        foreach ($agentLogEntries as $agentLogEntry) {
            if (strpos($agentLogEntry['message'], 'GUID mismatch') !== false ||
                strpos($agentLogEntry['message'], 'Not the next incremental image') !== false) {
                $message = 'BK009 - Backup failed due to a problem with incremental tracking';
                $this->context->getLogger()->warning("BAK2012 $message");
                $agent = $this->context->getAsset();
                if ($agent->supportsDiffMerge()) {
                    $this->diffMergeService->setDiffMergeAllVolumes($agent->getKeyName());
                }
            }
        }
    }
}
