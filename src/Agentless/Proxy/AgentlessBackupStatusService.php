<?php

namespace Datto\Agentless\Proxy;

use Datto\Utility\File\Lock;
use Datto\Utility\File\LockFactory;
use Datto\Common\Utility\Filesystem;

/**
 * Service class that takes care of storing to disk the cached status of an agentless backup job.
 * This is not the high level class used to retrieve the actual state of the backup, for that use:
 * \Datto\Agentless\Proxy\AgentlessBackupService::getBackupStatus
 *
 * @author Mario Rial <mrial@datto.com>
 */
class AgentlessBackupStatusService
{
    public const BACKUP_STATUS_ACTIVE = "active";
    public const BACKUP_STATUS_FINISHED = "finished";
    public const BACKUP_STATUS_FAILED = "failed";
    public const BACKUP_STATUS_CANCELLED = "cancelled";

    public const JOB_STATUS_PATH_FORMAT = AgentlessSessionService::SESSION_BASE_PATH . '%s/%s.status';

    /** @var Filesystem */
    private $filesystem;

    /** @var LockFactory */
    private $lockFactory;

    /**
     * @param Filesystem $filesystem
     * @param LockFactory $lockFactory
     */
    public function __construct(Filesystem $filesystem, LockFactory $lockFactory)
    {
        $this->filesystem = $filesystem;
        $this->lockFactory = $lockFactory;
    }

    /**
     * @param AgentlessSessionId $agentlessSessionId
     * @param string $backupJobId
     * @param array $partitionIds
     */
    public function initializeStatus(AgentlessSessionId $agentlessSessionId, string $backupJobId, array $partitionIds): void
    {
        $lock = $this->getLock($agentlessSessionId, $backupJobId);

        $status = [
            'status' => self::BACKUP_STATUS_ACTIVE,
            'elapsedTime' => 0,
            'connection_error' => false,
            'snapshot_error' => false,
            'errorData' => [
                "errorCode" => 0,
                "errorParams" => [],
                "errorMsg" => ""
            ]
        ];
        $status['details'] = [];

        foreach ($partitionIds as $partitionId) {
            $status['details'][] = [
                'volume' => $partitionId,
                'status' => self::BACKUP_STATUS_ACTIVE,
                'type' => '',
                'transfer' => [
                    'bytesTransferred' => 0,
                    'totalSize' => -1
                ]
            ];
        }

        $this->writeStatus($agentlessSessionId, $backupJobId, $status);
        $lock->unlock();
    }

    /**
     * @param AgentlessSessionId $agentlessSessionId
     * @param string $backupJobId
     * @param string $partitionGuid
     * @param int $bytesTransferred
     * @param int $totalSize
     * @param int $elapsedTime
     */
    public function updateVolumeTransfer(
        AgentlessSessionId $agentlessSessionId,
        string $backupJobId,
        string $partitionGuid,
        int $bytesTransferred,
        int $totalSize,
        int $elapsedTime
    ): void {
        $lock = $this->getLock($agentlessSessionId, $backupJobId);
        $status = $this->readStatus($agentlessSessionId, $backupJobId);

        foreach ($status['details'] as &$volDetails) {
            if ($volDetails['volume'] === $partitionGuid) {
                $volDetails['transfer']['bytesTransferred'] = $bytesTransferred;
                $volDetails['transfer']['totalSize'] = $totalSize;
            }
        }

        $status['elapsedTime'] = $elapsedTime;

        $this->writeStatus($agentlessSessionId, $backupJobId, $status);
        $lock->unlock();
    }

    /**
     * @param AgentlessSessionId $agentlessSessionId
     * @param string $backupJobId
     * @param string $volumeGuid
     * @param string $backupType
     */
    public function setVolumeBackupType(
        AgentlessSessionId $agentlessSessionId,
        string $backupJobId,
        string $volumeGuid,
        string $backupType
    ): void {
        $lock = $this->getLock($agentlessSessionId, $backupJobId);
        $status = $this->readStatus($agentlessSessionId, $backupJobId);

        foreach ($status['details'] as &$volDetails) {
            if ($volDetails['volume'] === $volumeGuid) {
                $volDetails['type'] = $backupType;
            }
        }

        $this->writeStatus($agentlessSessionId, $backupJobId, $status);
        $lock->unlock();
    }

