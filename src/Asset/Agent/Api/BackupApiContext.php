<?php

namespace Datto\Asset\Agent\Api;

use Datto\Asset\Agent\Volume;
use Datto\Backup\File\BackupImageFile;
use Datto\Backup\Transport\BackupTransport;

/**
 * Handles the agent API's backup context
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class BackupApiContext
{
    /** @var string */
    private $assetKeyName;

    /** @var BackupTransport */
    private $backupTransport;

    /** @var bool */
    private $forceDiffMerge;

    /** @var string[] */
    private $forceDiffMergeVolumeGuids;

    /** @var bool */
    private $forceCopyFull;

    /** @var int */
    private $writeSize;

    /** @var string */
    private $backupEngineConfigured;

    /** @var string */
    private $backupEngineUsed;

    /** @var array */
    private $vssExclusions;

    /** @var bool */
    private $rollbackOnFailure;

    /** @var bool */
    private $cacheWrites;

    /** @var array */
    private $quiescingScripts;

    /** @var string */
    private $agentType;

    /** @var string */
    private $hostOverride;

    /** @var string */
    private $osVersion;

    /** @var Volume[] */
    private $volumes;

    /** @var BackupImageFile */
    private $backupImageFile;

    /**
     * @param string $assetKeyName
     * @param BackupTransport $backupTransport
     * @param bool $forceDiffMerge
     * @param string[] $forceDiffMergeVolumeGuids
     * @param bool $forceCopyFull
     * @param int $writeSize
     * @param string $backupEngineConfigured
     * @param string $backupEngineUsed
     * @param array $vssExclusions
     * @param bool $rollbackOnFailure
     * @param bool $cacheWrites
     * @param array $quiescingScripts
     * @param string $agentType
     * @param string $hostOverride
     * @param string $osVersion
     * @param Volume[] $volumes
     * @param BackupImageFile $backupImageFile
     */
    public function __construct(
        string $assetKeyName,
        BackupTransport $backupTransport,
        bool $forceDiffMerge,
        array $forceDiffMergeVolumeGuids,
        bool $forceCopyFull,
        int $writeSize,
        string $backupEngineConfigured,
        string $backupEngineUsed,
        array $vssExclusions,
        bool $rollbackOnFailure,
        bool $cacheWrites,
        array $quiescingScripts,
        string $agentType,
        string $hostOverride,
        string $osVersion,
        array $volumes,
        BackupImageFile $backupImageFile
    ) {
        $this->assetKeyName = $assetKeyName;
        $this->backupTransport = $backupTransport;
        $this->forceDiffMerge = $forceDiffMerge;
        $this->forceDiffMergeVolumeGuids = $forceDiffMergeVolumeGuids;
        $this->forceCopyFull = $forceCopyFull;
        $this->writeSize = $writeSize;
        $this->backupEngineConfigured = $backupEngineConfigured;
        $this->backupEngineUsed = $backupEngineUsed;
        $this->vssExclusions = $vssExclusions;
        $this->rollbackOnFailure = $rollbackOnFailure;
        $this->cacheWrites = $cacheWrites;
        $this->quiescingScripts = $quiescingScripts;
        $this->agentType = $agentType;
        $this->hostOverride = $hostOverride;
        $this->osVersion = $osVersion;
        $this->volumes = $volumes;
        $this->backupImageFile = $backupImageFile;
    }

    /**
     * @return BackupImageFile
     */
    public function getBackupImageFile(): BackupImageFile
    {
        return $this->backupImageFile;
    }

    /**
     * @return string
     */
    public function getAssetKeyName(): string
    {
        return $this->assetKeyName;
    }

    /**
     * @return BackupTransport
     */
    public function getBackupTransport(): BackupTransport
    {
        return $this->backupTransport;
    }

    /**
     * @return bool
     */
    public function isForceDiffMerge(): bool
    {
        return $this->forceDiffMerge;
    }

    /**
     * @return string[]
     */
    public function getForceDiffMergeVolumeGuids(): array
    {
        return $this->forceDiffMergeVolumeGuids;
    }

    /**
     * @return bool
     */
    public function isForceCopyFull(): bool
    {
        return $this->forceCopyFull;
    }

    /**
     * @return int
     */
    public function getWriteSize(): int
    {
        return $this->writeSize;
    }

    /**
     * @return string
     */
    public function getBackupEngineConfigured(): string
    {
        return $this->backupEngineConfigured;
    }

    /**
     * @return string
     */
    public function getBackupEngineUsed(): string
    {
        return $this->backupEngineUsed;
    }

    /**
     * @return array
     */
    public function getVssExclusions(): array
    {
        return $this->vssExclusions;
    }

    /**
     * @return bool
     */
    public function isRollbackOnFailure(): bool
    {
        return $this->rollbackOnFailure;
    }

    /**
     * @return bool
     */
    public function useCacheWrites(): bool
    {
        return $this->cacheWrites;
    }

    /**
     * @return array
     */
    public function getQuiescingScripts(): array
    {
        return $this->quiescingScripts;
    }

    /**
     * @return string
     */
    public function getAgentType(): string
    {
        return $this->agentType;
    }

    /**
     * @return string
     */
    public function getHostOverride(): string
    {
        return $this->hostOverride;
    }

    /**
     * @return string
     */
    public function getOsVersion(): string
    {
        return $this->osVersion;
    }

    /**
     * @return Volume[]
     */
    public function getVolumes(): array
    {
        return $this->volumes;
    }
}
