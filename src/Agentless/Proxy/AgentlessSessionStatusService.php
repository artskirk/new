<?php

namespace Datto\Agentless\Proxy;

use Datto\Utility\File\Lock;
use Datto\Utility\File\LockFactory;
use Datto\Common\Utility\Filesystem;

/**
 * Service class that takes care of storing to disk the cached status of an agentless session.
 * This is not the high level class used to retrieve the actual state of the session, for that use:
 * \Datto\Agentless\Proxy\AgentlessSessionService::getSessionStatus
 *
 * @author Mario Rial <mrial@datto.com>
 */
class AgentlessSessionStatusService
{
    public const SESSION_STATUS_PATH_FORMAT = AgentlessSessionService::SESSION_BASE_PATH . '%s/session.status';

    public const SESSION_STATUS_STARTING = 'starting';
    public const SESSION_STATUS_PRE_CLEANING = 'pre_cleaning';
    public const SESSION_STATUS_CREATE_SNAPSHOT = 'create_snapshot';
    public const SESSION_STATUS_MOUNT_VDDK = 'mount_vddk';
    public const SESSION_STATUS_ESX_INFO = 'retrieve_esx_info';
    public const SESSION_STATUS_AGENT_INFO = 'retrieve_agent_info';
    public const SESSION_STATUS_READY = 'ready';
    public const SESSION_STATUS_CLEANING = 'cleaning';
    public const SESSION_STATUS_CLEANED_UP = 'cleaned_up';
    public const SESSION_STATUS_FAILED = 'failed';

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
     */
    public function initializeStatus(AgentlessSessionId $agentlessSessionId): void
    {
        $lock = $this->getLock($agentlessSessionId);

        $status = [
            'status' => self::SESSION_STATUS_STARTING,
            'status_detail' => '',
            'error' => '',
            'hostVersion' => '',
            'bypassingManagementServer' => false
        ];

        $this->writeStatus($agentlessSessionId, $status);
        $lock->unlock();
    }

    /**
     * @param AgentlessSessionId $agentlessSessionId
     * @param string $statusValue
     * @param string|null $statusDetail
     */
    public function setSessionStatus(
        AgentlessSessionId $agentlessSessionId,
        string $statusValue,
        string $statusDetail = null
    ): void {
        $lock = $this->getLock($agentlessSessionId);

        $status = $this->readStatus($agentlessSessionId);
        $status['status'] = $statusValue;
        if ($statusDetail) {
            $status['status_detail'] = $statusDetail;
        }

        $this->writeStatus($agentlessSessionId, $status);
        $lock->unlock();
    }

    /**
     * @param AgentlessSessionId $agentlessSessionId
     * @param string $error
     */
    public function setSessionError(AgentlessSessionId $agentlessSessionId, $error): void
    {
        $lock = $this->getLock($agentlessSessionId);

        $status = $this->readStatus($agentlessSessionId);
        $status['status'] = self::SESSION_STATUS_FAILED;
        $status['error'] = $error;

        $this->writeStatus($agentlessSessionId, $status);
        $lock->unlock();
    }

    /**
     * @param AgentlessSessionId $agentlessSessionId
     * @param string|null $hostVersion
     */
    public function setHostVersion(AgentlessSessionId $agentlessSessionId, string $hostVersion = null): void
    {
        $lock = $this->getLock($agentlessSessionId);

        $status = $this->readStatus($agentlessSessionId);
        $status['hostVersion'] = $hostVersion;

        $this->writeStatus($agentlessSessionId, $status);
        $lock->unlock();
    }

    /**
     * @param AgentlessSessionId $agentlessSessionId
     * @param bool $isBypassing
     */
    public function setBypassingManagementServer(AgentlessSessionId $agentlessSessionId, bool $isBypassing): void
    {
        $lock = $this->getLock($agentlessSessionId);

        $status = $this->readStatus($agentlessSessionId);
        $status['bypassingManagementServer'] = $isBypassing;

        $this->writeStatus($agentlessSessionId, $status);
        $lock->unlock();
    }

    /**
     * @param AgentlessSessionId $agentlessSessionId
     * @return mixed
     */
    public function readStatus(AgentlessSessionId $agentlessSessionId)
    {
        return json_decode($this->filesystem->fileGetContents($this->getSessionStatusPath($agentlessSessionId)), true);
    }

    /**
     * Checks the filesystem to see if the session status file exists.
     * @param AgentlessSessionId $agentlessSessionId
     * @return bool
     */
    public function statusExists(AgentlessSessionId $agentlessSessionId): bool
    {
        return $this->filesystem->exists($this->getSessionStatusPath($agentlessSessionId));
    }

    /**
     * @param AgentlessSessionId $agentlessSessionId
     * @param array $status
     */
    private function writeStatus(AgentlessSessionId $agentlessSessionId, array $status): void
    {
        $this->filesystem->filePutContents(
            $this->getSessionStatusPath($agentlessSessionId),
            json_encode($status, JSON_PRETTY_PRINT)
        );
    }

    /**
     * @param AgentlessSessionId $agentlessSessionId
     * @return Lock
     */
    private function getLock(AgentlessSessionId $agentlessSessionId)
    {
        $lock = $this->lockFactory->getProcessScopedLock($this->getSessionStatusPath($agentlessSessionId));
        $lock->exclusive();

        return $lock;
    }

    /**
     * @param AgentlessSessionId $agentlessSessionId
     * @return string
     */
    private function getSessionStatusPath(AgentlessSessionId $agentlessSessionId): string
    {
        return sprintf(self::SESSION_STATUS_PATH_FORMAT, $agentlessSessionId->toSessionIdName());
    }
}