    /**
     * @param AgentlessSessionId $agentlessSessionId
     * @param string $backupJobId
     * @param string $volumeGuid
     */
    public function setVolumeStatusFinished(
        AgentlessSessionId $agentlessSessionId,
        string $backupJobId,
        string $volumeGuid
    ): void {
        $lock = $this->getLock($agentlessSessionId, $backupJobId);
        $status = $this->readStatus($agentlessSessionId, $backupJobId);

        foreach ($status['details'] as &$volDetails) {
            if ($volDetails['volume'] === $volumeGuid) {
                $volDetails['status'] = self::BACKUP_STATUS_FINISHED;
            }
        }
        $this->writeStatus($agentlessSessionId, $backupJobId, $status);
        $lock->unlock();
    }

    /**
     * @param AgentlessSessionId $agentlessSessionId
     * @param string $backupJobId
     */
    public function setBackupStatusFinished(AgentlessSessionId $agentlessSessionId, string $backupJobId): void
    {
        $this->setBackupStatus($agentlessSessionId, $backupJobId, self::BACKUP_STATUS_FINISHED);
    }

    /**
     * @param AgentlessSessionId $agentlessSessionId
     * @param string $backupJobId
     * @param string|null $errorMsg
     */
    public function setBackupStatusFailed(
        AgentlessSessionId $agentlessSessionId,
        string $backupJobId,
        string $errorMsg = null
    ): void {
        $lock = $this->getLock($agentlessSessionId, $backupJobId);
        $status = $this->readStatus($agentlessSessionId, $backupJobId);

        $status['status'] = self::BACKUP_STATUS_FAILED;
        $status['errorData']['errorMsg'] = $errorMsg;

        $this->writeStatus($agentlessSessionId, $backupJobId, $status);
        $lock->unlock();
    }

    /**
     * @param AgentlessSessionId $agentlessSessionId
     * @param string $backupJobId
     */
    public function setBackupStatusCancelled(AgentlessSessionId $agentlessSessionId, string $backupJobId): void
    {
        $this->setBackupStatus($agentlessSessionId, $backupJobId, self::BACKUP_STATUS_CANCELLED);
    }

    /**
     * @param AgentlessSessionId $agentlessSessionId
     * @param string $backupJobId
     * @return mixed
     */
    public function getStatus(AgentlessSessionId $agentlessSessionId, string $backupJobId)
    {
        $lock = $this->getLock($agentlessSessionId, $backupJobId, true);
        $status = $this->readStatus($agentlessSessionId, $backupJobId);
        $lock->unlock();

        return $status;
    }

    /**
     * @param AgentlessSessionId $agentlessSessionId
     * @param string $backupJobId
     * @param string $statusValue
     */
    private function setBackupStatus(AgentlessSessionId $agentlessSessionId, string $backupJobId, string $statusValue): void
    {
        $lock = $this->getLock($agentlessSessionId, $backupJobId);
        $status = $this->readStatus($agentlessSessionId, $backupJobId);

        $status['status'] = $statusValue;

        $this->writeStatus($agentlessSessionId, $backupJobId, $status);
        $lock->unlock();
    }

    /**
     * @param AgentlessSessionId $agentlessSessionId
     * @param string $backupJobId
     * @return mixed
     */
    private function readStatus(AgentlessSessionId $agentlessSessionId, string $backupJobId)
    {
        return json_decode($this->filesystem->fileGetContents($this->getJobStatusPath($agentlessSessionId, $backupJobId)), true);
    }

    /**
     * @param AgentlessSessionId $agentlessSessionId
     * @param string $backupJobId
     * @param array $status
     */
    private function writeStatus(AgentlessSessionId $agentlessSessionId, string $backupJobId, array $status): void
    {
        $this->filesystem->filePutContents(
            $this->getJobStatusPath($agentlessSessionId, $backupJobId),
            json_encode($status, JSON_PRETTY_PRINT)
        );
    }

    /**
     * @param AgentlessSessionId $agentlessSessionId
     * @param string $backupJobId
     * @param bool $readLock
     * @return Lock
     */
    private function getLock(AgentlessSessionId $agentlessSessionId, string $backupJobId, bool $readLock = false)
    {
        $lock = $this->lockFactory->getProcessScopedLock($this->getJobStatusPath($agentlessSessionId, $backupJobId));

        if ($readLock) {
            $lock->shared();
        } else {
            $lock->exclusive();
        }

        return $lock;
    }

    /**
     * @param AgentlessSessionId $agentlessSessionId
     * @param string $jobId
     * @return string
     */
    private function getJobStatusPath(AgentlessSessionId $agentlessSessionId, string $jobId): string
    {
        return sprintf(self::JOB_STATUS_PATH_FORMAT, $agentlessSessionId->toSessionIdName(), $jobId);
    }
}
