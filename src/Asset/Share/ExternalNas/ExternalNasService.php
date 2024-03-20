<?php

namespace Datto\Asset\Share\ExternalNas;

use Datto\Asset\Share\ExternalNas\Serializer\BackupProgressSerializer;
use Datto\Asset\Share\Share;
use Datto\Asset\Share\ShareException;
use Datto\Asset\Share\ShareService;
use Datto\Backup\BackupCancelledException;
use Datto\Backup\BackupCancelManager;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\AgentConfigFactory;
use Datto\File\Xattr;
use Datto\Log\LoggerAwareTrait;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Datto\Common\Resource\Sleep;
use Datto\System\Mount;
use Datto\System\MountManager;
use Datto\System\Rsync\MonitorableRsyncProcess;
use Datto\System\Rsync\RsyncResults;
use Datto\System\SambaMount;
use Datto\System\SambaMountBuilder;
use Exception;
use InvalidArgumentException;
use Psr\Log\LoggerAwareInterface;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Class ExternalNasService
 *
 * Provides a means to temporarily mount an external share and rsync to a destination path.
 *
 * @author Andrew Mitchell <amitchell@datto.com>
 */
class ExternalNasService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const FORCE_FULL_ACL_BACKUP_FLAG = 'forceFullAclBackup';
    const MOUNT_ROOT = '/datto/mounts';
    const TEST_MOUNT_PREFIX = 'isMountableTest';
    const PROGRESS_FILE_EXTENSION = '.snapStateJSON';
    const RSYNC_PROGRESS_CHECK_DELAY_SECONDS = 1;
    const RSYNC_HUNG_TIMEOUT_SECONDS = 86400; // 24 hours
    const SYNC_TIMEOUT_SECONDS = 1800; // 30 minutes
    const MKDIR_MODE = 0777;

    private const VALID_RSYNC_EXIT_CODES = [
        RsyncResults::EXIT_CODE_SUCCESS,
        RsyncResults::EXIT_CODE_PARTIAL_TRANSFER_ERROR,
        RsyncResults::EXIT_CODE_PARTIAL_TRANSFER_VANISHED_FILES
    ];

    private MountManager $mountManager;
    private Filesystem $filesystem;
    private MonitorableRsyncProcess $rsyncProcess;
    private BackupProgressSerializer $backupProgressSerializer;
    private XattrService $xattrService;
    private ShareService $shareService;
    private AgentConfigFactory $agentConfigFactory;
    private ProcessFactory $processFactory;
    private BackupCancelManager $backupCancelManager;
    private Sleep $sleep;

    private string $mountPoint = '';

    public function __construct(
        MountManager $mountManager,
        Filesystem $filesystem,
        MonitorableRsyncProcess $rsyncProcess,
        BackupProgressSerializer $backupProgressSerializer,
        XattrService $xattrService,
        ShareService $shareService,
        AgentConfigFactory $agentConfigFactory,
        ProcessFactory $processFactory,
        BackupCancelManager $backupCancelManager,
        Sleep $sleep
    ) {
        $this->mountManager = $mountManager;
        $this->filesystem = $filesystem;
        $this->rsyncProcess = $rsyncProcess;
        $this->backupProgressSerializer = $backupProgressSerializer;
        $this->xattrService = $xattrService;
        $this->shareService = $shareService;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->processFactory = $processFactory;
        $this->backupCancelManager = $backupCancelManager;
        $this->sleep = $sleep;
    }

    public function __destruct()
    {
        $this->unmount();
    }

    /**
     * Tests if the sambaMount is mountable by attempting to mount it
     *
     * @param SambaMount $sambaMount The samba mount to test
     * @return bool True if mountable false otherwise
     */
    public function isMountable(ExternalNasShare $share, SambaMount $sambaMount): bool
    {
        $this->cleanupMounts(static::MOUNT_ROOT . "/" . static::TEST_MOUNT_PREFIX . "-?*-" . Mount::PURPOSE_EXTNAS);

        $this->logger->info("ENS0017 Checking samba mount connection");
        try {
            $uniqueName = $this->filesystem->tempName(static::MOUNT_ROOT, static::TEST_MOUNT_PREFIX . "-");
            if ($uniqueName === false) {
                throw new Exception('Unable to create unique mountpoint for share');
            }
            $this->filesystem->unlink($uniqueName);
            $this->mount(basename($uniqueName), $share, $sambaMount);
            $this->unmount();
        } catch (Exception $e) {
            $this->logger->info("ENS0100 Share not mountable", ['exception' => $e]);
            return false;
        }
        $this->logger->info("ENS0018 Mounting samba share succeeds!");
        return true;
    }

    /**
     * Copy a remote share described by a SambaMount to a destination directory.
     * The $name is used when generating the temporary mount directory name.
     *
     * @param string $name     A unique name for this extnas share
     * @param SambaMount $sambaMount      Information needed to mount a remote share
     * @param string $dstDir   Where the files should be copied to
     * @param int $progressCheckDelay The amount of time, in seconds, to wait between progress queries. This is an
     * optional parameter and defaults to ExternalNasService::RSYNC_PROGRESS_CHECK_DELAY_SECONDS.
     * @param int $hungTimeout The amount of time to allow hung progress before killing the rsync process. This is
     * an optional parameter and defaults to ExternalNasService::RSYNC_HUNG_TIMEOUT_SECONDS.
     * @return true            on success, or an Exception will be thrown
     */
    public function copyShare(
        string $name,
        SambaMount $sambaMount,
        string $dstDir,
        int $progressCheckDelay = self::RSYNC_PROGRESS_CHECK_DELAY_SECONDS,
        int $hungTimeout = self::RSYNC_HUNG_TIMEOUT_SECONDS
    ): bool {
        $share = $this->shareService->get($name);
        if (!$share instanceof ExternalNasShare) {
            throw new Exception(
                'Unexpected share type provided, "ExternalNasShare" type was expected'
            );
        }

        $mountPoint = $this->mount($name, $share, $sambaMount);
        $this->shareService->save($share);

        $backupNtfsAcls = $share->isBackupAclsEnabled();
        try {
            $this->rsync($mountPoint, $dstDir, $name, $progressCheckDelay, $hungTimeout, !$backupNtfsAcls);

            if ($backupNtfsAcls) {
                if ($share->getFormat() !== ExternalNasShare::FORMAT_NTFS) {
                    $this->logger->warning(
                        "ENS0007 External share ZVol is not formatted with NTFS, NTFS permissions may not be retained."
                    );
                }

                if (!$sambaMount->includeCifsAcls()) {
                    $this->logger->warning(
                        "ENS0006 External share was not mounted with CIFS ACL option, NTFS permissions may not be retained."
                    );
                }

                $this->flush($share);
                $this->copyAcls($share, $dstDir);
            }
        } finally {
            $this->unmount();
            $this->flush($share);
        }

        return true;
    }

    /**
     * Get the backup progress for a given share.  If progress can't be read, then returns a default "idle" progress.
     *
     * @param string $shareName
     * @return BackupProgress
     */
    public function getBackupProgress(string $shareName): BackupProgress
    {
        $path = Share::BASE_CONFIG_PATH . '/' . $shareName . self::PROGRESS_FILE_EXTENSION;
        $data = $this->filesystem->fileGetContents($path);
        if ($data !== false) {
            $progress = $this->backupProgressSerializer->unserialize($data);
        } else {
            $progress = new BackupProgress(BackupStatusType::IDLE());
        }
        return $progress;
    }

    /**
     * Attempts to mount the samba share. The name is used to generate a temporary mount directory.
     */
    private function mount(string $name, ExternalNasShare $share, SambaMount $sambaMount): string
    {
        $this->logger->info("ENS0001 Mounting samba share");

        // MountManager expects a special postfix string describing the purpose of the mount
        $mountName = $name . '-' . Mount::PURPOSE_EXTNAS;
        $this->mountPoint = self::MOUNT_ROOT . '/' . $mountName;
        // FYI: an unexpected reboot can leave a mount directory behind
        if (!$this->filesystem->exists($this->mountPoint)) {
            $this->logger->info("ENS0003 Creating mountpoint", ['mountPoint' => $this->mountPoint]);
            $this->filesystem->mkdir($this->mountPoint, false, self::MKDIR_MODE);
        }
        $mountBuilder = new SambaMountBuilder($sambaMount);
        $allowFallback = $share->getSmbVersion() === null && $share->getNtlmAuthentication() === null;

        if ($allowFallback) {
            $this->logger->info("ENS0040 Attempting to authenticate with ntlmv2");
            $mountResult = $this->mountManager->mount($mountBuilder, $this->mountPoint);

            if ($mountResult->mountFailed()) {
                $this->logger->info("ENS0041 Falling back to ntlm");
                $mountBuilder->useSMB1();
                $mountBuilder->useV1();
                $mountResult = $this->mountManager->mount($mountBuilder, $this->mountPoint);
            }

            if ($mountResult->mountFailed()) {
                $this->logger->info("ENS0042 Falling back to ntlmssp");
                $mountBuilder->useSSP();
                $mountResult = $this->mountManager->mount($mountBuilder, $this->mountPoint);
            }
        } else {
            $this->logger->info("ENS0043 Mounting using last known good parameters", [
                'smbVersion' => $share->getSmbVersion(),
                'ntlmAuthentication' => $share->getNtlmAuthentication()
            ]);

            switch ($share->getSmbVersion()) {
                case SambaMountBuilder::SMB_2:
                    $mountBuilder->useSMB2();
                    break;
                case SambaMountBuilder::SMB_1:
                    $mountBuilder->useSMB1();
                    break;
            }
            switch ($share->getNtlmAuthentication()) {
                case SambaMountBuilder::NTLMV2:
                    $mountBuilder->useV2();
                    break;
                case SambaMountBuilder::NTLMV1:
                    $mountBuilder->useV1();
                    break;
                case SambaMountBuilder::NTLMSSP:
                    $mountBuilder->useSSP();
                    break;
            }

            $mountResult = $this->mountManager->mount($mountBuilder, $this->mountPoint);
        }

        if ($mountResult->mountFailed()) {
            $this->logger->error("ENS0004 Mount failed", ['exitCode' => $mountResult->getExitCode(), 'output' => $mountResult->getMountOutput()]);
            $this->logger->info("ENS0019 Failed mount command", ['executedCommand' => $mountResult->getExecutedCommand()]);
            if (!$allowFallback) {
                $this->logger->error(
                    "ENS0050 Unable to mount extenal share using previously saved parameters",
                    [
                        'smbVersion' => $share->getSmbVersion(),
                        'ntlmAuthentication' => $share->getNtlmAuthentication()
                    ]
                );
            }

            throw new ShareException('Unable to mount share.');
        }

        if ($allowFallback) {
            $share->setSmbVersion($mountBuilder->smbVersion());
            $share->setNtlmAuthentication($mountBuilder->securityOption());
        }

        $this->logger->info("ENS0005 Mounted share", [
            'mountPoint' => $this->mountPoint,
            'smbVersion' => $share->getSmbVersion(),
            'ntlmAuthentication' => $share->getNtlmAuthentication()
        ]);

        return $this->mountPoint;
    }

    /**
     * Runs an rsync command to sync the src directory and the dst directory.
     * Depending on the amount of data being copied, this command may take quite some time
     * to finish. Security Note: Nothing special is done with the destination file permissions,
     * resulting in files which may be world readable on the local device.
     * todo: tighten security of files copied to the local device.
     *
     * @param string $srcDir
     * @param string $dstDir
     * @param string $shareName The name of the share asset being synced
     * @param int $progressCheckDelay The amount of time, in seconds, to wait between progress queries. This is an
     * optional parameter and defaults to ExternalNasService::RSYNC_PROGRESS_CHECK_DELAY_SECONDS.
     * @param int $hungTimeout The amount of time to allow hung progress before killing the rsync process. This is
     * an optional parameter and defaults to ExternalNasService::RSYNC_HUNG_TIMEOUT_SECONDS.
     * @param bool $copyPosixAcls If true rsync will copy posix user, group, and permissions.
     */
    private function rsync(
        $srcDir,
        $dstDir,
        $shareName,
        $progressCheckDelay = self::RSYNC_PROGRESS_CHECK_DELAY_SECONDS,
        $hungTimeout = self::RSYNC_HUNG_TIMEOUT_SECONDS,
        $copyPosixAcls = true
    ): void {
        $share = $this->shareService->get($shareName);

        $adjustedSrcDir = $srcDir;

        // the src path must end in / to avoid an unnecessary parent directory being created in the destination
        if (substr($srcDir, -1) != '/') {
            $adjustedSrcDir = $adjustedSrcDir . '/';
        }

        $this->rsyncProcess->startProcess($adjustedSrcDir, $dstDir, $copyPosixAcls);
        $secondsWithoutProgress = 0;
        $previousBytesTransferred = 0;

        $backupProgress = new BackupProgress(BackupStatusType::INITIALIZING());
        $this->saveBackupProgress($shareName, $backupProgress);

        while ($this->rsyncProcess->isRunning() && $secondsWithoutProgress <= $hungTimeout) {
            $progress = $this->rsyncProcess->getProgressData();

            if ($this->backupCancelManager->isCancelling($share)) {
                $this->rsyncProcess->killProcess();
                $this->logger->info('ENS1000 Backup was cancelled, rsync process killed');
                throw new BackupCancelledException("Backup was cancelled by user");
            } elseif ($progress->getBytesTransferred() === 0) {
                $this->logger->debug("ENS0022 Initializing rsync transfer.");
            } else {
                $backupProgress = new BackupProgress(
                    BackupStatusType::IN_PROGRESS(),
                    $progress->getBytesTransferred(),
                    $progress->getTransferRate()
                );
                $this->saveBackupProgress($shareName, $backupProgress);
                $this->logger->debug("ENS0023 rsync transferred "  . $progress->getBytesTransferred() . " bytes at " .
                    $progress->getTransferRate() . ".");
            }

            $this->sleep->sleep($progressCheckDelay);

            if ($progress->getBytesTransferred() !== $previousBytesTransferred) {
                $secondsWithoutProgress = 0;
            } else {
                $secondsWithoutProgress += $progressCheckDelay;
            }
            $previousBytesTransferred = $progress->getBytesTransferred();
        }

        $progress = $this->rsyncProcess->getProgressData();
        $backupProgress = new BackupProgress(
            BackupStatusType::IDLE(),
            $progress->getBytesTransferred(),
            $progress->getTransferRate()
        );
        $this->saveBackupProgress($shareName, $backupProgress);

        if ($secondsWithoutProgress > $hungTimeout && $this->rsyncProcess->isRunning()) {
            $this->logger->error("ENS0025 Maximum hung time exceeded; killing rsync process.");
            $this->rsyncProcess->killProcess();
        }
        $results = $this->rsyncProcess->getResults();
        if (!in_array($results->getExitCode(), self::VALID_RSYNC_EXIT_CODES)) {
            $context = ['exitCode' => $results->getExitCode(), 'exitCodeText' => $results->getExitCodeText()];
            if ($results->getExitCode() == $results::EXIT_CODE_TIMEOUT_WAITING_SYNC) {
                $this->logger->error('ENS0039 rsync was still running when timeout occurred', $context);
            } else {
                $this->logger->error('ENS0024 rsync exited with error status', $context);
            }
            throw new RuntimeException('rsync exited with error status ' . $results->getExitCode() . ': ' . $results->getExitCodeText());
        }

        $errorOutput = $results->getErrorOutput();
        if (!empty($errorOutput)) {
            $this->logger->error("ENS0033 rsync error output", ['errorOutput' => $errorOutput]);
        }

        if ($results->getExitCode() === RsyncResults::EXIT_CODE_PARTIAL_TRANSFER_ERROR) {
            $standardError = $this->rsyncProcess->getErrorOutput();
            if (strpos($standardError, 'Permission denied') !== false) {
                $this->logger->error('ENS0027 One or more files were copied unsuccessfully due to lack of permissions.');
            }
        }

        $this->logger->info("ENS0026 rsync process exited", ['exitCode' => $results->getExitCode(), 'exitCodeText' => $results->getExitCodeText()]);

        $this->logger->debug("ENS0014 rsync stats are: " . trim(str_replace("\n", "  ", $results->getStatsOutput())));
    }

    /**
     * Copies ACLs from the CIFS mount (by way of the xattr system.cifs_acl) to our backup (by way of the xattr
     * system.ntfs_acl)
     *
     * @param ExternalNasShare $share
     * @param string $destDir
     */
    private function copyAcls(ExternalNasShare $share, string $destDir): void
    {
        $this->saveBackupProgress($share->getKeyName(), new BackupProgress(
            BackupStatusType::COPYING_ACLS()
        ));

        $filesWithChangedAcls = $this->getFilesWithChangedAcls($share);
        $filesCount = count($filesWithChangedAcls);

        if ($filesCount > 0) {
            $this->logger->info('ENS0030 Copying file ACLs', ['filesCount' => $filesCount]);

            // The Linux CIFS driver and NTFS-3G use the same format for windows ACLs so we can just
            // copy from one xattr to the other and the ACLs will be preserved.
            $this->xattrService->copyXattrsFiles(
                Xattr::ATTR_CIFS,
                Xattr::ATTR_NTFS,
                $this->mountPoint,
                $destDir,
                $filesWithChangedAcls
            );
        } else {
            $this->logger->info('ENS0031 No changed files, not copying ACLs.');
        }
    }

    /**
     * Unmount and remove the temporary mount directory.
     */
    private function unmount(): void
    {
        if (empty($this->mountPoint)) {
            return;
        }

        try {
            if ($this->filesystem->exists($this->mountPoint)) {
                if ($this->mountManager->isMounted($this->mountPoint)) {
                    $this->logger->info("ENS0010 Unmounting samba share", ['mountPoint' => $this->mountPoint]);
                    $this->mountManager->unmount($this->mountPoint);
                }
                $this->filesystem->unlinkDir($this->mountPoint);
            }
        } catch (Exception $e) {
            $this->logger->error("ENS0021 Unable to unmount samba share", ['mountPoint' => $this->mountPoint, 'exception' => $e]);
        }
    }

    /**
     * Unmount and unlink the mounts on the filesystem that match $mountGlob
     *
     * @param string $mountGlob Glob of mounts to cleanup. Follows the syntax for filesystem->glob()
     */
    private function cleanupMounts(string $mountGlob): void
    {
        $mounts = $this->filesystem->glob($mountGlob) ?: [];
        foreach ($mounts as $mount) {
            try {
                $this->logger->info("ENS0020 Cleaning up old mount", ['mount' => $mount]);
                $this->mountManager->unmount($mount);
                $this->filesystem->unlinkDir($mount);
            } catch (Exception $e) {
                try {
                    // unmount may have failed because it was already unmounted
                    $this->filesystem->unlinkDir($mount);
                } catch (Exception $e) {
                    $this->logger->error("ENS0021 Error unmounting", ['mount' => $mount]);
                }
            }
        }
    }

    /**
     * Save backup progress for a share to a file on disk.
     *
     * @param string $shareName
     * @param BackupProgress $progress
     */
    private function saveBackupProgress($shareName, BackupProgress $progress): void
    {
        $data = $this->backupProgressSerializer->serialize($progress);
        $path = Share::BASE_CONFIG_PATH . '/' . $shareName . self::PROGRESS_FILE_EXTENSION;
        $this->filesystem->filePutContents($path, $data);
    }

    /**
     * Get a list of files with potentially changed xattrs.
     *
     * @param ExternalNasShare $share
     * @return string[]
     */
    private function getFilesWithChangedAcls(ExternalNasShare $share): array
    {
        $point = $share->getLocal()->getRecoveryPoints()->getLast();
        $latestSnapshot = $point ? $point->getEpoch() : null;
        $forceFullAclBackup = $this->checkAndClearForcedFullAclBackup($share);

        if ($forceFullAclBackup || $latestSnapshot === null) {
            // Copy all ACLs if first backup or we should perform full ACL backup

            if ($forceFullAclBackup) {
                $this->logger->debug('ENS0101 Force full ACL backup flag detected');
            }

            $this->logger->debug("ENS0102 Enumerating all files ...");
            $filesWithChangedAcls = $this->filesystem->enumerateRelativeFiles($this->mountPoint);
        } else {
            $latestSnapshotMinus11Hrs = ($latestSnapshot - (DateTimeService::SECONDS_PER_HOUR * 11));
            $this->logger->debug("ENS0103 Enumerating files with ctime greater than $latestSnapshotMinus11Hrs");

            $filter = function (SplFileInfo $file) use ($latestSnapshotMinus11Hrs): bool {
                try {
                    // Figure out if the current ctime for the file was updated up to 11hrs before the previous
                    // snapshot, which indicates file metadata (like the ACL) has been changed, even if the file
                    // contents haven't changed.  This will help in cases where the timezone on the protected system
                    // doesn't match the timezone on the device.  Windows and Linux handle timezones differently in
                    // regard to UTC, so by including all ACLs that have changed in the past 11 hours, we will ensure
                    // that we have captured all ACL changes that have happened between backups, regardless of timezone.
                    // We use 11 hours because timezones vary from -11 to +14 UTC.
                    return $file->getCTime() > $latestSnapshotMinus11Hrs;
                } catch (Throwable $e) {
                    return true;
                }
            };
            $filesWithChangedAcls = $this->filesystem->enumerateRelativeFiles($this->mountPoint, $filter);
        }

        return $filesWithChangedAcls;
    }

    /**
     * @param ExternalNasShare $share
     * @return bool
     */
    private function checkAndClearForcedFullAclBackup(ExternalNasShare $share): bool
    {
        $agentConfig = $this->agentConfigFactory->create($share->getKeyName());

        $forcedAclBackup = $agentConfig->has(self::FORCE_FULL_ACL_BACKUP_FLAG);
        if ($forcedAclBackup) {
            $agentConfig->clear(self::FORCE_FULL_ACL_BACKUP_FLAG);
        }

        return $forcedAclBackup;
    }

    /**
     * @param Share $share
     */
    private function flush(Share $share): void
    {
        $dataset = $share->getDataset();
        $mountpoint = $dataset->getMountPoint();

        $this->processFactory
            ->get(['sync', $mountpoint])
            ->setTimeout(self::SYNC_TIMEOUT_SECONDS)
            ->mustRun();
    }
}
