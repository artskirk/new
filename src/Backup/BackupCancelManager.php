<?php

namespace Datto\Backup;

use Datto\Asset\Asset;
use Datto\Config\AgentConfigFactory;

/**
 * This class handles the backup cancellation mechanism.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class BackupCancelManager
{
    /** File extension used to signal a backup cancel */
    const CANCEL_BACKUP_FILE_EXT = 'killMe';

    /** @const int The default number of seconds to wait for the backup cancellation to be processed */
    const CANCEL_WAIT_SECONDS = 60;

    private AgentConfigFactory $agentConfigFactory;
    private BackupLockFactory $backupLockFactory;

    /** @var BackupLock[] */
    private array $backupLocks = [];

    public function __construct(AgentConfigFactory $agentConfigFactory, BackupLockFactory $backupLockFactory)
    {
        $this->agentConfigFactory = $agentConfigFactory;
        $this->backupLockFactory = $backupLockFactory;
    }

    /**
     * Cancel a backup for the given asset
     *
     * @param Asset $asset
     */
    public function cancel(Asset $asset)
    {
        $agentConfig = $this->agentConfigFactory->create($asset->getKeyName());
        $agentConfig->set(self::CANCEL_BACKUP_FILE_EXT, 1);
    }

    /**
     * Cancel any currently running backup for this asset, and acquire the lock so that no other backup processes
     * can start for this asset
     *
     * @param Asset $asset
     */
    public function cancelRunningAndSuspend(Asset $asset)
    {
        $assetBackupLock = $this->getBackUpLock($asset);
        if ($assetBackupLock->isLocked()) {
            $this->cancel($asset);
        }
        // Canceling the backup is asynchronous, so we have to wait until our request is processed
        // before the lock is released
        $assetBackupLock->acquire(self::CANCEL_WAIT_SECONDS);
    }

    /**
     * Allow the asset to resume backups by releasing the backup lock
     *
     * @param Asset $asset
     */
    public function resumeBackups(Asset $asset)
    {
        $assetBackupLock = $this->getBackUpLock($asset);
        $assetBackupLock->release();
        unset($this->backupLocks[$asset->getKeyName()]);
    }

    /**
     * Determine whether the backup is in the process of being cancelled for an asset.
     *
     * @param Asset $asset
     * @return bool True if the backup has been cancelled.
     */
    public function isCancelling(Asset $asset)
    {
        $agentConfig = $this->agentConfigFactory->create($asset->getKeyName());
        return $agentConfig->has(self::CANCEL_BACKUP_FILE_EXT);
    }

    /**
     * Clean up after the backup has been cancelled.
     *
     * @param Asset $asset
     */
    public function cleanup(Asset $asset)
    {
        $agentConfig = $this->agentConfigFactory->create($asset->getKeyName());
        $agentConfig->clear(self::CANCEL_BACKUP_FILE_EXT);
    }

    /**
     * Retrieve the BackupLock for the current Asset, generate if not present.
     * @param Asset $asset
     * @return BackupLock The retrieved/generated BackupLock
     */
    private function getBackUpLock(Asset $asset): BackupLock
    {
        if (!isset($this->backupLocks[$asset->getKeyName()])) {
            $this->backupLocks[$asset->getKeyName()] = $this->backupLockFactory->create($asset->getKeyName());
        }
        return $this->backupLocks[$asset->getKeyName()];
    }
}
