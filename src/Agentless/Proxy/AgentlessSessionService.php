<?php

namespace Datto\Agentless\Proxy;

use Datto\Agentless\Proxy\Exceptions\SessionBusyException;
use Datto\Agentless\Proxy\Exceptions\SessionNotFoundException;
use Datto\Core\Security\Cipher;
use Datto\Feature\FeatureService;
use Datto\Log\LoggerAwareTrait;
use Datto\Utility\File\Lock;
use Datto\Utility\File\LockFactory;
use Datto\Common\Resource\PosixHelper;
use Datto\Security\SecretFile;
use Datto\System\Hardware;
use Datto\Utility\Screen;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Datto\Util\RetryHandler;
use Datto\Common\Resource\Sleep;
use Datto\Virtualization\HypervisorType;
use Datto\Virtualization\VmwareApiClient;
use Datto\Virtualization\Exceptions\BypassedVcenterException;
use Psr\Log\LoggerAwareInterface;
use Datto\Log\DeviceLoggerInterface;
use RuntimeException;
use Throwable;
use Vmwarephp\Extensions\VirtualMachine;
use Vmwarephp\Vhost;

/**
 * Main entry point to deal with everything related to agentless backup sessions.
 *
 * - Session creation/initialization (background and foreground),
 * - Session retrieval (load from cached information on disk),
 * - Session status retrieval
 * - Session cleanup
 *
 * This class provides access to the session lock, any method that could modify the state of the session
 * in any way should acquire it to make sure there's only one process modifying the state of the session at a time.
 *
 * A fully initialized session means we can access the VM's disk data in a consistent way.
 * It stores all the necessary data that we need to perform actions with it and clean everything up
 *
 * @author Mario Rial <mrial@datto.com>
 */
