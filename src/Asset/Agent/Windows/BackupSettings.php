<?php

namespace Datto\Asset\Agent\Windows;

/**
 * Asset settings related to taking and storing snapshots on the device.
 *
 * @author John Fury Christ <jchrist@datto.com>
 */
class BackupSettings
{
    public const DEFAULT_BACKUP_ENGINE = 'both';
    public const VSS_BACKUP_ENGINE = 'VSS';
    public const DBD_BACKUP_ENGINE = 'DBD'; // only applies to datto agents
    public const STC_BACKUP_ENGINE = 'STC'; // only applies to shadowsnap agents

    private const VALID_OPTIONS = [
        self::DEFAULT_BACKUP_ENGINE,
        self::VSS_BACKUP_ENGINE,
        self::DBD_BACKUP_ENGINE,
        self::STC_BACKUP_ENGINE
    ];

    private string $backupEngine;

    public function __construct($backupEngine = self::DEFAULT_BACKUP_ENGINE)
    {
        $backupEngine = trim($backupEngine);
        // $backupEngine may be null when fetched from the serializer which indicates we should use the default
        if (!in_array($backupEngine, self::VALID_OPTIONS, true)) {
            $backupEngine = self::DEFAULT_BACKUP_ENGINE;
        }

        $this->setBackupEngine($backupEngine);
    }

    public function getBackupEngine(): string
    {
        return $this->backupEngine;
    }

    public function setBackupEngine(string $backupEngine): void
    {
        $this->backupEngine = $backupEngine;
    }
}
