<?php

namespace Datto\Agentless\Proxy;

use Datto\Agentless\Proxy\Exceptions\SessionNotFoundException;
use Datto\Asset\UuidGenerator;
use Datto\Log\LoggerAwareTrait;
use Datto\Utility\File\Lock;
use Datto\Utility\Screen;
use Datto\Utility\ByteUnit;
use Datto\Resource\DateTimeService;
use Datto\Util\RetryHandler;
use Datto\Virtualization\VmwareApiClient;
use Datto\Common\Resource\Sleep;
use DiskChangeExtent;
use Exception;
use Psr\Log\LoggerAwareInterface;
use RuntimeException;
use Throwable;

/**
 * Main entry point for agentless backup operations in an agentless session.
 * To execute any method in this class you need a previously initialized session using AgentlessSessionService.
 * It serves as an entry point for:
 *
 * - Backup job create/start (background and foreground)
 * - Backup job cancel
 * - Get backup job status.
 *
 * @author Mario Rial <mrial@datto.com>
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class AgentlessBackupService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const SECTOR_OFFSET = 63;
    private const SECTOR_SIZE = 512;
    //Pass this parameter from the /backup API call instead.
    private const MBR_OFFSET = self::SECTOR_OFFSET * self::SECTOR_SIZE;

    private BackupJobExecutor $backupJobExecutor;
    private AgentlessBackupStatusService $backupJobStatusService;
    private ChangeIdService $changeIdService;
    private Screen $screenService;
    private AgentlessSessionService $agentlessSessionService;
    private VmwareApiClient $vmwareApiClient;
    private RetryHandler $retryHandler;
    private Sleep $sleep;
    private DateTimeService $dateTimeService;
    private UuidGenerator $uuidGenerator;

    public function __construct(
        AgentlessBackupStatusService $backupJobStatusService,
        BackupJobExecutor $backupJobExecutor,
        ChangeIdService $changeIdService,
        Screen $screenService,
        AgentlessSessionService $agentlessSessionService,
        VmwareApiClient $vmwareApiClient,
        RetryHandler $retryHandler,
        Sleep $sleep,
        DateTimeService $dateTimeService,
        UuidGenerator $uuidGenerator
    ) {
        $this->backupJobStatusService = $backupJobStatusService;
        $this->backupJobExecutor = $backupJobExecutor;
        $this->changeIdService = $changeIdService;
        $this->screenService = $screenService;
        $this->agentlessSessionService = $agentlessSessionService;
        $this->vmwareApiClient = $vmwareApiClient;
        $this->retryHandler = $retryHandler;
        $this->sleep = $sleep;
        $this->dateTimeService = $dateTimeService;
        $this->uuidGenerator = $uuidGenerator;
    }

    /**
     * @param string[] $volumeGuids
     * @param string[] $destinationFiles
     * @param string[] $changeIdFiles
     */
    public function takeBackupBackground(
        AgentlessSessionId $agentlessSessionId,
        array $volumeGuids,
        array $destinationFiles,
        array $changeIdFiles,
        bool $forceDiffmerge = false,
        bool $forceFull = false
    ): string {
        $this->logger->setAgentlessSessionContext($agentlessSessionId->toSessionIdName());
        $this->logger->setAssetContext($agentlessSessionId->getAssetKeyName());

        if (!$this->agentlessSessionService->isSessionInitialized($agentlessSessionId)) {
            throw new SessionNotFoundException($agentlessSessionId);
        }

        $jobId = $this->generateBackupJobId();
        $this->logger->info('ALB0000 Starting backup process in the background', ['jobId' => $jobId]);

        $command = [
            'snapctl',
            'agentless:proxy:start:backup',
            '--agentless-session',
            $agentlessSessionId,
            '--jobId',
            $jobId
        ];

        for ($i = 0; $i < count($volumeGuids); $i++) {
            $command[] = '--volume';
            $command[] = $volumeGuids[$i];
            $command[] = '--destination-file';
            $command[] = $destinationFiles[$i];
            $command[] = '--changeId-file';
            $command[] = $changeIdFiles[$i];
        }

        if ($forceDiffmerge) {
            $command[] = '--diff';
        }

        if ($forceFull) {
            $command[] = '--full';
        }

        if ($this->agentlessSessionService->isBackupRunning($agentlessSessionId)) {
            $backupJobId = $this->agentlessSessionService->getBackupJobId($agentlessSessionId);
            if ($backupJobId === false) {
                $this->logger->warning(
                    'ALB0012 A backup job is currently running in the session but its id could not be found.'
                );
            } else {
                $this->logger->warning(
                    'ALB0001 A backup job is currently running in the session. Cancelling it first.',
                    ['backupJobId' => $backupJobId]
                );
                $this->cancelBackup($agentlessSessionId, $backupJobId);
                $this->agentlessSessionService->killRunningBackup($agentlessSessionId);
            }
        }

        $this->agentlessSessionService->waitUntilSessionIsReleased($agentlessSessionId, $this->logger);

        $this->screenService->runInBackground($command, $jobId);
        $this->waitUntilBackupStarted($agentlessSessionId);
        $this->logger->info('ALB0002 Backup Job Id successfully started in the background', [
            'jobId' => $jobId,
        ]);

        return $jobId;
    }

    /**
     * @param string[] $volumeGuids
     * @param string[] $destinationFiles
     * @param string[] $changeIdFiles
     */
    public function takeBackup(
        AgentlessSessionId $agentlessSessionId,
        array $volumeGuids,
        array $destinationFiles,
        array $changeIdFiles,
        string $backupJobId,
        bool $forceDiffmerge = false,
        bool $forceFull = false
    ): int {
        $this->logger->setAgentlessSessionContext($agentlessSessionId->toSessionIdName());
        $this->logger->setAssetContext($agentlessSessionId->getAssetKeyName());

        $this->logger->info('ALB0003 Acquiring session lock', ['sessionId' => $agentlessSessionId]);
        $this->agentlessSessionService->acquireSessionLock($agentlessSessionId, $this->logger, false);

        $this->logger->info('ALB0004 Initializing status for backup job', ['backupJobId' => $backupJobId]);
        $this->backupJobStatusService->initializeStatus(
            $agentlessSessionId,
            $backupJobId,
            $volumeGuids
        );

        $this->agentlessSessionService->saveBackupRunningPid($agentlessSessionId);
        $this->agentlessSessionService->saveBackupJobId($agentlessSessionId, $backupJobId);

        try {
            $this->logger->info('ALB0005 Loading session', ['sessionId' => $agentlessSessionId]);
            $agentlessSession = $this->agentlessSessionService->getSession($agentlessSessionId);

            $totalTransferredBytes = $this->doBackup(
                $agentlessSession,
                $backupJobId,
                $volumeGuids,
                $destinationFiles,
                $changeIdFiles,
                $forceDiffmerge,
                $forceFull
            );
            $this->logger->info('ALB0006 Backup Completed', ['totalTransferredBytes' => $totalTransferredBytes]);

            $this->backupJobStatusService->setBackupStatusFinished($agentlessSessionId, $backupJobId);
        } catch (Throwable $exception) {
            $this->logger->error('ALB0007 Backup failed', ['exception' => $exception]);
            $this->backupJobStatusService->setBackupStatusFailed(
                $agentlessSessionId,
                $backupJobId,
                $exception->getMessage()
            );
            throw $exception;
        }

        return $totalTransferredBytes;
    }

    private function waitUntilBackupStarted(AgentlessSessionId $agentlessSessionId): void
    {
        $this->logger->info('ALB0009 Waiting for backup to start...');

        $startTime = $this->dateTimeService->getTime();
        while (!$this->agentlessSessionService->isSessionLocked($agentlessSessionId) ||
            !$this->agentlessSessionService->isBackupRunning($agentlessSessionId)) {
            $timePassed = $this->dateTimeService->getTime() - $startTime;

            if ($timePassed > AgentlessSessionService::BACKGROUND_TASK_START_TIMEOUT_SECONDS) {
                $this->logger->error('ALB0008 Timeout reached while waiting for backup to start.');
                throw new RuntimeException('Timeout reached while waiting for backup to start.');
            }
            $this->sleep->sleep(1);
        }
    }

    /**
     * @return Lock the acquired lock that prevents backups from starting until unlocked.
     */
    public function cancelBackup(AgentlessSessionId $agentlessSessionId, string $backupJobId): Lock
    {
        $this->logger->setAgentlessSessionContext($agentlessSessionId->toSessionIdName());
        $this->logger->setAssetContext($agentlessSessionId->getAssetKeyName());

        if (!$this->agentlessSessionService->isSessionInitialized($agentlessSessionId)) {
            throw new SessionNotFoundException($agentlessSessionId);
        }

        $backupRunning = $this->agentlessSessionService->isBackupRunning($agentlessSessionId);

        if (!$backupRunning) {
            $this->logger->warning('ALB0010 Backup is not running, nothing to cancel.');
        } else {
            $this->logger->info('ALB0011 Cancelling backup...', ['sessionId' => $agentlessSessionId]);
            $this->agentlessSessionService->killRunningBackup($agentlessSessionId);
        }

        /** @var Lock $sessionLock */
        $sessionLock = $this->retryHandler->executeAllowRetry(
            function () use ($agentlessSessionId) {
                $sessionLock = $this->agentlessSessionService->acquireSessionLock(
                    $agentlessSessionId,
                    $this->logger,
                    false
                );
                return $sessionLock;
            }
        );

        $this->backupJobStatusService->setBackupStatusCancelled($agentlessSessionId, $backupJobId);
        $this->logger->info('ALB0014 Backup cancelled successfully.', ['backupJobId' => $backupJobId]);

        return $sessionLock;
    }

    /**
     * @return string[]
     */
    public function getBackupStatus(AgentlessSessionId $agentlessSessionId, string $backupJobId): array
    {
        if (!$this->agentlessSessionService->isSessionInitialized($agentlessSessionId)) {
            throw new SessionNotFoundException($agentlessSessionId);
        }

        $cachedStatus = $this->backupJobStatusService->getStatus($agentlessSessionId, $backupJobId);

        //If backup was supposed to be running, correct status to failed.
        if ($cachedStatus['status'] === AgentlessBackupStatusService::BACKUP_STATUS_ACTIVE &&
            !$this->agentlessSessionService->isBackupRunning($agentlessSessionId)) {
            $cachedStatus['status'] = AgentlessBackupStatusService::BACKUP_STATUS_FAILED;
        }

        return $cachedStatus;
    }

    private function generateBackupJobId(): string
    {
        return 'backup-' . $this->uuidGenerator->get();
    }

    /**
     * @param string[] $volumeGuids
     * @param string[] $destinationFiles
     * @param string[] $changeIdFiles
     * @return mixed
     */
    private function doBackup(
        AgentlessSession $agentlessSession,
        string $backupJobId,
        array $volumeGuids,
        array $destinationFiles,
        array $changeIdFiles,
        bool $forceDiffmerge = false,
        bool $forceFull = false
    ) {
        $logger = $agentlessSession->getLogger();
        $logger->info('ALB0015 Backing up volumes', [
            'volumes' => $volumeGuids,
            'forceDiffmerge' => $forceDiffmerge,
            'forceFull' => $forceFull
        ]);
        if ($forceDiffmerge || $forceFull) {
            $volumeChangeIds = array_fill(0, count($volumeGuids), '*');
        } else {
            $logger->info('ALB0018 Requested incremental, reading change ids...');
            try {
                $volumeChangeIds = $this->changeIdService->readChangeIds($changeIdFiles);

                if (count($volumeGuids) !== count($volumeChangeIds)) {
                    throw new Exception('Invalid number of changeIds provided');
                }
            } catch (Throwable $exception) {
                $logger->error('ALB0019 Error while reading volume change Ids', [
                    'exception' => $exception
                ]);
                $logger->warning('ALB0020 Defaulting to "*" changeIds');
                $logger->warning('ALB0021 Doing diff-merge with all drives');
                $forceDiffmerge = true;
                $volumeChangeIds = array_fill(0, count($volumeGuids), '*');
            }
        }
        $logger->info('ALB0025 Generating Partition Backup Jobs', [
            'volumeChangeIds' => $volumeChangeIds
        ]);

        /** @var BackupJob[] $backupJobs */
        $backupJobs =
            $this->createVolumeBackupJobs(
                $agentlessSession,
                $volumeGuids,
                $volumeChangeIds,
                $destinationFiles,
                $changeIdFiles,
                $forceDiffmerge
            );

        $totalBytes = 0;
        $totalChangedAreas = 0;
        foreach ($backupJobs as $backupJob) {
            $totalBytes += $backupJob->getTotalBytes();
            $totalChangedAreas += count($backupJob->getChangedAreas());
        }
        $logger->info('ALB0026 Changes Detected', [
            'totalChangedAreas' => $totalChangedAreas,
            'totalBytes' => $totalBytes
        ]);

        //Make sure the backup always takes at least 1 second. If not backups of vms that are powered off
        //and have 0 changed areas can finish even before the startBackupBackground api call checks for backup started.
        $this->sleep->sleep(1);

        $startTime = $this->dateTimeService->getTime();
        $lastBytes = 0;
        $lastTime = $startTime;
        foreach ($backupJobs as $backupJob) {
            $this->backupJobStatusService->setVolumeBackupType(
                $agentlessSession->getAgentlessSessionId(),
                $backupJobId,
                $backupJob->getBackupJobId(),
                $backupJob->isDiffMerge() ?
                    BackupJob::DIFF_MERGE : $backupJob->getBackupType()
            );

            $logger->info('ALB0029 Executing partition backup job', [
                'backupJobId' => $backupJob->getBackupJobId()
            ]);

            $this->backupJobExecutor->executeBackupJob(
                $backupJob,
                $agentlessSession,
                $logger,
                function (
                    int $processedBytes,
                    int $writtenBytes,
                    int $skippedBytes,
                    int $elapsedTimeMs,
                    int $bytesPerSecond
                ) use (
                    $agentlessSession,
                    $backupJobId,
                    $backupJob,
                    $startTime,
                    &$lastBytes,
                    &$lastTime
                ) {
                    $timeNow = $this->dateTimeService->getTime();
                    $bytesSinceLastTime = $processedBytes - $lastBytes;
                    //Avoids division by zero
                    $timeSpan = max(1, $timeNow - $lastTime);
                    $instantBytesPerSecond = $bytesSinceLastTime / $timeSpan;
                    $lastBytes = $processedBytes;
                    $lastTime = $timeNow;

                    $agentlessSession->getLogger()->debug('ALB0043 Backup Progress', [
                        'processedMB' => ByteUnit::BYTE()->toMiB($processedBytes),
                        'writtenMB' => ByteUnit::BYTE()->toMiB($writtenBytes),
                        'skippedMB' => ByteUnit::BYTE()->toMiB($skippedBytes),
                        'elapsedSec' => $elapsedTimeMs / 1000,
                        'avgMBPS' => ByteUnit::BYTE()->toMiB($bytesPerSecond),
                        'instantMBPS' => ByteUnit::BYTE()->toMiB($instantBytesPerSecond)
                    ]);
                    $volumeGuid = $backupJob->getBackupJobId();
                    $totalSize = $backupJob->getTotalBytes();
                    $elapsedTime = $timeNow - $startTime;
                    $this->backupJobStatusService->updateVolumeTransfer(
                        $agentlessSession->getAgentlessSessionId(),
                        $backupJobId,
                        $volumeGuid,
                        $processedBytes,
                        $totalSize,
                        $elapsedTime
                    );
                }
            );

            $elapsedTime = $this->dateTimeService->getTime() - $startTime;
            $logger->info('ALB0030 Partition backup job execution finished', ['elapsedTime' => $elapsedTime]);

            $volumeGuid = $backupJob->getBackupJobId();
            $totalSize = $backupJob->getTotalBytes();
            $this->backupJobStatusService->updateVolumeTransfer(
                $agentlessSession->getAgentlessSessionId(),
                $backupJobId,
                $volumeGuid,
                $totalSize,
                $totalSize,
                $elapsedTime
            );
            $this->backupJobStatusService->setVolumeStatusFinished(
                $agentlessSession->getAgentlessSessionId(),
                $backupJobId,
                $volumeGuid
            );
        }

        return $totalBytes;
    }

    /**
     * @param string[] $includedVolumeGuids
     * @param string[] $includedVolumeChangeIds
     * @param string[] $destinationFiles
     * @param string[] $changeIdFiles
     * @return BackupJob[]
     */
    private function createVolumeBackupJobs(
        AgentlessSession $agentlessSession,
        array $includedVolumeGuids,
        array $includedVolumeChangeIds,
        array $destinationFiles,
        array $changeIdFiles,
        bool $diffMerge
    ): array {

        $volumeBackupJobsInfo = [];
        $vmdksInfo = $agentlessSession->getEsxVmInfo()['vmdkInfo'];
        $logger = $agentlessSession->getLogger();

        foreach ($includedVolumeGuids as $i => $volumeGuid) {
            $logger->info('ALB0031 Processing Partition', ['volumeGuid' => $volumeGuid]);

            if ($agentlessSession->isFullDiskBackup()) {
                $vmdkInfo = self::getDiskVmdkInfo($volumeGuid, $vmdksInfo);
            } else {
                $vmdkInfo = self::getPartitionVmdkInfo($volumeGuid, $vmdksInfo);
            }

            if (!$vmdkInfo) {
                $logger->warning('ALB0100 Volume not found in any vmdk file, skipping...', [
                    'volumeGuid' => $volumeGuid
                ]);
                continue;
            }

            // RDM disk, can't backup.
            if ($vmdkInfo['canSnapshot'] === false) {
                $logger->info('ALB0032 Cannot snapshot, skipping...');
                continue;
            }

            if ($agentlessSession->isUsingHyperShuttle()) {
                //HyperShuttle clones snapshots directly from remote
                $sourceVmdkPath = $vmdkInfo['diskPath'];
            } else {
                $sourceVmdkPath = $vmdkInfo['localDiskPath'];
            }

            if ($agentlessSession->isFullDiskBackup()) {
                $volumeInfo = [];
            } else {
                $volumeInfo = self::getPartitionInfo($volumeGuid, $vmdkInfo);
                if (!$volumeInfo) {
                    $logger->error('ALB0038 Partition does not exist for volume.', ['volumeGuid' => $volumeGuid, 'vmdkInfo' => $vmdkInfo]);
                    throw new Exception('Partition does not exist for volume.');
                }
            }

            $volumeChangeId = $includedVolumeChangeIds[$i];
            $destinationFile = $destinationFiles[$i];
            $changeIdFile = $changeIdFiles[$i];

            $logger->info('ALB0033 Creating Backup Job', [
                'sourceVmdkPath' => $sourceVmdkPath,
                'destinationFile' => $destinationFile
            ]);
            $volumeBackupJobsInfo[] = $this->createVolumeBackupJob(
                $agentlessSession,
                $sourceVmdkPath,
                $volumeChangeId,
                $vmdkInfo,
                $volumeInfo,
                $destinationFile,
                $changeIdFile,
                $diffMerge,
                $volumeGuid
            );
        }

        return $volumeBackupJobsInfo;
    }

    private function createVolumeBackupJob(
        AgentlessSession $agentlessSession,
        string $sourceVmdk,
        string $oldVmdkChangeId,
        array $vmdkInfo,
        array $partitionInfo,
        string $destinationFile,
        string $changeIdFile,
        bool $diffMerge,
        string $volumeGuid
    ): BackupJob {
        $deviceKey = $vmdkInfo['deviceKey'];
        $diskSize = $vmdkInfo['diskSizeKiB'] * 1024;
        $newChangeId = $vmdkInfo['changeId'] ?? '';

        if ($agentlessSession->isFullDiskBackup()) {
            $volumeStartOffset = 0;
            $volumeEndOffset = $diskSize;
            $volumeSizeBytes = $diskSize;
            $destinationOffset = 0;
        } else {
            $volumeStartOffset = $partitionInfo['part_start'];
            $volumeEndOffset = $partitionInfo['part_end'];
            $destinationOffset = self::MBR_OFFSET;
            $volumeSizeBytes = $partitionInfo['part_size'];
        }

        $changedAreas = [];
        $totalBytes = 0;

        $logger = $agentlessSession->getLogger();

        if (empty($oldVmdkChangeId)) {
            $logger->warning("ALB0034 Empty changeId, defaulting to '*' and doing diff-merge...");
            $oldVmdkChangeId = '*';
            $diffMerge = true;
        }

        if ($oldVmdkChangeId === '*') {
            $backupType = BackupJob::BACKUP_TYPE_FULL;
        } else {
            $backupType = BackupJob::BACKUP_TYPE_INCREMENTAL;
        }

        $logger->info('ALB0035 Creating backup job', ['volumeGuid' => $volumeGuid]);

        $startPos = 0;
        do {
            $logger->debug('ALB0036 Querying Changed Disk Areas', [
                'startPos' => $startPos,
                'changeId' => $oldVmdkChangeId
            ]);
            try {
                $changes = $this->vmwareApiClient->queryChangedDiskAreas(
                    $agentlessSession->getVirtualMachine(),
                    $agentlessSession->getSnapshotMoRefId(),
                    $deviceKey,
                    $startPos,
                    $oldVmdkChangeId
                );
            } catch (Throwable $exception) {
                $logger->error('ALB0037 Error when querying changed disk areas. Attempting full backup', [
                    'changeId' => $oldVmdkChangeId,
                    'exception' => $exception
                ]);
                $oldVmdkChangeId = '*';
                $diffMerge = true;
                $backupType = BackupJob::BACKUP_TYPE_FULL;

                try {
                    $changes = $this->vmwareApiClient->queryChangedDiskAreas(
                        $agentlessSession->getVirtualMachine(),
                        $agentlessSession->getSnapshotMoRefId(),
                        $deviceKey,
                        $startPos,
                        $oldVmdkChangeId
                    );
                } catch (Throwable $exception) {
                    $logger->error('ALB0039 Additional error when querying changed disk areas with changeId: *', [
                        'exception' => $exception
                    ]);
                    $logger->warning('ALB0040 Skipping CBT, doing completely full backup (allocated and unallocated)');

                    return $this->createCompletelyFullBackupJob(
                        $sourceVmdk,
                        $destinationFile,
                        $volumeStartOffset,
                        $destinationOffset,
                        $volumeSizeBytes,
                        $newChangeId,
                        $changeIdFile,
                        $volumeGuid,
                        $diffMerge
                    );
                }
            }

            // changedArea is not populated if there were no changes since the last backup
            $changeCount = count($changes->changedArea ?? []);

            for ($i = 0; $i < $changeCount; $i++) {
                $changedArea = $this->getClippedChangedArea(
                    $changes->changedArea[$i],
                    $volumeStartOffset,
                    $volumeEndOffset
                );

                if ($changedArea === false) {
                    continue;
                }

                $changeOffset = $destinationOffset + ($changedArea['changeStart'] - $volumeStartOffset);
                $totalBytes += $changedArea['changeLength'];
                $changedAreas[] = [
                    'source_offset' => $changedArea['changeStart'],
                    'destination_offset' => $changeOffset,
                    'length' => $changedArea['changeLength']
                ];
            }

            $startPos = $changes->startOffset + $changes->length;
        } while ($startPos < $diskSize);

        $logger->info('ALB0042 Backing up changed areas in VMDK', [
            'changedAreas' => count($changedAreas),
            'changeCount' => $changeCount
        ]);
        return new BackupJob(
            $sourceVmdk,
            $destinationFile,
            $oldVmdkChangeId,
            $changedAreas,
            $newChangeId,
            $changeIdFile,
            $totalBytes,
            $volumeGuid,
            $backupType,
            $diffMerge
        );
    }

    /**
     * Creates a backup job that skips vSphere CBT
     * This backup job services both disk and partition backup modes, since all offsets and lengths are explicitly set
     */
    private function createCompletelyFullBackupJob(
        string $sourceFile,
        string $destinationFile,
        int $sourceOffset,
        int $destinationOffset,
        int $length,
        string $newChangeId,
        string $changeIdFile,
        string $identifier,
        bool $diffMerge
    ): BackupJob {
        $changedAreas = [
            [
                'source_offset' => $sourceOffset,
                'destination_offset' => $destinationOffset,
                'length' => $length
            ]
        ];

        return new BackupJob(
            $sourceFile,
            $destinationFile,
            '',
            $changedAreas,
            $newChangeId,
            $changeIdFile,
            $length,
            $identifier,
            BackupJob::BACKUP_TYPE_FULL_NO_CBT,
            $diffMerge
        );
    }

    /**
     * Get changed area info clipped to partition bounds.
     *
     * The changed area information returned from VMware's CBT corresponds to
     * a whole VMDK image so it needs to be clipped to the bounds of the
     * partition that we take the backup of.
     *
     * @return array|false
     *  False if changedArea falls out of partition bounds that we're trying to
     *  backup.
     */
    private function getClippedChangedArea(
        DiskChangeExtent $changedArea,
        int $partStart,
        int $partEnd
    ) {
        $changeLength = $changedArea->length;
        $changeStart = $changedArea->start;
        $changeEnd = $changeStart + $changeLength - 1;

        // if start offset is past partition end, break
        if ($changeStart > $partEnd || $changeEnd < $partStart) {
            return false;
        }

        // change starts before partition start, adjust start position.
        if ($changeStart < $partStart) {
            $changeStart = $partStart;
        }

        // if the change extends beyond end of partition, trim length.
        if ($changeEnd > $partEnd) {
            $changeEnd = $partEnd;
        }

        $length = $changeEnd - $changeStart + 1;

        $ret = [
            'changeStart' => $changeStart,
            'changeEnd' => $changeEnd,
            'changeLength' => $length,
        ];

        return $ret;
    }

    /**
     * @return mixed|null
     */
    private static function getPartitionVmdkInfo(string $partitionGuid, array $vmdksInfo)
    {
        foreach ($vmdksInfo as $vmdkInfo) {
            foreach ($vmdkInfo['partitions'] as $partition) {
                if ($partitionGuid === $partition['guid']) {
                    return $vmdkInfo;
                }
            }
        }

        return null;
    }

    /**
     * @param string $diskUuid
     * @param array $vmdksInfo
     * @return mixed|null
     */
    private static function getDiskVmdkInfo(string $diskUuid, array $vmdksInfo)
    {
        foreach ($vmdksInfo as $vmdkInfo) {
            if ($diskUuid === $vmdkInfo['diskUuid']) {
                return $vmdkInfo;
            }
        }

        return null;
    }

    /**
     * @return mixed|null
     */
    private static function getPartitionInfo(string $partitionGuid, array $vmdkInfo)
    {
        foreach ($vmdkInfo['partitions'] as $partition) {
            if ($partitionGuid === $partition['guid']) {
                return $partition;
            }
        }

        return null;
    }
}