class AgentlessSessionService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const SESSION_BASE_PATH = '/tmp/agentless/';
    public const SESSION_PATH_FORMAT = self::SESSION_BASE_PATH . '%s';
    public const SESSION_LOG_PATH_FORMAT = self::SESSION_BASE_PATH . '%s/session.log';
    public const SESSION_INIT_PID_FORMAT = self::SESSION_BASE_PATH . '%s/sessionInit.pid';
    public const BACKUP_RUNNING_PID_FORMAT = self::SESSION_BASE_PATH . '%s/backupRunning.pid';
    public const BACKGROUND_TASK_START_TIMEOUT_SECONDS = 10;

    private const SESSION_LOCK_PATH_FORMAT = self::SESSION_BASE_PATH . '%s/session.lock';
    private const VDDK_MOUNT_PATH_FORMAT = self::SESSION_BASE_PATH . '%s/vddk';
    private const SESSION_INFO_PATH_FORMAT = self::SESSION_BASE_PATH . '%s/sessionInfo';
    private const ESX_INFO_PATH_FORMAT = self::SESSION_BASE_PATH . '%s/esxInfo';
    private const AGENT_INFO_PATH_FORMAT = self::SESSION_BASE_PATH . '%s/agentInfo';
    private const BACKUP_RUNNING_JOB_FORMAT = self::SESSION_BASE_PATH . '%s/backupRunning.job';
    # When we cleanup the session we move the files here, so we have them for debugging purposes.
    private const OLD_SESSION_FILES_PATH_FORMAT = self::SESSION_BASE_PATH . '%s/old';
    private const MKDIR_MODE = 0777;

    private Cipher $cipher;
    private EsxVmInfoService $esxVmInfoService;
    private Filesystem $filesystem;
    private AgentlessSessionStatusService $agentlessSessionStatusService;
    private VddkService $vddkService;
    private VmwareApiClient $vmwareApiClient;
    private Screen $screenService;
    private LockFactory $lockFactory;
    private RetryHandler $retryHandler;
    private PosixHelper $posixHelper;
    private VhostFactory $vhostFactory;
    private Sleep $sleep;
    private DateTimeService $dateTimeService;
    private Hardware $hardwareService;
    private SecretFile $secretFile;
    private FeatureService $featureService;

    public function __construct(
        EsxVmInfoService $esxVmInfoService,
        Filesystem $filesystem,
        AgentlessSessionStatusService $agentlessSessionStatusService,
        VddkService $vddkService,
        VmwareApiClient $vmwareApiClient,
        Screen $screenService,
        LockFactory $lockFactory,
        RetryHandler $retryHandler,
        PosixHelper $posixHelper,
        VhostFactory $vhostFactory,
        Sleep $sleep,
        DateTimeService $dateTimeService,
        Hardware $hardwareService,
        SecretFile $secretFile,
        Cipher $cipher,
        FeatureService $featureService
    ) {
        $this->esxVmInfoService = $esxVmInfoService;
        $this->filesystem = $filesystem;
        $this->agentlessSessionStatusService = $agentlessSessionStatusService;
        $this->vddkService = $vddkService;
        $this->vmwareApiClient = $vmwareApiClient;
        $this->screenService = $screenService;
        $this->lockFactory = $lockFactory;
        $this->retryHandler = $retryHandler;
        $this->posixHelper = $posixHelper;
        $this->vhostFactory = $vhostFactory;
        $this->sleep = $sleep;
        $this->dateTimeService = $dateTimeService;
        $this->hardwareService = $hardwareService;
        $this->secretFile = $secretFile;
        $this->cipher = $cipher;
        $this->featureService = $featureService;
    }

    /**
     * @return string Agentless Session ID.
     */
    public function createAgentlessSessionBackground(
        string $host,
        string $user,
        string $password,
        string $virtualMachineName,
        string $assetKeyName,
        bool $forceNbd = false,
        bool $fullDiskBackup = false
    ): string {
        $agentlessSessionId = $this->generateAgentlessSessionId(
            $host,
            $user,
            $password,
            $virtualMachineName,
            $assetKeyName
        );
        $sessionIdName = $agentlessSessionId->toSessionIdName();
        
        $this->logger->setAgentlessSessionContext($sessionIdName);
        $this->logger->setAssetContext($assetKeyName);

        $encryptedPassword = $this->cipher->encrypt($password);
        $this->secretFile->save($encryptedPassword);
        $passwordFile = $this->secretFile->getFilename();

        $command = [
            'snapctl',
            'agentless:proxy:initialize',
            '--host',
            $host,
            '--user',
            $user,
            '--password-file',
            $passwordFile,
            '--vm-name',
            $agentlessSessionId->getVmMoRefId(),
            '--agentless-session',
            $sessionIdName
        ];

        if ($forceNbd) {
            $command[] = '--force-nbd';
        }

        if ($fullDiskBackup) {
            $command[] = '--full-disk';
        }

        if ($this->isSessionInitializing($agentlessSessionId)) {
            $this->logger->warning('ALS0000 Session was initializing in the background, killing it', [
                'sessionIdName' => $sessionIdName
            ]);
            $this->killInitializingSession($agentlessSessionId);
        }

        if ($this->isBackupRunning($agentlessSessionId)) {
            $this->logger->warning('ALS0001 A backup was running in the background, killing it');
            $this->killRunningBackup($agentlessSessionId);
        }

        // Wait for the session to be released now that all the processes using it should be dead
        $this->waitUntilSessionIsReleased($agentlessSessionId, $this->logger);

        $this->screenService->runInBackground($command, $sessionIdName);
        $this->logger->info('ALS0002 Session initialization started in the background', [
            'sessionIdName' => $sessionIdName
        ]);

        $this->waitUntilSessionIsInitializing($agentlessSessionId, $this->logger);

        return $sessionIdName;
    }

    public function createAgentlessSession(
        string $host,
        string $user,
        string $password,
        string $virtualMachineName,
        AgentlessSessionId $agentlessSessionId,
        bool $forceNbd = false,
        bool $fullDiskBackup = false
    ): AgentlessSession {
        $this->logger->setAgentlessSessionContext($agentlessSessionId->toSessionIdName());
        $this->logger->setAssetContext($agentlessSessionId->getAssetKeyName());

        $this->acquireSessionLock($agentlessSessionId, $this->logger, false);

        $this->logger->info('ALS0004 Initializing status for session', [
            'agentlessSessionId' => $agentlessSessionId
        ]);
        $this->agentlessSessionStatusService->initializeStatus($agentlessSessionId);

        $this->saveSessionInitPid($agentlessSessionId);

        try {
            $this->doPreCleanup(
                $host,
                $user,
                $password,
                $virtualMachineName,
                $agentlessSessionId
            );
            $agentlessSession = $this->doCreateAgentlessSession(
                $host,
                $user,
                $password,
                $virtualMachineName,
                $agentlessSessionId,
                $forceNbd,
                $fullDiskBackup
            );
        } catch (Throwable $throwable) {
            $this->logger->error('ALS0081 Error while creating session', [
                'error' => $throwable->getMessage()
            ]);
            $this->agentlessSessionStatusService->setSessionError($agentlessSessionId, $throwable->getMessage());
            throw $throwable;
        }

        return $agentlessSession;
    }

    public function getSessionStatus(AgentlessSessionId $agentlessSessionId): array
    {
        $sessionInitializing = $this->isSessionInitializing($agentlessSessionId);
        $cachedStatus = $this->agentlessSessionStatusService->readStatus($agentlessSessionId);

        $vddkMountPath = $this->getSessionPath(self::VDDK_MOUNT_PATH_FORMAT, $agentlessSessionId);
        $cachedStatus['vmdks_mounted'] = $this->vddkService->isVddkMounted($vddkMountPath);

        if ($cachedStatus['status'] === AgentlessSessionStatusService::SESSION_STATUS_READY &&
            $cachedStatus['vmdks_mounted']) {
            $cachedStatus['vmdks'] = $this->vddkService->getVmdksTransportMethods($vddkMountPath);
        }

        if ($cachedStatus['status'] !== AgentlessSessionStatusService::SESSION_STATUS_READY &&
            $cachedStatus['status'] !== AgentlessSessionStatusService::SESSION_STATUS_CLEANED_UP && !$sessionInitializing) {
            //If session is not initializing, not ready and not cleaned-up it means it was killed.
            $cachedStatus['status'] = AgentlessSessionStatusService::SESSION_STATUS_FAILED;
        }

        return $cachedStatus;
    }

    /**
     * Determines if the session is in a state considered to be 'running', aka
     * not failed out or cleaned up.
     */
    public function isSessionRunning(AgentlessSessionId $agentlessSessionId): bool
    {
        if (!$this->agentlessSessionStatusService->statusExists($agentlessSessionId)) {
            return false;
        }

        $sessionStatus = $this->getSessionStatus($agentlessSessionId);
        return !in_array($sessionStatus['status'], [
            AgentlessSessionStatusService::SESSION_STATUS_FAILED,
            AgentlessSessionStatusService::SESSION_STATUS_CLEANED_UP,
        ]);
    }

    /**
     * Creates an AgentlessSessionId instance from a provided ESX credentials and VM name.
     */
    public function generateAgentlessSessionId(
        string $host,
        string $user,
        string $password,
        string $vmName,
        string $assetKeyName
    ): AgentlessSessionId {
        $virtualizationHost = $this->vhostFactory->get($host, $user, $password);

        $virtualMachine = $this->vmwareApiClient->retrieveVirtualMachine($virtualizationHost, $vmName);
        $vmMoRefId = $virtualMachine->toReference()->_;

        $connectionType = $this->vmwareApiClient->getConnectionType($virtualizationHost);
        if ($connectionType === VmwareApiClient::CONNECTION_TYPE_VCENTER) {
            $uuid = $this->vmwareApiClient->getVcenterUuid($virtualizationHost);
        } else {
            $uuid = $this->vmwareApiClient->getStandaloneEsxHostUuid($virtualizationHost);
        }

        return AgentlessSessionId::create($uuid, $vmMoRefId, $assetKeyName);
    }

    public function getSession(AgentlessSessionId $agentlessSessionId): AgentlessSession
    {
        $this->logger->setAgentlessSessionContext($agentlessSessionId->toSessionIdName());
        $this->logger->setAssetContext($agentlessSessionId->getAssetKeyName());
        $this->acquireSessionLock($agentlessSessionId, $this->logger, false);

        return $this->loadAgentlessSession($agentlessSessionId);
    }

    /**
     * Get a read only copy of the session
     */
    public function getSessionReadonly(AgentlessSessionId $agentlessSessionId): AgentlessSession
    {
        return $this->loadAgentlessSession($agentlessSessionId);
    }

    public function cleanupSessionInBackground(AgentlessSessionId $agentlessSessionId): void
    {
        $sessionIdName = $agentlessSessionId->toSessionIdName();
        $this->logger->setAgentlessSessionContext($agentlessSessionId->toSessionIdName());
        $this->logger->setAssetContext($agentlessSessionId->getAssetKeyName());

        $command = [
            'snapctl', 'agentless:proxy:cleanup',
            '--agentless-session', $sessionIdName,
        ];

        $this->screenService->runInBackground($command, $sessionIdName);
        $this->logger->info('ALS0029 Session cleanup started in the background', [
            'sessionIdName' => $sessionIdName
        ]);
    }

    private function unmountVddkFuse(AgentlessSessionId $agentlessSessionId): void
    {
        $vddkMountPath = $this->getSessionPath(self::VDDK_MOUNT_PATH_FORMAT, $agentlessSessionId);
        $this->vddkService->umountVddk($vddkMountPath, $this->logger);
        $this->logger->debug('ALS0016 VDDK unmounted', ['vddkMountPath' => $vddkMountPath]);
    }

    /**
     * This is the "Golden Path" cleanup, it only tries to clean whatever was created during session initialization,
     * nothing else.
     *
     * @param bool $onPreCleanup true if this cleanup is part of pre-cleanup.
     */
    public function cleanupSession(AgentlessSessionId $agentlessSessionId, bool $onPreCleanup = false): void
    {
        $this->logger->setAgentlessSessionContext($agentlessSessionId->toSessionIdName());
        $this->logger->setAssetContext($agentlessSessionId->getAssetKeyName());

        $this->logger->info('ALS0005 Cleaning up session', ['agentlessSessionId' => $agentlessSessionId]);

        if ($this->isBackupRunning($agentlessSessionId)) {
            $this->logger->info("ALS0006 There is a backup job running for this session. Kill it.");
            $this->killRunningBackup($agentlessSessionId);
        }

        //If we are not in preCleanup, it means we can kill a potential initializing session.
        if (!$onPreCleanup) {
            if ($this->isSessionInitializing($agentlessSessionId)) {
                $this->logger->info("ALS0009 This session is being initialized. Kill it.");
                $this->killInitializingSession($agentlessSessionId);
            }
        }

        //Now that we know that the backup doesn't have the session lock...
        //Try to acquire session lock to make sure nobody tries to recreate the session while cleaning up.
        try {
            $this->retryHandler->executeAllowRetry(
                function () use ($agentlessSessionId) {
                    return $this->acquireSessionLock($agentlessSessionId, $this->logger, false);
                },
                5,
                5
            );
        } catch (Throwable $exception) {
            $this->logger->warning('ALS0013 Not able to acquire session lock, continuing cleanup');
        }

        //From here we know that no backup or session is gonna be created (for a particular vm).
        $this->logger->debug('ALS0014 Session ready to clean', ['agentlessSessionId' => $agentlessSessionId]);
        $this->agentlessSessionStatusService->setSessionStatus(
            $agentlessSessionId,
            AgentlessSessionStatusService::SESSION_STATUS_CLEANING,
            "Umounting VDDK"
        );

        $sessionInfoPath = $this->getSessionPath(self::SESSION_INFO_PATH_FORMAT, $agentlessSessionId);
        $sessionInfoExists = $this->filesystem->exists($sessionInfoPath);
        $sessionInfo = null;
        $transferMethod = '';
        if ($sessionInfoExists) {
            $sessionInfo = json_decode($this->filesystem->fileGetContents($sessionInfoPath) ?: "", true);
            $transferMethod = $sessionInfo['transferMethod'];
        }

        $removeSnapshot = true;

        //When using HyperShuttle, VDDK must already be unmounted by this point
        if ($transferMethod !== AgentlessSession::TRANSFER_METHOD_HYPER_SHUTTLE) {
            try {
                $this->unmountVddkFuse($agentlessSessionId);
            } catch (Throwable $exception) {
                if (!$onPreCleanup) {
                    $this->logger->warning('ALS0017 Failed unmounting vddk, keeping snapshot', [
                        'exception' => $exception
                    ]);
                    //Don't remove snapshot, if umounting failed somehow, there's a big chance that disks are still locked
                    //in the VM. If we delete the created snapshot now, we will likely cause "consolidation needed" on esx.
                    $removeSnapshot = false;
                }
            }
        }

        if (!$sessionInfoExists || !$sessionInfo) {
            $this->logger->debug('ALS0018 No previous sessionInfo file exists, nothing else to cleanup.');
            $this->agentlessSessionStatusService->setSessionStatus(
                $agentlessSessionId,
                AgentlessSessionStatusService::SESSION_STATUS_CLEANED_UP
            );
            return;
        }

        $host = $sessionInfo['host'];
        $user = $sessionInfo['user'];
        $password = $this->cipher->decrypt($sessionInfo['password']);
        $snapshotMoRefId = $sessionInfo['snapshotMoRefId'];
        $vmMoRefId = $sessionInfo['vmMoRefId'];

        $this->agentlessSessionStatusService->setSessionStatus(
            $agentlessSessionId,
            AgentlessSessionStatusService::SESSION_STATUS_CLEANING,
            "Deleting snapshot"
        );

        $virtualizationHost = $this->vhostFactory->get($host, $user, $password);
        $virtualMachine = $this->vmwareApiClient->retrieveVirtualMachine($virtualizationHost, $vmMoRefId);
        $selfVm = $this->doesProxyShareEnvironmentWithVm($virtualizationHost);

        if ($selfVm) {
            $orphanDisksUuids = $this->vmwareApiClient->findOrphanedDisksUuids($selfVm, $virtualMachine);

            if ($orphanDisksUuids) {
                $this->logger->warning(
                    'ALS0019 There are still drives attached to the proxy VM, skipping snapshot deletion'
                );
                $removeSnapshot = false;
            }
        }

        if ($removeSnapshot) {
            try {
                $snapshot = $this->vmwareApiClient->retrieveSnapshot($virtualizationHost, $snapshotMoRefId);
                $this->retryHandler->executeAllowRetry(
                    function () use ($snapshot) {
                        $this->logger->info('ALS0021 Trying to delete snapshot');
                        $this->vmwareApiClient->removeSnapshot($snapshot);
                    }
                );
            } catch (Throwable $exception) {
                $this->logger->warning('ALS0022 Error deleting the snapshot. Continuing', [
                    'exception' => $exception
                ]);
            }
        } else {
            $this->logger->warning('ALS0023 Snapshot not deleted due to errors unmounting backup disks.');
        }

        $this->logger->info('ALS0024 Backing up old session files...');
        $this->moveSessionFiles($agentlessSessionId);

        $this->agentlessSessionStatusService->setSessionStatus(
            $agentlessSessionId,
            AgentlessSessionStatusService::SESSION_STATUS_CLEANED_UP
        );
        $this->logger->info('ALS0025 Session cleaned up correctly.');
    }

    public function acquireSessionLock(
        AgentlessSessionId $agentlessSessionId,
        DeviceLoggerInterface $logger,
        bool $wait
    ): Lock {
        $sessionLock = $this->getAgentlessSessionLock($agentlessSessionId);

        if ($wait && $sessionLock->isLocked()) {
            $logger->warning('ALS0026 Agentless session is locked. Waiting for it to be ready...', [
                'agentlessSessionId' => $agentlessSessionId
            ]);
        }
        if (!$sessionLock->exclusive($wait)) {
            if ($wait) {
                $logger->error('ALS0027 Failed to acquire session lock');
                throw new RuntimeException('Failed to acquire session lock');
            }

            $logger->error('ALS0028 Session is busy', ['agentlessSessionId' => $agentlessSessionId]);
            throw new SessionBusyException((string) $agentlessSessionId);
        }

        $this->filesystem->filePutContents($sessionLock->path(), strval($this->posixHelper->getCurrentProcessId()));

        return $sessionLock;
    }

    public function isSessionLocked(AgentlessSessionId $agentlessSessionId): bool
    {
        return $this->getAgentlessSessionLock($agentlessSessionId)->isLocked();
    }

    public function isSessionInitialized(AgentlessSessionId $agentlessSessionId): bool
    {
        $sessionInfoPath = $this->getSessionPath(self::SESSION_INFO_PATH_FORMAT, $agentlessSessionId);

        return $this->filesystem->exists($sessionInfoPath);
    }

    public function waitUntilSessionIsReleased(
        AgentlessSessionId $agentlessSessionId,
        DeviceLoggerInterface $logger,
        int $timeout = self::BACKGROUND_TASK_START_TIMEOUT_SECONDS
    ): void {
        $startTime = $this->dateTimeService->getTime();
        $sessionLock = $this->getAgentlessSessionLock($agentlessSessionId);
        $logger->info('ALS0031 Waiting for session to be released');

        while (!$sessionLock->exclusive(false)) {
            $elapsedTime = $this->dateTimeService->getTime() - $startTime;

            if ($elapsedTime > $timeout) {
                $logger->error('ALS0030 Timeout reached while waiting for session to be released');
                throw new SessionBusyException((string) $agentlessSessionId);
            }
            $this->sleep->sleep(1);
        }
        $logger->info('ALS0032 Session released');
        $sessionLock->unlock();
    }

    public function isBackupRunning(AgentlessSessionId $agentlessSessionId): bool
    {
        $pid = $this->getBackupRunningPid($agentlessSessionId);

        if (!$pid) {
            return false;
        }

        return $this->posixHelper->isProcessRunning(intval($pid));
    }

    public function killRunningBackup(AgentlessSessionId $agentlessSessionId): bool
    {
        $pid = $this->getBackupRunningPid($agentlessSessionId);

        if (!$pid) {
            return false;
        }

        return $this->posixHelper->kill(intval($pid), 9);
    }

    /**
     * @return bool|string
     */
    public function getBackupRunningPid(AgentlessSessionId $agentlessSessionId)
    {
        $backupRunningPid = $this->getSessionPath(self::BACKUP_RUNNING_PID_FORMAT, $agentlessSessionId);

        return @$this->filesystem->fileGetContents($backupRunningPid);
    }

    public function saveBackupRunningPid(AgentlessSessionId $agentlessSessionId): void
    {
        $backupRunningPid = $this->getSessionPath(self::BACKUP_RUNNING_PID_FORMAT, $agentlessSessionId);

        $this->filesystem->filePutContents($backupRunningPid, strval($this->posixHelper->getCurrentProcessId()));
    }

    /**
     * @return bool|string
     */
    public function getBackupJobId(AgentlessSessionId $agentlessSessionId)
    {
        $backupJobIdPath = $this->getSessionPath(self::BACKUP_RUNNING_JOB_FORMAT, $agentlessSessionId);

        return @$this->filesystem->fileGetContents($backupJobIdPath);
    }

    public function saveBackupJobId(AgentlessSessionId $agentlessSessionId, string $backupJobId): void
    {
        $backupJobIdPath = $this->getSessionPath(self::BACKUP_RUNNING_JOB_FORMAT, $agentlessSessionId);

        $this->filesystem->filePutContents($backupJobIdPath, $backupJobId);
    }

    /**
     * This cleanup tries to leave the virtual environment as clean as possible, so the backup will run efficiently.
     */
    private function doPreCleanup(
        string $host,
        string $user,
        string $password,
        string $virtualMachineName,
        AgentlessSessionId $agentlessSessionId
    ): void {
        $this->logger->setAgentlessSessionContext($agentlessSessionId->toSessionIdName());
        $this->logger->setAssetContext($agentlessSessionId->getAssetKeyName());

        $agentlessSessionInfoPath = $this->getSessionPath(self::SESSION_INFO_PATH_FORMAT, $agentlessSessionId);

        $this->logger->info('ALS0033 Doing pre-cleanup');
        $msg = '';
        //If sessionInfo file found, means that cleanup was not called. Probably process abruptly killed siris side.
        if ($this->filesystem->exists($agentlessSessionInfoPath)) {
            $this->logger->warning('ALS0034 There is a session initialized for this VM, trying to cleanup first');
        }

        $this->agentlessSessionStatusService->setSessionStatus(
            $agentlessSessionId,
            AgentlessSessionStatusService::SESSION_STATUS_PRE_CLEANING,
            $msg
        );
        $this->cleanupSession($agentlessSessionId, true);

        $virtualizationHost = $this->vhostFactory->get($host, $user, $password);
        $virtualMachine = $this->vmwareApiClient->retrieveVirtualMachine($virtualizationHost, $virtualMachineName);
        $vmMoRefId = $virtualMachine->toReference()->_;
        $vmBiosUuid = $this->vmwareApiClient->getVmBiosUuid($virtualMachine);

        $vddkMountPath = $this->getSessionPath(self::VDDK_MOUNT_PATH_FORMAT, $agentlessSessionId);
        $this->vddkService->ensureVddkIsCleaned($vddkMountPath, $vmMoRefId, $vmBiosUuid, $this->logger);

        try {
            $this->preCleanupVirtualSharedEnvironment($virtualizationHost, $virtualMachine, $this->logger);
        } catch (Throwable $exception) {
            $this->logger->warning('ALS0037 Error during pre-cleaning of virtual environment', [
                'exception' => $exception
            ]);
        }

        $this->agentlessSessionStatusService->setSessionStatus(
            $agentlessSessionId,
            AgentlessSessionStatusService::SESSION_STATUS_PRE_CLEANING,
            "Ensure CBT is enabled"
        );

        $this->logger->info('ALS0038 Ensuring CBT (Change Block Tracking) is enabled...');
        $this->vmwareApiClient->setCbtEnabled($virtualMachine, true);

        $this->agentlessSessionStatusService->setSessionStatus(
            $agentlessSessionId,
            AgentlessSessionStatusService::SESSION_STATUS_PRE_CLEANING,
            "Removing orphaned snapshots"
        );
        $this->logger->debug('ALS0039 Removing orphaned snapshots...');
        $this->vmwareApiClient->removeOrphanedSnapshots($virtualMachine, $this->logger);

        $this->logger->info('ALS0040 Finished pre-cleanup.');
    }

    private function preCleanupVirtualSharedEnvironment(
        Vhost $virtualizationHost,
        VirtualMachine $targetVirtualMachine,
        DeviceLoggerInterface $logger
    ): void {
        $selfVirtualMachine = $this->doesProxyShareEnvironmentWithVm($virtualizationHost);

        if (!$selfVirtualMachine) {
            $logger->info("ALS0041 Proxy doesn't share environment with VM.");
            return;
        }

        $logger->info("ALS0042 Proxy shares the environment with the VM to backup.");
        if ($this->vmwareApiClient->isVmRunningOnSnapshots($selfVirtualMachine)) {
            $logger->warning("ALS0043 The proxy/vSiris is running on snapshots, this can prevent cleanup and impact performance");
        }

        $orphanDisksUuids = $this->vmwareApiClient->findOrphanedDisksUuids($selfVirtualMachine, $targetVirtualMachine);

        if ($orphanDisksUuids) {
            $logger->warning(
                'ALS0044 Proxy has attached drives from VM to backup, manual cleanup could be needed, trying to detach drives',
                ['orphanDiskUuids' => $orphanDisksUuids]
            );

            $this->vmwareApiClient->removeOrphanedDisks($selfVirtualMachine, $orphanDisksUuids, $logger);
        } else {
            $logger->info("ALS0045 Virtual machines don't have any disks in common, nothing to detach.");
        }
    }

    /**
     * Returns the VirtualMachine instance of the prox/vSiris it self, in case that is able to identify itself
     * in the virtual environment of the VM's hypervisor. Returns false otherwise.
     *
     * In case that is not able to uniquely identify itself in the virtual environment, it will throw an exception.
     */
    private function doesProxyShareEnvironmentWithVm(
        Vhost $virtualizationHost
    ): ?VirtualMachine {
        $detectedHypervisor = $this->hardwareService->detectHypervisor();

        if ($detectedHypervisor === HypervisorType::VMWARE()) {
            $proxySerialNumber = $this->hardwareService->getSystemSerialNumber();

            return $this->vmwareApiClient->findVmByBiosSerialNumber($virtualizationHost, $proxySerialNumber);
        }

        return null;
    }

    private function saveAgentlessSession(AgentlessSession $agentlessSession): void
    {
        $agentlessSessionId = $agentlessSession->getAgentlessSessionId();
        $password = $agentlessSession->getPassword();

        $sessionInfo = [
            "sessionId" => (string)$agentlessSessionId,
            "host" => $agentlessSession->getHost(),
            "user" => $agentlessSession->getUser(),
            "password" => $this->cipher->encrypt($password),
            "vmMoRefId" => $agentlessSession->getVmMoRefId(),
            "snapshotMoRefId" => $agentlessSession->getSnapshotMoRefId(),
            "fullDiskBackup" => $agentlessSession->isFullDiskBackup(),
            "transferMethod" => $agentlessSession->getTransferMethod(),
            "forceNbd" => $agentlessSession->isForceNbd()
        ];

        $sessionInfoPath = $this->getSessionPath(self::SESSION_INFO_PATH_FORMAT, $agentlessSessionId);
        $esxInfoPath = $this->getSessionPath(self::ESX_INFO_PATH_FORMAT, $agentlessSessionId);
        $agentInfoPath = $this->getSessionPath(self::AGENT_INFO_PATH_FORMAT, $agentlessSessionId);

        $this->filesystem->filePutContents($sessionInfoPath, json_encode($sessionInfo, JSON_PRETTY_PRINT));
        $this->filesystem->filePutContents(
            $esxInfoPath,
            json_encode($agentlessSession->getEsxVmInfo(), JSON_PRETTY_PRINT)
        );
        $this->filesystem->filePutContents(
            $agentInfoPath,
            json_encode($agentlessSession->getAgentVmInfo(), JSON_PRETTY_PRINT)
        );
    }

    private function loadAgentlessSession(
        AgentlessSessionId $agentlessSessionId
    ): AgentlessSession {
        $this->logger->setAgentlessSessionContext($agentlessSessionId->toSessionIdName());
        $this->logger->setAssetContext($agentlessSessionId->getAssetKeyName());

        $agentlessSessionInfoPath = $this->getSessionPath(self::SESSION_INFO_PATH_FORMAT, $agentlessSessionId);
        $esxInfoPath = $this->getSessionPath(self::ESX_INFO_PATH_FORMAT, $agentlessSessionId);
        $agentInfoPath = $this->getSessionPath(self::AGENT_INFO_PATH_FORMAT, $agentlessSessionId);
        $vddkMountPath = $this->getSessionPath(self::VDDK_MOUNT_PATH_FORMAT, $agentlessSessionId);

        if (!$this->filesystem->exists($agentlessSessionInfoPath)) {
            $this->logger->error('ALS0049 Session does not exist', [
                'agentlessSessionInfoPath' => $agentlessSessionInfoPath
            ]);
            throw new SessionNotFoundException($agentlessSessionInfoPath);
        }

        $this->logger->info('ALS0050 Loading session', [
            'agentlessSessionInfoPath' => $agentlessSessionInfoPath
        ]);
        $sessionInfo = json_decode($this->filesystem->fileGetContents($agentlessSessionInfoPath) ?: "", true);
        $esxVmInfo = json_decode($this->filesystem->fileGetContents($esxInfoPath) ?: "", true);
        $agentVmInfo = json_decode($this->filesystem->fileGetContents($agentInfoPath) ?: "", true);

        $host = $sessionInfo['host'];
        $user = $sessionInfo['user'];
        $password = $this->cipher->decrypt($sessionInfo['password']);
        $vmMoRefId = $sessionInfo['vmMoRefId'];
        $snapshotMoRefId = $sessionInfo['snapshotMoRefId'];
        $fullDiskBackup = $sessionInfo['fullDiskBackup'];
        $transferMethod = $sessionInfo['transferMethod'];
        $forceNbd = $sessionInfo['forceNbd'];

        $virtualizationHost = $this->vhostFactory->get($host, $user, $password);
        $virtualMachine = $this->vmwareApiClient->retrieveVirtualMachine($virtualizationHost, $vmMoRefId);
        $snapshot = $this->vmwareApiClient->retrieveSnapshot($virtualizationHost, $snapshotMoRefId);

        return new AgentlessSession(
            $agentlessSessionId,
            $transferMethod,
            $host,
            $user,
            $password,
            $virtualizationHost,
            $virtualMachine,
            $snapshot,
            $vmMoRefId,
            $snapshotMoRefId,
            $esxVmInfo,
            $agentVmInfo,
            $fullDiskBackup,
            $forceNbd,
            $this->logger
        );
    }

    /**
     * Moves session files to a backup directory so they are kept for debugging purposes.
     */
    private function moveSessionFiles(AgentlessSessionId $agentlessSessionId): void
    {
        $oldFilesBasePath = $this->getSessionPath(self::OLD_SESSION_FILES_PATH_FORMAT, $agentlessSessionId);
        $this->filesystem->mkdirIfNotExists($oldFilesBasePath, false, self::MKDIR_MODE);

        $agentInfoPath = $this->getSessionPath(self::AGENT_INFO_PATH_FORMAT, $agentlessSessionId);
        $esxInfoPath = $this->getSessionPath(self::ESX_INFO_PATH_FORMAT, $agentlessSessionId);
        $sessionInfoPath = $this->getSessionPath(self::SESSION_INFO_PATH_FORMAT, $agentlessSessionId);

        $this->filesystem->rename($agentInfoPath, $oldFilesBasePath . '/agentInfo');
        $this->filesystem->rename($esxInfoPath, $oldFilesBasePath . '/esxInfo');
        $this->filesystem->rename($sessionInfoPath, $oldFilesBasePath . '/sessionInfo');

        // Move status files of potential backup attempts.
        $filePaths = $this->filesystem->glob(
            $this->getSessionPath(self::SESSION_PATH_FORMAT, $agentlessSessionId) . "/backup-*.status"
        );
        if ($filePaths) {
            foreach ($filePaths as $filePath) {
                $fileName = $this->filesystem->basename($filePath);
                $this->filesystem->rename($filePath, $oldFilesBasePath . '/' . $fileName);
            }
        }
    }

    private function doCreateAgentlessSession(
        string $host,
        string $user,
        string $password,
        string $virtualMachineName,
        AgentlessSessionId $agentlessSessionId,
        bool $forceNbd = false,
        bool $fullDiskBackup = false
    ): AgentlessSession {
        $this->logger->setAgentlessSessionContext($agentlessSessionId->toSessionIdName());
        $this->logger->setAssetContext($agentlessSessionId->getAssetKeyName());

        $this->logger->info('ALS0055 Creating agentless session', [
            'virtualMachineName' => $virtualMachineName
        ]);

        $virtualizationHost = $this->vhostFactory->get($host, $user, $password);
        $virtualMachine = $this->vmwareApiClient->retrieveVirtualMachine($virtualizationHost, $virtualMachineName);
        $vmMoRefId = $virtualMachine->toReference()->_;

        $agentlessSessionPath = $this->getSessionPath(self::SESSION_PATH_FORMAT, $agentlessSessionId);
        $vddkMountPath = $this->getSessionPath(self::VDDK_MOUNT_PATH_FORMAT, $agentlessSessionId);
        $this->filesystem->mkdirIfNotExists($agentlessSessionPath, true, self::MKDIR_MODE);

        try {
            $this->vmwareApiClient->validateConnection($virtualizationHost, $this->logger);
        } catch (BypassedVcenterException $exception) {
            $this->agentlessSessionStatusService->setBypassingManagementServer($agentlessSessionId, true);
        } catch (Throwable $exception) {
            $this->logger->error('ALS0058 Error validating hypervisor connection', [
                'exception' => $exception
            ]);
        }

        if ($this->vmwareApiClient->isVmRunningOnSnapshots($virtualMachine)) {
            $this->logger->warning('ALS0059 The VM that you are backing up is running on snapshots, this can impact backup performance');
        }

        $esxHostVersion = $this->vmwareApiClient->getEsxHostVersion($virtualMachine);
        if (is_null($esxHostVersion)) {
            $this->logger->error('ALS0060 No ESX host version found.');
            throw new RuntimeException('No ESX host version found.');
        }
        $this->logger->info('ALS0061 Got ESX Host Version', ['esxHostVersion' => $esxHostVersion]);
        $this->agentlessSessionStatusService->setHostVersion($agentlessSessionId, $esxHostVersion);

        $this->agentlessSessionStatusService->setSessionStatus(
            $agentlessSessionId,
            AgentlessSessionStatusService::SESSION_STATUS_CREATE_SNAPSHOT,
            "Creating snapshot"
        );
        $this->logger->info('ALS0062 Creating snapshot...');
        $snapshot = $this->vmwareApiClient->createSnapshot($virtualMachine);
        $snapshotMoRefId = $snapshot->toReference()->_;

        try {
            $vmdkPaths = $this->vmwareApiClient->retrieveVmdkPaths($snapshot);

            $this->agentlessSessionStatusService->setSessionStatus(
                $agentlessSessionId,
                AgentlessSessionStatusService::SESSION_STATUS_MOUNT_VDDK,
                "Mounting vddk"
            );
            $vddkPid = $this->vddkService->mountVddk(
                $host,
                $user,
                $password,
                $vmMoRefId,
                $snapshotMoRefId,
                $vmdkPaths,
                $vddkMountPath,
                $esxHostVersion,
                $this->logger,
                $forceNbd
            );
        } catch (Throwable $t) {
            $this->logger->error('ALS0065 Error occurred mounting vddk-fuse', [
                'exception' => $t
            ]);
            throw $t;
        }
        $this->logger->info('ALS0067 VDDK Mounted.');

        try {
            $vmdkTransportInfos = $this->vddkService->getVmdksTransportMethods($vddkMountPath);
            foreach ($vmdkTransportInfos as $vmdkTransportInfo) {
                $vmdk = $vmdkTransportInfo['vmdk'];
                $transportMethod = $vmdkTransportInfo['transport'];
                $this->logger->info('ALS0069 VMDK Transport Method', [
                    'transportMethod' => $transportMethod,
                    'vmdk' => $vmdk
                ]);
            }
        } catch (Throwable $t) {
            $this->logger->warning('ALS0070 Failure retrieving vmdk transport info', [
                'exception' => $t
            ]);
        }

        $esxVmInfo = null;
        $agentVmInfo = null;
        try {
            $this->agentlessSessionStatusService->setSessionStatus(
                $agentlessSessionId,
                AgentlessSessionStatusService::SESSION_STATUS_ESX_INFO,
                "Initializing libguestfs"
            );

            $this->esxVmInfoService->initialize($virtualMachine, $snapshot, $vddkMountPath);
            $esxVmInfo = $this->esxVmInfoService->retrieveEsxVmInfo();

            try {
                $this->logger->info('ALS0085 Retrieving VMX content...');
                $esxVmInfo['vmx'] = $this->vmwareApiClient->retrieveVirtualMachineVmx($virtualizationHost, $vmMoRefId);
                $this->logger->info('ALS0086 VMX file retrieved correctly');
            } catch (Throwable $throwable) {
                $this->logger->error('ALS0087 Error, cannot retrieve VMX file.', [
                    'exception' => $throwable
                ]);
            }

            $this->agentlessSessionStatusService->setSessionStatus(
                $agentlessSessionId,
                AgentlessSessionStatusService::SESSION_STATUS_AGENT_INFO
            );
            $agentVmInfo = $this->esxVmInfoService->retrieveAgentInfo();
        } catch (Throwable $t) {
            $this->logger->error('ALS0076 An error occurred while retrieving info', [
                'exception' => $t
            ]);
            throw $t;
        } finally {
            $this->esxVmInfoService->shutdown();
        }

        $transferMethod = $this->getTransferMethodForEsxHost($esxHostVersion);

        $agentlessSession = new AgentlessSession(
            $agentlessSessionId,
            $transferMethod,
            $host,
            $user,
            $password,
            $virtualizationHost,
            $virtualMachine,
            $snapshot,
            $vmMoRefId,
            $snapshotMoRefId,
            $esxVmInfo,
            $agentVmInfo,
            $fullDiskBackup,
            $forceNbd,
            $this->logger
        );

        //VDDKFuse must be unmounted before hyper shuttle can perform backups
        if ($agentlessSession->isUsingHyperShuttle()) {
            try {
                $this->unmountVddkFuse($agentlessSessionId);
            } catch (Throwable $t) {
                $this->logger->error('ALS0090 Error terminating vddk-fuse process', [
                    'exception' => $t
                ]);
                throw $t;
            }
        }

        $this->saveAgentlessSession($agentlessSession);
        $this->logger->info('ALS0079 Session fully initialized');
        $this->agentlessSessionStatusService->setSessionStatus(
            $agentlessSessionId,
            AgentlessSessionStatusService::SESSION_STATUS_READY,
            ""
        );

        return $agentlessSession;
    }

    private function getTransferMethodForEsxHost(string $esxHostVersion): string
    {
        $atLeast6_7 = version_compare($esxHostVersion, VddkService::VDDK_VERSION_6_7, '>=');
        if ($this->featureService->isSupported(FeatureService::FEATURE_HYPER_SHUTTLE) && $atLeast6_7) {
            return AgentlessSession::TRANSFER_METHOD_HYPER_SHUTTLE;
        } else {
            return AgentlessSession::TRANSFER_METHOD_MERCURY_FTP;
        }
    }

    private function isSessionInitializing(AgentlessSessionId $agentlessSessionId): bool
    {
        $pid = $this->getSessionInitPid($agentlessSessionId);

        if (!$pid) {
            return false;
        }

        return $this->posixHelper->isProcessRunning(intval($pid));
    }

    private function killInitializingSession(AgentlessSessionId $agentlessSessionId): bool
    {
        $pid = $this->getSessionInitPid($agentlessSessionId);

        if (!$pid) {
            return false;
        }

        return $this->posixHelper->kill(intval($pid), 9);
    }

    /**
     * @return bool|string
     */
    private function getSessionInitPid(AgentlessSessionId $agentlessSessionId)
    {
        $sessionInitPidPath = $this->getSessionPath(self::SESSION_INIT_PID_FORMAT, $agentlessSessionId);

        return @$this->filesystem->fileGetContents($sessionInitPidPath);
    }

    private function saveSessionInitPid(AgentlessSessionId $agentlessSessionId): void
    {
        $sessionInitPidPath = $this->getSessionPath(self::SESSION_INIT_PID_FORMAT, $agentlessSessionId);

        $this->filesystem->filePutContents($sessionInitPidPath, strval($this->posixHelper->getCurrentProcessId()));
    }

    private function waitUntilSessionIsInitializing(
        AgentlessSessionId $agentlessSessionId,
        DeviceLoggerInterface $logger
    ): void {
        $startTime = $this->dateTimeService->getTime();
        $logger->info('ALS0080 Waiting for session to be locked...');
        while (!($this->isSessionLocked($agentlessSessionId) && $this->isSessionInitializing($agentlessSessionId))) {
            $elapsedTime = $this->dateTimeService->getTime() - $startTime;
            if ($elapsedTime > self::BACKGROUND_TASK_START_TIMEOUT_SECONDS) {
                throw new RuntimeException("Timeout reached while waiting for session to be locked.");
            }
            $this->sleep->sleep(1);
        }
    }

    private function getAgentlessSessionLock(AgentlessSessionId $agentlessSessionId): Lock
    {
        $agentlessSessionPath = $this->getSessionPath(self::SESSION_LOCK_PATH_FORMAT, $agentlessSessionId);

        return $this->lockFactory->getProcessScopedLock($agentlessSessionPath);
    }

    private function getSessionPath(string $pathFormat, AgentlessSessionId $agentlessSessionId): string
    {
        return sprintf(
            $pathFormat,
            $agentlessSessionId->toSessionIdName()
        );
    }
}
