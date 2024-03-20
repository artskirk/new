<?php

namespace Datto\Restore\File;

use Datto\Asset\Agent\Encryption\TempAccessService;
use Datto\Asset\Agent\EncryptionService;
use Datto\Asset\Agent\MountHelper;
use Datto\Asset\AssetService;
use Datto\Asset\SambaShareAuthentication;
use Datto\File\FileEntry;
use Datto\File\FileEntryService;
use Datto\Log\LoggerFactory;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Datto\Restore\AssetCloneManager;
use Datto\Restore\CloneSpec;
use Datto\Restore\File\Stages\BindSftpStage;
use Datto\Restore\File\Stages\CloneFileRestoreStage;
use Datto\Restore\File\Stages\HideFilesStage;
use Datto\Restore\File\Stages\MountFileRestoreStage;
use Datto\Restore\File\Stages\SambaFileRestoreStage;
use Datto\Restore\File\Stages\SaveFileRestoreStage;
use Datto\Restore\FileExclusionService;
use Datto\Restore\Restore;
use Datto\Restore\RestoreService;
use Datto\Restore\RestoreType;
use Datto\Samba\SambaManager;
use Datto\Security\PasswordGenerator;
use Datto\Sftp\SftpManager;
use Datto\System\MountManager;
use Datto\System\Transaction\Stage;
use Datto\System\Transaction\Transaction;
use Datto\System\Transaction\TransactionException;
use Datto\System\Transaction\TransactionFailureType;
use Datto\User\ShadowUser;
use Datto\Resource\DateTimeService;
use Datto\Util\DateTimeZoneService;
use Datto\Common\Utility\Filesystem;
use Datto\Restore\RestoreFactory;
use Datto\Util\RetryHandler;
use Datto\Utility\File\Lock;
use Datto\Utility\File\LockFactory;
use Datto\Utility\File\LockInfo;
use Datto\Utility\Security\SecretString;
use Exception;

