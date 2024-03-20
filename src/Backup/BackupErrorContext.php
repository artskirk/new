<?php

namespace Datto\Backup;

/**
 * This class holds the context for logging agent backup errors.
 */
class BackupErrorContext
{
    private int $errorCode;
    private string $errorCodeString;
    private string $errorMessage;
    private bool $backupResumable;

    public function __construct(
        int $errorCode,
        string $errorCodeString,
        string $errorMessage,
        bool $backupResumable
    ) {
        $this->errorCode = $errorCode;
        $this->errorCodeString = $errorCodeString;
        $this->errorMessage = $errorMessage;
        $this->backupResumable = $backupResumable;
    }

    public function toArray()
    {
        return [
            'errorCode' => $this->errorCode,
            'errorCodeStr' => $this->errorCodeString,
            'errorMessage' => $this->errorMessage,
            'backupResumable' => $this->backupResumable
        ];
    }
}
