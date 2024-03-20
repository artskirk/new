<?php

namespace Datto\Restore\PushFile;

use Datto\Asset\Agent\Api\AgentTransferState;

/**
 * Status of a push file restore data transfer.
 *
 * @author Ryan Mack <rmack@datto.com>
 */
class PushFileRestoreStatus
{
    private string $restoreID;

    private int $bytesTransferred;

    private AgentTransferState $status;

    private int $totalSize;

    private int $errorCode;

    private string $errorCodeStr;

    private string $errorMsg;

    public function setStatus(AgentTransferState $status)
    {
        $this->status = $status;
    }

    public function getStatus(): AgentTransferState
    {
        return $this->status;
    }

    public function setRestoreID(string $restoreID)
    {
        $this->restoreID = $restoreID;
    }

    public function getRestoreID(): string
    {
        return $this->restoreID;
    }

    public function setBytesTransferred(int $bytesTransferred)
    {
        $this->bytesTransferred = $bytesTransferred;
    }

    public function getBytesTransferred(): int
    {
        return $this->bytesTransferred;
    }

    public function setTotalSize(int $totalSize)
    {
        $this->totalSize = $totalSize;
    }

    public function getTotalSize(): int
    {
        return $this->totalSize;
    }

    public function setErrorCode(int $errorCode)
    {
        $this->errorCode = $errorCode;
    }

    public function getErrorCode(): int
    {
        return $this->errorCode;
    }

    public function setErrorCodeStr(string $errorCodeStr)
    {
        $this->errorCodeStr = $errorCodeStr;
    }

    public function getErrorCodeStr(): string
    {
        return $this->errorCodeStr;
    }

    public function setErrorMsg(string $errorMsg)
    {
        $this->errorMsg = $errorMsg;
    }

    public function getErrorMsg(): string
    {
        return $this->errorMsg;
    }
}
