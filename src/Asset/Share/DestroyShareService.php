<?php

namespace Datto\Asset\Share;

use Datto\Asset\AssetRemovalConflict;
use Datto\Asset\RecoveryPoint\DestroySnapshotReason;
use Datto\Backup\BackupManagerFactory;
use Datto\Cloud\SpeedSync;
use Datto\Cloud\SpeedSyncMaintenanceService;
use Datto\Log\DeviceLoggerInterface;
use Datto\Log\LoggerAwareTrait;
use Datto\Replication\SpeedSyncAuthorizedKeysService;
use Datto\Restore\RestoreService;
use Datto\Service\Backup\BackupQueueService;
use Datto\Util\Email\EmailService;
use Datto\Util\Email\Generator\RemoveAssetEmailGenerator;
use Datto\Utility\Cloud\RemoteDestroyException;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Service for handling destroying shares.
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class DestroyShareService implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    private RestoreService $restoreService;
    private SpeedSync $speedSync;
    private ShareRepository $shareRepository;
    private RemoveAssetEmailGenerator $removeAssetEmailGenerator;
    private EmailService $emailService;
    private SpeedSyncAuthorizedKeysService $speedSyncAuthorizedKeysService;
    private BackupManagerFactory $backupManagerFactory;
    private BackupQueueService $backupQueueService;

    /** @var SpeedSyncMaintenanceService */
    private $speedSyncMaintenanceService;

    public function __construct(
        SpeedSync $speedSync,
        RestoreService $restoreService,
        RemoveAssetEmailGenerator $removeAssetEmailGenerator,
        EmailService $emailService,
        ShareRepository $shareRepository,
        SpeedSyncAuthorizedKeysService $speedSyncAuthorizedKeysService,
        BackupManagerFactory $backupManagerFactory,
        BackupQueueService $backupQueueService,
        SpeedSyncMaintenanceService $speedSyncMaintenanceService
    ) {
        $this->restoreService = $restoreService;
        $this->speedSync = $speedSync;
        $this->shareRepository = $shareRepository;
        $this->removeAssetEmailGenerator = $removeAssetEmailGenerator;
        $this->emailService = $emailService;
        $this->speedSyncAuthorizedKeysService = $speedSyncAuthorizedKeysService;
        $this->backupManagerFactory = $backupManagerFactory;
        $this->backupQueueService = $backupQueueService;
        $this->speedSyncMaintenanceService = $speedSyncMaintenanceService;
    }

    /**
     * Assert that a share has nothing that blocks its destruction.
     *
     * @param Share $share
     */
    public function assertCanDestroy(Share $share): void
    {
        $this->preflightChecks($share);
    }

    /**
     * Destroy a share.
     *
     * @param Share $share
     * @param bool $force If true, bypass some checks and try to remove
     * @param bool $preserveDataset
     */
    public function destroy(Share $share, bool $force, bool $preserveDataset): void
    {
        $shareName = $share->getKeyName();
        $isReplicated = $share->getOriginDevice()->isReplicated();

        $this->logger->setAssetContext($share->getKeyName());
        $this->logger->info('SHS0002 Attempting to destroy share', ['shareName' => $shareName]); // log code is used by device-web see DWI-2252
        try {
            $this->preflightChecks($share);

            $zfsPath = $share->getDataset()->getZfsPath();

            $this->speedSyncMaintenanceService->pauseAsset($shareName, true);
            $this->destroyOffsiteZfsDatasets($zfsPath, $force);

            $this->cancelBackups($share);

            $share->destroy($preserveDataset);

            $this->shareRepository->destroy($shareName);

            if ($isReplicated) {
                $this->speedSyncAuthorizedKeysService->remove($shareName);
            } else {
                $email = $this->removeAssetEmailGenerator->generate('share', $share->getDisplayName());
                $this->emailService->sendEmail($email);
            }
        } catch (Throwable $e) {
            $this->logger->error('SHR1500 Share removal failed.', ['exception' => $e]);
            throw $e;
        }

        $this->logger->info('SHS0003 Share successfully destroyed.', ['share' => $shareName]); // log code is used by device-web see DWI-2252
    }

    /**
     * Run preflight checks to ensure no restores exist for a share and they are not replicated.
     *
     * @param Share $share
     */
    private function preflightChecks(Share $share): void
    {
        $restores = $this->restoreService->getForAsset($share->getKeyName());

        if (count($restores) > 0) {
            throw new AssetRemovalConflict('This share has an active restore and cannot be removed');
        }
    }

    /**
     * Cancel backups and block until backups are dead and stop writing (or 10 attempts are made)
     *
     * @param Share $share
     */
    private function cancelBackups(Share $share): void
    {
        // Make sure another backup isn't ready to start right after we cancel the current backup
        $this->backupQueueService->clearQueuedBackup($share->getKeyName());
        $backupManager = $this->backupManagerFactory->create($share);
        $backupManager->cancelBackupWaitUntilCancelled();
    }

    private function destroyOffsiteZfsDatasets($zfsPath, bool $force): void
    {
        try {
            $this->logger->debug('SHS0020 Destroying remote datasets', ['zfsPath' => $zfsPath]);
            $this->speedSync->remoteDestroy($zfsPath, DestroySnapshotReason::MANUAL());
        } catch (Throwable $e) {
            $this->logger->error('SHS0022 Error while attempting to destroy the remote dataset', ['zfsPath' => $zfsPath, 'exception' => $e]);

            // Only fail if the error is something the partner can take action on to resolve themselves.
            // Otherwise allow the data to be orphaned offsite.
            $userResolvableFailure = in_array($e->getCode(), RemoteDestroyException::USER_RESOLVABLE_ERRORS);

            if ($force) {
                $this->logger->warning('SHS0040 Continuing removal despite error since "force" has been specified.');
            } elseif ($userResolvableFailure) {
                throw $e;
            } else {
                $this->logger->warning('SHS0041 Continuing removal despite error since the problem is not user resolvable.');
            }
        }
    }
}