/**
 * Main entry point for managing file restores.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class FileRestoreService
{
    const DOWNLOAD_TOKEN_DIR = '/dev/shm/restore/file/download/tokens';
    const DOWNLOAD_TOKEN_LENGTH = 64; // If changed, change in download application too
    const MOUNT_POINT_TEMPLATE = '/homePool/%s-%s-%s';

    private AssetService $assetService;
    private AssetCloneManager $assetCloneManager;
    private MountHelper $mountHelper;
    private SambaManager $sambaManager;
    private SambaShareAuthentication $sambaShareAuthentication;
    private DateTimeService $dateTimeService;
    private DateTimeZoneService $dateTimeZoneService;
    private RestoreService $restoreService;
    private RestoreFactory $restoreFactory;
    private Filesystem $filesystem;
    private EncryptionService $encryptionService;
    private TempAccessService $tempAccessService;
    private LoggerFactory $loggerFactory;
    private MountManager $mountManager;
    private FileEntryService $fileEntryService;
    private SftpManager $sftpManager;
    private ShadowUser $shadowUser;
    private FileExclusionService $fileExclusionService;
    private Collector $collector;
    private LockFactory $lockFactory;
    private RetryHandler $retryHandler;

    public function __construct(
        AssetService $assetService,
        AssetCloneManager $assetCloneManager,
        MountHelper $mountHelper,
        SambaManager $sambaManager,
        SambaShareAuthentication $sambaShareAuthentication,
        DateTimeService $dateTimeService,
        DateTimeZoneService $dateTimeZoneService,
        RestoreService $restoreService,
        RestoreFactory $restoreFactory,
        Filesystem $filesystem,
        EncryptionService $encryptionService,
        TempAccessService $tempAccessService,
        LoggerFactory $loggerFactory,
        MountManager $mountManager,
        FileEntryService $fileEntryService,
        SftpManager $sftpManager,
        ShadowUser $shadowUser,
        FileExclusionService $fileExclusionService,
        Collector $collector,
        LockFactory $lockFactory,
        RetryHandler $retryHandler
    ) {
        $this->assetService = $assetService;
        $this->assetCloneManager = $assetCloneManager;
        $this->mountHelper = $mountHelper;
        $this->sambaManager = $sambaManager;
        $this->sambaShareAuthentication = $sambaShareAuthentication;
        $this->dateTimeService = $dateTimeService;
        $this->dateTimeZoneService = $dateTimeZoneService;
        $this->restoreService = $restoreService;
        $this->restoreFactory = $restoreFactory;
        $this->filesystem = $filesystem;
        $this->encryptionService = $encryptionService;
        $this->tempAccessService = $tempAccessService;
        $this->loggerFactory = $loggerFactory;
        $this->mountManager = $mountManager;
        $this->fileEntryService = $fileEntryService;
        $this->sftpManager = $sftpManager;
        $this->shadowUser = $shadowUser;
        $this->fileExclusionService = $fileExclusionService;
        $this->collector = $collector;
        $this->lockFactory = $lockFactory;
        $this->retryHandler = $retryHandler;
    }

    public function create(
        string $assetKey,
        int $snapshot,
        SecretString $passphrase = null,
        bool $withSftp = false
    ): Restore {
        $asset = $this->assetService->get($assetKey);
        $this->collector->increment(Metrics::RESTORE_STARTED, [
            'type' => Metrics::RESTORE_TYPE_FILE_RESTORE,
            'is_replicated' => $asset->getOriginDevice()->isReplicated(),
        ]);

        $logger = $this->loggerFactory->getAsset($assetKey);
        $logger->info('FIR0021 Creating file restore ...'); // log code is used by device-web see DWI-2252

        $context = new FileRestoreContext($asset, $snapshot, $passphrase, $withSftp);

        $stages = $this->getStages($context);
        $transaction = new Transaction(TransactionFailureType::STOP_ON_FAILURE(), $logger);

        foreach ($stages as $stage) {
            $transaction->add($stage);
        }

        try {
            $transaction->commit();
            $logger->info('FIR0022 File restore created.');
            return $context->getRestore();
        } catch (TransactionException $e) {
            throw $e->getPrevious() ?? $e;
        }
    }

    /**
     * Only mounts
     * @param Restore $restore
     */
    public function repair(Restore $restore)
    {
        $asset = $this->assetService->get($restore->getAssetKey());
        $logger = $this->loggerFactory->getAsset($restore->getAssetKey());

        $context = new FileRestoreContext(
            $asset,
            $restore->getPoint(),
            null,
            isset($restore->getOptions()['sftp']),
            true
        );
        $context->setCloneSpec(CloneSpec::fromAsset($asset, $restore->getPoint(), $restore->getSuffix()));
        $context->setRestore($restore);

        $stages = $this->getStages($context);
        $transaction = new Transaction(TransactionFailureType::STOP_ON_FAILURE(), $logger);

        foreach ($stages as $stage) {
            $transaction->add($stage);
        }

        try {
            $transaction->commit();
        } catch (TransactionException $e) {
            throw $e->getPrevious() ?? $e;
        }
    }

    /**
     * @param string $assetKey
     * @param int $snapshot
     */
    public function remove(string $assetKey, int $snapshot)
    {
        $logger = $this->loggerFactory->getAsset($assetKey);
        $logger->info('FIR0020 Destroying file restore ...'); // log code is used by device-web see DWI-2252

        /** @var Restore $restore */
        $restore = $this->restoreService->find($assetKey, $snapshot, RestoreType::FILE);

        if ($restore) {
            $logger->debug('FIR0000 Restore found');
            $options = $restore->getOptions();
            $asset = $this->assetService->get($assetKey);
            $cloneSpec = CloneSpec::fromAsset($asset, $snapshot, RestoreType::FILE);

            if (isset($options['sftp'])) {
                $sftpUser = $options['sftp']['username'];

                $logger->info('FIR0019 Killing any active processes for the SFTP user', ['user' => $sftpUser]);
                $this->sftpManager->killConnections($sftpUser);

                $logger->info('FIR0015 Unbinding SFTP user account ...');
                $this->sftpManager->unmount($sftpUser, $assetKey);

                if ($this->shadowUser->exists($sftpUser)) {
                    $logger->info('FIR0016 Deleting SFTP user account ...');
                    $this->shadowUser->delete($sftpUser);
                }

                $logger->info('FIR0017 Stopping SFTP service if applicable ...');
                $this->sftpManager->stopIfNoUsers();
            }

            $logger->debug('FIR0001 Removing samba share ...');
            $lock = $this->lockFactory->create(LockInfo::SAMBA_LOCK_FILE);
            $lock->assertExclusiveAllowWait(Lock::DEFAULT_LOCK_WAIT_TIME);
            $this->sambaManager->reload();
            $this->sambaManager->removeShareByPath($restore->getMountDirectory());
            $lock->unlock();

            try {
                $logger->debug('FIR0002 Unmounting directory tree ...');
                $this->mountHelper->unmount($asset->getKeyName(), $snapshot, RestoreType::FILE);
            } catch (\Exception $e) {
                $logger->warning('FIR0003 Could not unmount directory tree', ['error' => $e->getMessage()]);
            }

            // We might be able to destroy the clone anyway, let's try ...

            if ($this->assetCloneManager->exists($cloneSpec)) {
                $logger->debug('FIR0004 Destroying file restore clone ...');
                $this->assetCloneManager->flushBuffers($cloneSpec->getTargetDatasetName());
                $this->assetCloneManager->destroyClone($cloneSpec);
            }

            $logger->debug('FIR0005 Removing restore entry ...');
            $lock = $this->lockFactory->create(LockInfo::RESTORE_LOCK_FILE);
            $lock->assertExclusiveAllowWait(Lock::DEFAULT_LOCK_WAIT_TIME);
            $this->restoreService->getAll();
            $this->restoreService->remove($restore);
            $this->restoreService->save();
            $lock->unlock();

            $logger->info('FIR0018 File restore removed.');
        } else {
            $logger->warning('FIR0006 No restore found.');
        }
    }

    /**
     * @param string $assetKey
     * @param int $snapshot
     * @return bool
     */
    public function hasClients(string $assetKey, int $snapshot): bool
    {
        /** @var Restore $restore */
        $restore = $this->restoreService->find($assetKey, $snapshot, RestoreType::FILE);

        if ($restore) {
            $shares = $this->sambaManager->getSharesByPath($restore->getMountDirectory());

            foreach ($shares as $share) {
                $hasClients = !empty($this->sambaManager->getOpenClientConnections($share->getName()));

                if ($hasClients) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param FileRestoreContext $context
     * @return Stage[]
     */
    private function getStages(FileRestoreContext $context)
    {
        $logger = $this->loggerFactory->getAsset($context->getAsset()->getKeyName());

        $stages = [];

        if (!$context->getRepairMode()) {
            $stages[] = new CloneFileRestoreStage(
                $context,
                $logger,
                $this->assetCloneManager
            );

            $stages[] = new HideFilesStage(
                $context,
                $logger,
                $this->fileExclusionService
            );
        }

        $stages[] = new MountFileRestoreStage(
            $context,
            $logger,
            $this->mountHelper,
            $this->encryptionService,
            $this->tempAccessService,
            $this->dateTimeService,
            $this->dateTimeZoneService,
            $this->filesystem,
            $this->mountManager,
            $this->retryHandler
        );

        $stages[] = new SambaFileRestoreStage(
            $context,
            $logger,
            $this->sambaManager,
            $this->sambaShareAuthentication,
            $this->dateTimeService,
            $this->dateTimeZoneService,
            $this->lockFactory
        );

        if ($context->getWithSftp()) {
            $stages[] = new BindSftpStage(
                $context,
                $logger,
                $this->restoreService,
                $this->sftpManager,
                $this->shadowUser
            );
        }

        if (!$context->getRepairMode()) {
            $stages[] = new SaveFileRestoreStage(
                $context,
                $logger,
                $this->restoreService,
                $this->restoreFactory,
                $this->dateTimeService,
                $this->lockFactory
            );
        }

        return $stages;
    }

    /**
     * Create a file download token (and a corresponding token file)
     * to be used by the file download application (used for Cloud Devices).
     *
     * @param string $assetKey Asset key name / UUID
     * @param int $snapshot Unix timestamp / snapshot name
     * @param string $path Relative path inside the mount point
     * @return string Random file download token
     */
    public function createToken(string $assetKey, int $snapshot, string $path): string
    {
        $mountpoint = $this->getMountpoint($assetKey, $snapshot, $path);
        $file = "$mountpoint/$path";

        if (!$this->filesystem->exists($file) || $this->filesystem->isLink($file)) {
            throw new Exception();
        }

        $token = PasswordGenerator::generate(self::DOWNLOAD_TOKEN_LENGTH);
        $tokenFile = self::DOWNLOAD_TOKEN_DIR . '/' . $token;

        $this->filesystem->mkdirIfNotExists(self::DOWNLOAD_TOKEN_DIR, true, 0770);
        $this->filesystem->filePutContents($tokenFile, json_encode([
            'file' => $file
        ]));

        return $token;
    }

    /**
     * Returns a list of file entries for a restore in the given
     * sub-path.
     *
     * @param string $assetKey Asset key name / UUID
     * @param int $snapshot Unix timestamp / snapshot name
     * @param string $path Relative path inside the mount point
     * @param int $depth How deep we should traverse (min: 1, max: 3)
     * @return FileEntry[]
     */
    public function browse(string $assetKey, int $snapshot, string $path, int $depth = 1): array
    {
        $mountpoint = $this->getMountpoint($assetKey, $snapshot, $path);
        return $this->fileEntryService->getFileEntriesFromDir($mountpoint, $path, $depth);
    }

    /**
     * Return local mount point for a mounted file restore,
     * and check the given path for directory traversal.
     *
     * Note: The 'path' is not included in the returned mountpoint
     * path. It is merely used for directory traversal checks.
     *
     * @param string $assetKey Asset key name / UUID
     * @param int $snapshot Unix timestamp / snapshot name
     * @param string $path Relative path inside the mount point
     * @return string Mount point, e.g. /datto/mounts/...
     */
    private function getMountpoint(string $assetKey, int $snapshot, string $path): string
    {
        /** @var Restore $restore */

        $asset = $this->assetService->get($assetKey);
        $restore = $this->restoreService->find($assetKey, $snapshot, RestoreType::FILE);

        if (!isset($asset, $restore)) {
            throw new Exception();
        }

        $mountpoint = $restore->getMountDirectory();

        if ($this->filesystem->isDirectoryTraversalAttack($mountpoint, $path)) {
            throw new Exception();
        }

        return $mountpoint;
    }
}
