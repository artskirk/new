<?php

namespace Datto\Asset\Agent;

use Datto\Asset\Agent\Rescue\RescueAgentService;
use Datto\Asset\AssetInfoSyncService;
use Datto\Asset\AssetRemovalConflict;
use Datto\Asset\AssetRemovalService;
use Datto\Asset\RecoveryPoint\DestroySnapshotReason;
use Datto\Asset\Repository;
use Datto\Backup\BackupManagerFactory;
use Datto\Cloud\SpeedSync;
use Datto\Cloud\SpeedSyncMaintenanceService;
use Datto\Config\AgentConfigFactory;
use Datto\Config\AgentStateFactory;
use Datto\Iscsi\IscsiTarget;
use Datto\License\ShadowProtectLicenseManagerFactory;
use Datto\Log\LoggerAwareTrait;
use Datto\Mercury\MercuryFtpTarget;
use Datto\Mercury\MercuryTargetDoesNotExistException;
use Datto\Replication\SpeedSyncAuthorizedKeysService;
use Datto\Restore\CloneSpec;
use Datto\Restore\Insight\InsightsService;
use Datto\Restore\Restore;
use Datto\Restore\RestoreService;
use Datto\Restore\RestoreType;
use Datto\Restore\Virtualization\VirtualMachineService;
use Datto\Screenshot\ScreenshotFileRepository;
use Datto\Service\Backup\BackupQueueService;
use Datto\Service\Verification\Local\FilesystemIntegrityCheckReportService;
use Datto\Util\Email\EmailService;
use Datto\Util\Email\Generator\RemoveAssetEmailGenerator;
use Datto\Util\RetryHandler;
use Datto\Utility\Cloud\RemoteDestroyException;
use Datto\Verification\VerificationQueue;
use Datto\Verification\VerificationService;
use Datto\Restore\AssetCloneManager;
use Datto\ZFS\ZfsService;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Service for handling destroying agents.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class DestroyAgentService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const FAILED_CODE = 'AGT1500';

    private const DESTROY_ZFS_ATTEMPT_SLEEP_SECONDS = 32; // mercuryftp can potentially stay alive for above 90s
    private const DESTROY_ZFS_ATTEMPT_MAX_ATTEMPTS = 4;
    private const ASSET_KEY_EXTENSIONS_TO_KEEP = [ AssetRemovalService::REMOVING_KEY ];

    private SpeedSync $speedSync;
    private AssetCloneManager $cloneManager;
    private AssetInfoSyncService $assetInfoSyncService;
    private RestoreService $restoreService;
    private Repository $agentRepository;
    private EmailService $emailService;
    private RemoveAssetEmailGenerator $removeAssetEmailGenerator;
    private VerificationService $verificationService;
    private VerificationQueue $verificationQueue;
    private InsightsService $insightsService;
    private RescueAgentService $rescueAgentService;
    private ScreenshotFileRepository $screenshotFileRepository;
    private AgentConfigFactory $agentConfigFactory;
    private AgentStateFactory $agentStateFactory;
    private ShadowProtectLicenseManagerFactory $shadowProtectLicenseManagerFactory;
    private ZfsService $zfsService;
    private VirtualMachineService $virtualMachineService;
    private BackupManagerFactory $backupManagerFactory;
    private RetryHandler $retryHandler;
    private SpeedSyncAuthorizedKeysService $speedSyncAuthorizedKeysService;
    private FilesystemIntegrityCheckReportService $filesystemIntegrityCheckReportService;
    private MercuryFtpTarget $mercuryFtpTarget;
    private IscsiTarget $iscsiTarget;
    private BackupQueueService $backupQueueService;

    /** @var SpeedSyncMaintenanceService */
    private $speedSyncMaintenanceService;

    public function __construct(
        SpeedSync $speedSync,
        AssetCloneManager $cloneManager,
        AssetInfoSyncService $assetInfoSyncService,
        RestoreService $restoreService,
        AgentRepository $agentRepository,
        EmailService $emailService,
        RemoveAssetEmailGenerator $removeAssetEmailGenerator,
        VerificationService $verificationService,
        VerificationQueue $verificationQueue,
        VirtualMachineService $virtualMachineService,
        InsightsService $insightsService,
        RescueAgentService $rescueAgentService,
        ScreenshotFileRepository $screenshotFileRepository,
        AgentConfigFactory $agentConfigFactory,
        AgentStateFactory $agentStateFactory,
        ShadowProtectLicenseManagerFactory $shadowProtectLicenseManagerFactory,
        ZfsService $zfsService,
        BackupManagerFactory $backupManagerFactory,
        RetryHandler $retryHandler,
        SpeedSyncAuthorizedKeysService $speedSyncAuthorizedKeysService,
        FilesystemIntegrityCheckReportService $filesystemIntegrityCheckReportService,
        MercuryFtpTarget $mercuryFtpTarget,
        IscsiTarget $iscsiTarget,
        BackupQueueService $backupQueueService,
        SpeedSyncMaintenanceService $speedSyncMaintenanceService
    ) {
        $this->speedSync = $speedSync;
        $this->cloneManager = $cloneManager;
        $this->assetInfoSyncService = $assetInfoSyncService;
        $this->restoreService = $restoreService;
        $this->agentRepository = $agentRepository;
        $this->emailService = $emailService;
        $this->removeAssetEmailGenerator = $removeAssetEmailGenerator;
        $this->verificationService = $verificationService;
        $this->verificationQueue = $verificationQueue;
        $this->virtualMachineService = $virtualMachineService;
        $this->insightsService = $insightsService;
        $this->rescueAgentService = $rescueAgentService;
        $this->screenshotFileRepository = $screenshotFileRepository;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->agentStateFactory = $agentStateFactory;
        $this->shadowProtectLicenseManagerFactory = $shadowProtectLicenseManagerFactory;
        $this->zfsService = $zfsService;
        $this->retryHandler = $retryHandler;
        $this->backupManagerFactory = $backupManagerFactory;
        $this->speedSyncAuthorizedKeysService = $speedSyncAuthorizedKeysService;
        $this->filesystemIntegrityCheckReportService = $filesystemIntegrityCheckReportService;
        $this->mercuryFtpTarget = $mercuryFtpTarget;
        $this->iscsiTarget = $iscsiTarget;
        $this->backupQueueService = $backupQueueService;
        $this->speedSyncMaintenanceService = $speedSyncMaintenanceService;
    }

    /**
     * Assert that an agent has nothing that blocks its destruction.
     *
     * @param Agent $agent
     */
    public function assertCanDestroy(Agent $agent): void
    {
        $this->logger->setAssetContext($agent->getKeyName());
        $this->preflightChecks($agent);
    }

    /**
     * Destroy an agent.
     *
     * @param Agent $agent
     * @param bool $force if true, bypass some checks and try to remove
     * @param bool $preserveDataset if true, do not delete the zfs dataset
     */
    public function destroy(Agent $agent, bool $force, bool $preserveDataset): void
    {
        $assetKey = $agent->getKeyName();
        $this->logger->setAssetContext($assetKey);
        $this->logger->info('DAS0003 Request to destroy agent'); // log code is used by device-web see DWI-2252
        $this->preflightChecks($agent);

        try {
            $this->removeAgentData($agent, $force, $preserveDataset);
        } catch (Throwable $e) {
            try {
                // Trigger critical email on failure. Sending critical emails depends on the agent existing, and
                // in some cases, the agent will not exist at this point. Let's try/catch the whole thing to prevent
                // masking the error.
                $this->logger->error(self::FAILED_CODE . ' Agent removal failed.', [ // nosemgrep
                    'error' => $e->getMessage(),
                    'partnerAlertMessage' => "Agent removal failed. Error: {$e->getMessage()}"
                ]);
            } catch (Throwable $exception) {
                $this->logger->warning('AGT0011 Failed to send removal failure alert', ['error' => $exception->getMessage()]);
            }

            throw $e;
        }
    }

    private function removeAgentData(Agent $agent, bool $force, bool $preserveDataset): void
    {
        $agentKey = $agent->getKeyName();
        $isReplicated = $agent->getOriginDevice()->isReplicated();

        $this->logger->info('DAS0004 Attempting to destroy agent', ['agentKey' => $agentKey]); // log code is used by device-web see DWI-2252

        $dataset = $agent->getDataset();
        $zfsPath = $dataset->getZfsPath();

        $this->haltOffsiteMirroring($agentKey);
        $this->destroyOffsiteZfsDatasets($zfsPath, $force);

        if (!$isReplicated) {
            $this->pauseAgent($agent);
            $this->cancelBackups($agent);
        }

        $this->cancelQueuedVerifications($agent);
        $this->cancelRunningVerification($agent);
        $this->destroyMercuryTargetAndLuns($agentKey);
        $this->destroyRescueAgentVirtualMachine($agent);
        $this->promoteRescueAgents($agent);

        $this->logger->debug('DAS0006 Removing any leftover iscsi luns for the agent');
        $this->iscsiTarget->removeAgentIscsiEntities($agent);

        if ($preserveDataset) {
            $this->logger->warning('DAS0007 Preserving dataset for destroyed agent', ['zfsPath' => $zfsPath, 'agentKey' => $agentKey]);
        } else {
            $this->destroyLocalZfsDataset($zfsPath, $dataset->getMountPoint());
        }

        $this->syncAssetInfoToCloud($agent);

        if (!$isReplicated) {
            $this->disassociateShadowProtectLicense($agent);
        }

        $this->removeKeyFiles($agent);
        $this->removeFilesystemIntegrityCheckReports($agent);
        $this->removeScreenshots($agent);

        if ($isReplicated) {
            $this->disableReplication($agent);
        }

        if (!$isReplicated) {
            $this->sendRemovalEmail($agent);
        }

        $this->logger->info('DAS0008 Agent successfully destroyed', ['agentKey' => $agentKey]); // log code is used by device-web see DWI-2252

        // Clean up any remaining key files
        $agentConfig = $this->agentConfigFactory->create($agentKey);
        $agentConfig->deleteAllKeys(self::ASSET_KEY_EXTENSIONS_TO_KEEP);

        $agentState = $this->agentStateFactory->create($agentKey);
        $agentState->deleteAllKeys(self::ASSET_KEY_EXTENSIONS_TO_KEEP);
    }

    /**
     * Cancel backups and block until backups are dead and stop writing (or 10 attempts are made)
     *
     * @param Agent $agent
     */
    private function cancelBackups(Agent $agent): void
    {
        // Make sure another backup isn't ready to start right after we cancel the current backup
        $this->backupQueueService->clearQueuedBackup($agent->getKeyName());
        $backupManager = $this->backupManagerFactory->create($agent);
        $backupManager->cancelBackupWaitUntilCancelled();
    }

    /**
     * Run preflight checks to ensure the agent is in a destroyable state.
     *
     * @param Agent $agent
     */
    private function preflightChecks(Agent $agent): void
    {
        $this->logger->info('DAS0009 Running removal preflight checks ...');

        $restores = $this->restoreService->getForAsset($agent->getKeyName());
        $restores = array_filter($restores, function (Restore $restore) {
            return $restore->getSuffix() !== RestoreType::RESCUE;
        });
        if (count($restores) > 0) {
            throw new AssetRemovalConflict('Agent has active restores and cannot be removed');
        }

        $comparisons = $this->insightsService->getCurrentByAsset($agent);
        if (count($comparisons) > 0) {
            throw new AssetRemovalConflict('Agent has active backup insights and cannot be removed');
        }
    }

    private function pauseAgent(Agent $agent): void
    {
        $this->logger->debug('DAS0005 Pausing agent to prevent backups from starting while the agent is being removed', ['agent' => $agent->getKeyName()]);
        $agentConfig = $this->agentConfigFactory->create($agent->getKeyName());
        $agentConfig->touch('backupPause');
    }

    /**
     * Destroy rescue agent VM if applicable.
     *
     * @param Agent $agent
     */
    private function destroyRescueAgentVirtualMachine(Agent $agent): void
    {
        try {
            $this->logger->setAssetContext($agent->getKeyName());
            if ($agent->isRescueAgent()) {
                $this->logger->info('DAS0010 Destroying the virtual machine for the rescue agent');

                $cloneSpec = CloneSpec::fromRescueAgent($agent);

                $vm = $this->virtualMachineService->getVm($cloneSpec->getTargetMountpoint(), $this->logger);

                if (is_null($vm)) {
                    $this->logger->warning('DAS0011 Skipping Rescue Agent vm destroy, vm does not exist for agentKey');
                } else {
                    $this->virtualMachineService->destroyAgentVm($agent, $vm);
                }

                // Note: other restore types do not need to be cleaned up because DestroyAgentService would not have
                // allowed the destroy to proceed if they existed
                $restore = $this->restoreService->findMostRecent($agent->getKeyName(), RestoreType::RESCUE);
                if (!is_null($restore)) {
                    $this->restoreService->remove($restore);
                    $this->restoreService->save();
                }
            }
        } catch (Throwable $e) {
            $this->logger->error('DAS0017 An exception was thrown while attempting to destroy the virtual machine', ['exception' => $e]);
        }
    }

    /**
     * Send agent removal email.
     *
     * @param Agent $agent
     */
    private function sendRemovalEmail(Agent $agent): void
    {
        try {
            $email = $this->removeAssetEmailGenerator->generate('agent', $agent->getPairName());
            $this->emailService->sendEmail($email);
        } catch (Throwable $e) {
            $this->logger->error('DAS0012 An exception was thrown while attempting to send remove agent email', ['exception' => $e]);
        }
    }

    /**
     * Cancel a running verification if one exists for the agent.
     *
     * @param Agent $agent
     */
    private function cancelRunningVerification(Agent $agent): void
    {
        $this->logger->debug('DAS0002 Cancelling running verification if one exists', ['agentKey' => $agent->getKeyName()]);
        $this->verificationService->cancel($agent);
    }

    /**
     * Cancel any queued verifications.
     *
     * @param Agent $agent
     */
    private function cancelQueuedVerifications(Agent $agent): void
    {
        try {
            $this->logger->debug('DAS0013 Removing all verification assets', ['agentKey' => $agent->getKeyName()]);
            $verificationAssets = $this->verificationService->getQueuedVerifications($agent);
            foreach ($verificationAssets as $verificationAsset) {
                $this->verificationQueue->remove($verificationAsset);
            }
        } catch (Throwable $e) {
            $this->logger->error('DAS0014 An exception was thrown while attempting to clear the verification queue', ['exception' => $e]);
        }
    }

    private function haltOffsiteMirroring(string $assetKey): void
    {
        try {
            $this->logger->debug('DAS0016 Halting speedsync', ['assetKey' => $assetKey]);
            $this->speedSyncMaintenanceService->pauseAsset($assetKey, true);
        } catch (Throwable $e) {
            $this->logger->error('DAS0015 An exception was thrown while attempting to halt speedsync', ['assetKey' => $assetKey, 'exception' => $e]);
        }
    }

    private function destroyOffsiteZfsDatasets($zfsPath, bool $force): void
    {
        try {
            $this->logger->debug('DAS0020 Destroying remote datasets', ['zfsPath' => $zfsPath]);
            $this->speedSync->remoteDestroy($zfsPath, DestroySnapshotReason::MANUAL());
        } catch (Throwable $e) {
            $this->logger->error('DAS0022 Error while attempting to destroy the remote dataset', ['zfsPath' => $zfsPath, 'exception' => $e]);

            // Only fail if the error is something the partner can take action on to resolve themselves.
            // Otherwise allow the data to be orphaned offsite.
            $userResolvableFailure = in_array($e->getCode(), RemoteDestroyException::USER_RESOLVABLE_ERRORS);

            if ($force) {
                $this->logger->warning('DAS0040 Continuing removal despite error since "force" has been specified.');
            } elseif ($userResolvableFailure) {
                throw $e;
            } else {
                $this->logger->warning('DAS0041 Continuing removal despite error since the problem is not user resolvable.');
            }
        }
    }


    /**
     * Promote any rescue agents if necessary.
     *
     * @param Agent $agent
     */
    private function promoteRescueAgents(Agent $agent): void
    {
        try {
            $this->rescueAgentService->promoteIfNecessary($agent->getKeyName());
        } catch (Throwable $e) {
            $this->logger->error('DAS0023 An exception was thrown while attempting to promote rescue agents', ['exception' => $e]);
        }
    }

    /**
     * Destroy the zfs dataset for the agent. Bye bye data.
     *
     * zfs destroy would fail if mercuryftp is still alive
     * mercuryftp has keep alive of 90s
     *
     * @param $zfsPath
     * @param $mountPath
     */
    private function destroyLocalZfsDataset($zfsPath, $mountPath): void
    {
        $this->retryHandler->executeAllowRetry(
            function () use ($zfsPath, $mountPath) {
                $this->logger->debug('DAS0025 Destroying zfs clone', [
                    'zfsPath' => $zfsPath,
                    'mountPath' => $mountPath
                ]);
                if ($this->zfsService->exists($zfsPath)) {
                    $this->cloneManager->destroyLoops($mountPath);
                    $this->zfsService->destroyDataset($zfsPath, true);
                } else {
                    $this->logger->warning('DAS0024 Cannot delete ZFS dataset because it does not exist, ignoring...', ['zfsPath' => $zfsPath]);
                }
            },
            self::DESTROY_ZFS_ATTEMPT_MAX_ATTEMPTS,
            self::DESTROY_ZFS_ATTEMPT_SLEEP_SECONDS
        );
    }

    /**
     * Sync asset information to the cloud.
     *
     * @param Agent $agent
     */
    private function syncAssetInfoToCloud(Agent $agent): void
    {
        try {
            $this->logger->debug('DAS0026 Removing remote volume data');
            $this->assetInfoSyncService->syncDeletedAsset($agent);
        } catch (Throwable $e) {
            $this->logger->error('DAS0027 An exception was thrown while updating remote volumes', ['exception' => $e]);
        }
    }

    /**
     * Disassociate the shadow protect license for the agent, if applicable.
     *
     * @param Agent $agent
     */
    private function disassociateShadowProtectLicense(Agent $agent): void
    {
        try {
            $shadowProtectLicenseManager = $this->shadowProtectLicenseManagerFactory->create($agent->getKeyName());

            $this->logger->debug('DAS0028 Disassociating shadow protect license if needed');

            if ($agent->getPlatform() === AgentPlatform::SHADOWSNAP()) {
                $shadowProtectLicenseManager->releaseUnconditionally();
            }
        } catch (Throwable $e) {
            $this->logger->error('DAS0029 An exception was thrown while disassociating shadow snap license', ['exception' => $e]);
        }
    }

    /**
     * Remove all key files associated with this agent.
     *
     * @param Agent $agent
     */
    private function removeKeyFiles(Agent $agent): void
    {
        try {
            $assetKey = $agent->getKeyName();

            $this->logger->debug('DAS0030 Destroying agent key files');
            $this->agentRepository->destroy($assetKey);

            $pairName = $agent->getPairName();
            $this->logger->debug('DAS0031 Destroying agent pairName UUID key file', ['pairName' => $pairName]);
            $agentConfig = $this->agentConfigFactory->create($pairName);
            $agentConfig->clear('uuid');
        } catch (Throwable $e) {
            $this->logger->error('DAS0032 An exception was thrown while attempting to destroy the agent keyfiles', ['exception' => $e]);
        }
    }

    /**
     * Remove any filesystem integrity check report files associated with this agent
     *
     * @param Agent $agent
     */
    private function removeFilesystemIntegrityCheckReports(Agent $agent): void
    {
        $assetKey = $agent->getKeyName();
        $this->filesystemIntegrityCheckReportService->destroyAssetReports($assetKey);
    }

    /**
     * Remove any screenshots that are associated with this agent.
     *
     * @param Agent $agent
     */
    private function removeScreenshots(Agent $agent): void
    {
        try {
            $screenshots = $this->screenshotFileRepository->getAllByKeyName($agent->getKeyName());
            foreach ($screenshots as $screenshot) {
                $this->screenshotFileRepository->remove($screenshot);
            }
        } catch (Throwable $e) {
            $this->logger->error('DAS0033 An exception was thrown while attempting to cleanup screenshot files', ['exception' => $e]);
        }
    }

    /**
     * If this is a replicated asset, remove speedsync key for this asset
     *
     * @param Agent $agent
     */
    private function disableReplication(Agent $agent): void
    {
        $this->speedSyncAuthorizedKeysService->remove($agent->getKeyName());
    }

    /**
     * Removes leftover mercury target (includes luns)
     * @param string $agentKey
     */
    private function destroyMercuryTargetAndLuns(string $agentKey): void
    {
        $this->logger->debug('DAS0034 Removing any leftover mercury luns for agent');
        $targetName = $this->mercuryFtpTarget->makeTargetNameTemp($agentKey);

        try {
            $this->mercuryFtpTarget->deleteTarget($targetName);
        } catch (MercuryTargetDoesNotExistException $e) {
            $this->logger->debug('DAS0035 No leftover mercury luns for agent');
        } catch (Throwable $t) {
            $this->logger->error('DAS0036 Error removing mercury luns for agent', ['exception' => $t]);
        }
    }
}
