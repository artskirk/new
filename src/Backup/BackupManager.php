<?php

namespace Datto\Backup;

use Datto\Alert\AlertManager;
use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentConnectivityService;
use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\RepairService;
use Datto\Asset\Agent\Windows\WindowsAgent;
use Datto\Asset\Asset;
use Datto\Asset\AssetService;
use Datto\Asset\AssetType;
use Datto\Config\AgentConfigFactory;
use Datto\DirectToCloud\DirectToCloudCommander;
use Datto\Common\Resource\Sleep;
use Datto\Service\Alert\AlertService;
use Datto\System\Transaction\TransactionException;
use Datto\Utility\Systemd\Systemctl;
use Exception;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * Main entrance service for backups.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class BackupManager
{
    const NO_EXPIRY = BackupStatusService::NO_EXPIRY;

    /** @var Asset */
    private $asset;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var AlertManager */
    private $alertManager;

    /** @var BackupTransactionFactory */
    private $backupTransactionFactory;

    /** @var BackupStatusService */
    private $backupStatus;

    /** @var AgentConfigFactory */
    private $agentConfigFactory;

    /** @var BackupCancelManager */
    private $backupCancelManager;

    /** @var BackupEventService */
    private $backupEventService;

    /** @var DirectToCloudCommander */
    private $directToCloudCommander;

    /** @var Systemctl */
    private $systemctl;

    /** @var SnapshotStatusService */
    private $snapshotStatusService;

    /** @var Sleep */
    private $sleep;

    /** @var AgentConnectivityService */
    private $agentConnectivityService;

    /** @var AssetService $assetService */
    private $assetService;

    /** @var RepairService $repairService */
    private $repairService;

    /** @var AlertService */
    private $alertService;

    public function __construct(
        Asset $asset,
        DeviceLoggerInterface $logger,
        AlertManager $alertManger,
        BackupTransactionFactory $backupTransactionFactory,
        BackupStatusService $backupStatus,
        AgentConfigFactory $agentConfigFactory,
        BackupCancelManager $backupCancelManager,
        BackupEventService $backupEventService,
        DirectToCloudCommander $directToCloudCommander,
        Systemctl $systemctl,
        SnapshotStatusService $snapshotStatusService,
        Sleep $sleep,
        AgentConnectivityService $agentConnectivityService,
        AssetService $assetService,
        RepairService $repairService,
        AlertService $alertService
    ) {
        $this->asset = $asset;
        $this->logger = $logger;
        $this->alertManager = $alertManger;
        $this->backupTransactionFactory = $backupTransactionFactory;
        $this->backupStatus = $backupStatus;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->backupCancelManager = $backupCancelManager;
        $this->backupEventService = $backupEventService;
        $this->directToCloudCommander = $directToCloudCommander;
        $this->systemctl = $systemctl;
        $this->snapshotStatusService = $snapshotStatusService;
        $this->sleep = $sleep;
        $this->agentConnectivityService = $agentConnectivityService;
        $this->assetService = $assetService;
        $this->repairService = $repairService;
        $this->alertService = $alertService;
    }

    /**
     * Start a user-triggered backup
     *
     * @param array $metadata Backup metadata.
     */
    public function startUnscheduledBackup(array $metadata = [])
    {
        $wasForced = true;
        $this->startBackup($wasForced, $metadata);
    }

    /**
     * Start a scheduled backup
     *
     * @param array $metadata Backup metadata.
     */
    public function startScheduledBackup(array $metadata = [])
    {
        $wasForced = false;
        $this->startBackup($wasForced, $metadata);
    }

    /**
     * Prepare a backup
     */
    public function prepareBackup(array $metadata)
    {
        try {
            $this->logger->info('BAK9002 Backup prepare requested', ["agent" => $this->asset->getUuid()]);
            $this->snapshotStatusService->clearSnapshotStatus($this->asset->getKeyName());
            $prepareTransaction = $this->backupTransactionFactory->createPrepare(
                $this->asset,
                $this->logger,
                false,
                $metadata
            );
            $prepareTransaction->commit();
        } catch (Throwable $throwable) {
            $this->alertService->sendBackupFailedAlert($this->asset->getKeyName());
            throw $throwable; // The $throwable variable is used in the finally block so this catch can't be removed
        } finally {
            $this->backupStatus->clearBackupStatus();
        }
    }

    /**
     * Get information related to the asset's backup state. This will include things such as if the asset
     * currently has a queued backup, its state, etc.
     *
     * @return BackupInfo
     */
    public function getInfo()
    {
        $agentKey = $this->asset->getKeyName();
        $agentConfig = $this->agentConfigFactory->create($agentKey);
        $isDirectToCloudAgent = $this->asset instanceof Agent && $this->asset->isDirectToCloudAgent();
        $checkIfProcessAlive = !$isDirectToCloudAgent; // dumb

        $queued = $agentConfig->has('needsBackup');
        $status = $this->backupStatus->get($checkIfProcessAlive);

        return new BackupInfo(
            $queued,
            $status
        );
    }

    /**
     * Cancel a backup
     */
    public function cancelBackup()
    {
        // FIXME: need a proper way to cancel direct-to-cloud backups, this is just a hack.
        $isDirectToCloudAgent = $this->asset instanceof Agent && $this->asset->isDirectToCloudAgent();
        if ($isDirectToCloudAgent) {
            $this->directToCloudCommander->killCommanderForAsset($this->asset->getKeyName());
        }

        if ($this->isRunning()) {
            $this->backupCancelManager->cancel($this->asset);
        }
    }

    /**
     * Cancel a backup and wait until the cancellation succeeded
     */
    public function cancelBackupWaitUntilCancelled()
    {
        $this->cancelBackup();

        $timeoutMilliseconds = 60 * 1000;
        while ($timeoutMilliseconds > 0) {
            if ($this->isRunning()) {
                $this->sleep->msleep(50);
                $timeoutMilliseconds -= 50;
            } else {
                return;
            }
        }

        throw new Exception('Backup still running for ' . $this->asset->getKeyName());
    }

    /**
     * Determine if a backup for this asset is running.
     *
     * @return bool True if backup is running
     */
    public function isRunning()
    {
        $backupLock = new BackupLock($this->asset->getKeyName());
        return $backupLock->isLocked();
    }

    /**
     * @param int $startTime
     * @param string $state
     * @param array $additional
     * @param string|null $backupType
     * @param int $expiry
     */
    public function setBackupStatus(
        int $startTime,
        string $state,
        array $additional,
        string $backupType = null,
        int $expiry = self::NO_EXPIRY
    ) {
        $this->backupStatus->updateBackupStatus(
            $startTime,
            $state,
            $additional,
            $backupType,
            $expiry
        );
    }

    /**
     * @param BackupErrorContext $backupErrorContext
     */
    public function logBackupError(BackupErrorContext $backupErrorContext, bool $finalStatus = false)
    {
        if ($finalStatus) {
            $this->logger->error('BAK3009 Agent backup error occurred', $backupErrorContext->toArray());
        } else {
            $this->logger->error('BAK3008 Agent backup error occurred in set status', $backupErrorContext->toArray());
        }
    }

    /**
     * Start the backup process
     *
     * @param bool $wasForced
     * @param array $metadata
     */
    private function startBackup(bool $wasForced, array $metadata)
    {
        $this->systemctl->assertSystemRunning();

        try {
            $this->logger->info('BAK0100 Snapshot requested', ["scheduled" => !$wasForced]);
            $this->dispatchBackupStarted($this->asset);

            $this->repairAgentTypeMismatch();

            $backupTransaction = $this->backupTransactionFactory->create(
                $this->asset,
                $this->logger,
                $wasForced,
                $metadata
            );
            $backupTransaction->commit();
            $this->clearBackupAlerts();
        } catch (Throwable $throwable) {
            if ($this->asset->isType(AssetType::AGENTLESS_WINDOWS) ||
                $this->asset->isType(AssetType::AGENTLESS_LINUX) ||
                $this->asset->isType(AssetType::AGENTLESS_GENERIC)) {
                $this->logger->critical('BAK0101 Backup failed with exception.', [
                    'exception' => $throwable,
                    'partnerAlertMessage' => 'Backup failed with exception. ' . $throwable->getMessage()
                ]);
            } else {
                $this->logger->error('BAK0044 Backup failed with exception.', [
                    'exception' => $throwable,
                    'partnerAlertMessage' => 'Backup failed with exception. ' . $throwable->getMessage()
                ]);
            }

            $this->alertService->sendBackupFailedAlert($this->asset->getKeyName());
            throw $throwable; // The $throwable variable is used in the finally block so this catch can't be removed
        } finally {
            if ($this->asset->isType(AssetType::AGENT)) {
                $this->dispatchBackupCompleted($throwable ?? null);
            }
            $this->backupStatus->clearBackupStatus();
        }
    }

    /**
     * Clears all backup alerts.
     * This is called after a successful backup to clean up any previously generated alerts.
     */
    private function clearBackupAlerts()
    {
        $assetKeyName = $this->asset->getKeyName();

        // This alert would normally be run by the BackupAlertService, but that only runs once a day.
        // So, this clears the alert instead of waiting for the cron to run.
        $this->alertManager->clearAlert($assetKeyName, 'BKP4000');

        // These alerts are generated by snapSchedule.php
        // todo: move this when snapSchedule is refactored
        $this->alertManager->clearAlerts(
            $assetKeyName,
            ['SBK0620', 'SBK2620', 'SBK0621', 'SBK2621']
        );

        // This is to clean up old backup alerts that may have been present during the transition to
        // the new backup.
        $this->alertManager->clearAlerts(
            $assetKeyName,
            ['ZFS3985', 'ZFS3987', 'BKP0013', 'BKP3201', 'BKP0022', 'BKP2040', 'BKP0510', 'BKP0615',
                'BKP1615', 'BKP0201', 'BKP0203', 'BKP1430', 'BKP1450', 'BKP2202', 'BKP2212', 'BKP7201',
                'AGT1340', 'AGT1360']
        );

        // Clear agentless backup alerts.
        if ($this->asset->isType(AssetType::AGENTLESS_WINDOWS) ||
            $this->asset->isType(AssetType::AGENTLESS_LINUX) ||
            $this->asset->isType(AssetType::AGENTLESS_GENERIC)) {
            $this->alertManager->clearAlerts($assetKeyName, ['BAK0101']);
        }
    }

    private function dispatchBackupStarted(Asset $asset)
    {
        if ($asset->isType(AssetType::AGENT)) {
            /** @var Agent $asset */
            try {
                $this->backupEventService->dispatchAgentStarted($asset);
            } catch (Throwable $e) {
                $this->logger->error("BAK9000 Could not dispatch backup started event", ["exception" => $e->getMessage()]);
            }
        }
    }

    /**
     * @param Throwable|null $throwable
     */
    private function dispatchBackupCompleted(Throwable $throwable = null)
    {
        try {
            $throwable = $this->ignoreTransactionException($throwable);

            /*
             * Note: If the context was not successfully created, the getContext call will fatal error. Given the
             *       unlikely-hood of this ever happening, lets log the message and continue using the context as
             *       it encapsulates all of the information we need.
             */

            $context = $this->backupTransactionFactory->getContext();

            if ($throwable === null) {
                $this->snapshotStatusService->updateSnapshotStatus(
                    $this->asset->getKeyName(),
                    SnapshotStatusService::STATE_SNAPSHOT_COMPLETE,
                    $context->getSnapshotTime()
                );
            } else {
                $this->snapshotStatusService->updateSnapshotStatus(
                    $this->asset->getKeyName(),
                    SnapshotStatusService::STATE_SNAPSHOT_FAILED
                );
            }

            $this->backupEventService->dispatchAgentCompleted(
                $context,
                $throwable
            );
        } catch (Throwable $e) {
            $this->logger->error("BAK9001 Could not dispatch backup completed event", ["exception" => $e->getMessage()]);
        }
    }

    /**
     * @param Throwable|null $throwable
     * @return \Exception|Throwable
     */
    private function ignoreTransactionException(Throwable $throwable = null)
    {
        if ($throwable !== null && $throwable instanceof TransactionException && $throwable->getPrevious() !== null) {
            $throwable = $throwable->getPrevious();
        }

        return $throwable;
    }

    /**
     * Check if the agent is communicating on a different port than we're expecting, and if so, change the agent type
     * and repair the connection if it's possible to automatically switch between agent types.
     */
    private function repairAgentTypeMismatch()
    {
        // We only support Shadowsnap -> DWA conversions at this time so there's no point
        // trying to switch unless the device thinks the agent is still Shadowsnap.
        if ($this->asset instanceof WindowsAgent && $this->asset->getPlatform() === AgentPlatform::SHADOWSNAP()) {
            /** @var Agent $agent */
            $agent = $this->asset;
            $connectivityState = $this->agentConnectivityService->checkExistingAgentConnectivity($agent);

            if (!$this->agentConnectivityService->isConnectedState($connectivityState) &&
                $this->agentConnectivityService->existingAgentHasNewType($agent)) {
                // Repair proactively
                $this->repairService->repair($agent->getKeyName());

                // We were able to establish connectivity, so clear any previous alerts related to connectivity
                $connectivityBackupAlertCodes = ['BAK0013', 'BAK0027', 'BAK0028', 'BAK0029', 'BAK0030', 'BAK0032'];
                $this->alertManager->clearAlerts($agent->getKeyName(), $connectivityBackupAlertCodes);

                // Don't add the rollback stage to the next backup transaction, so that we won't undo the rename of the
                // datto or detto files in the dataset
                $this->agentConfigFactory->create($agent->getKeyName())->touch('inhibitRollback');

                // reload asset from key files
                $this->asset = $this->assetService->get($agent->getKeyName());
            }
        }
    }
}
