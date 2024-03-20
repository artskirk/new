<?php

namespace Datto\Asset\Share\ExternalNas;

/**
 * Represents the state and progress of an external NAS backup.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class BackupProgress
{
    /** @var BackupStatusType */
    private $state;
    
    /** @var int */
    private $bytesTransferred;
    
    /** @var string */
    private $transferRate;
    
    /**
     * @param BackupStatusType $state
     * @param int $bytesTransferred
     * @param string $transferRate
     */
    public function __construct(BackupStatusType $state, $bytesTransferred = 0, $transferRate = '')
    {
        $this->state = $state;
        $this->bytesTransferred = $bytesTransferred;
        $this->transferRate = $transferRate;
    }
    
    /**
     * Get the backup status, i.e. "idle", "in progress", etc.
     *
     * @return BackupStatusType  Status code maps to the constants IDLE, IN_PROGRESS, etc.
     */
    public function getStatus()
    {
        return $this->state;
    }
    
    /**
     * Get the total number of bytes transferred by the backup process.
     *
     * @return int
     */
    public function getBytesTransferred()
    {
        return $this->bytesTransferred;
    }
    
    /**
     * Get the backup transfer rate.
     *
     * @return string    Transfer rate in whatever format rsync returns.
     */
    public function getTransferRate()
    {
        return $this->transferRate;
    }
}
