<?php

namespace Datto\Backup;

/**
 * A collection of information related to a backup for an asset.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class BackupInfo
{
    /** @var bool */
    private $isQueued;

    /** @var BackupStatus */
    private $status;

    /**
     * @param bool $isQueued
     * @param BackupStatus $status
     */
    public function __construct(bool $isQueued, BackupStatus $status)
    {
        $this->isQueued = $isQueued;
        $this->status = $status;
    }

    /**
     * Set if the asset currently has a queued backup.
     *
     * @return bool
     */
    public function isQueued(): bool
    {
        return $this->isQueued;
    }

    /**
     * @return BackupStatus
     */
    public function getStatus(): BackupStatus
    {
        return $this->status;
    }
}
