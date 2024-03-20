<?php

namespace Datto\Reporting\Backup;

/**
 * Class BackupReport
 * This model class holds state relating to starting and stopping a BackupReport. Used with BackupReportManager
 *
 * @package Datto\Reporting\Backup
 */
class BackupReportContext
{
    private $keyName;
    private ?BackupReport $backupReport;
    private ?BackupAttemptStatus $backupAttemptStatus;

    public function __construct(
        string $keyName
    ) {
        $this->keyName = $keyName;
        $this->backupReport = null;
        $this->backupAttemptStatus = null;
    }

    public function getKeyName(): string
    {
        return $this->keyName;
    }

    public function getBackupReport(): ?BackupReport
    {
        return $this->backupReport;
    }

    public function getBackupAttemptStatus(): ?BackupAttemptStatus
    {
        return $this->backupAttemptStatus;
    }

    public function setBackupReport(?BackupReport $backupReport)
    {
        $this->backupReport = $backupReport;
    }

    public function setBackupAttemptStatus(?BackupAttemptStatus $backupAttemptStatus)
    {
        $this->backupAttemptStatus = $backupAttemptStatus;
    }
}
