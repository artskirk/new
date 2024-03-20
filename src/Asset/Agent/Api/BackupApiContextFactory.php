<?php

namespace Datto\Asset\Agent\Api;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\DiffMergeService;
use Datto\Asset\Agent\Linux\LinuxAgent;
use Datto\Asset\Agent\Windows\WindowsAgent;
use Datto\Asset\AssetType;
use Datto\Backup\BackupContext;
use Datto\Backup\File\BackupImageFile;
use Datto\Config\AgentConfig;
use Datto\Config\DeviceConfig;

/**
 * Creates backup api contexts.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class BackupApiContextFactory
{
    const BACKUP_TYPE_DBD = 'DBD';
    const BACKUP_TYPE_VSS = 'VSS';
    const BACKUP_TYPE_STC = 'STC';
    const BACKUP_TYPE_BOTH = 'both';
    const BACKUP_TYPE_NONE = '';
    const UNKNOWN_RESULT_VSS_ATTEMPT_LIMIT = 2;

    /** @var BackupContext */
    private $backupContext;

    /** @var int */
    private $transferAttempt;

    /** @var AgentTransferResult */
    private $agentTransferResult;

    /** @var AgentConfig */
    private $agentConfig;

    /** @var DeviceConfig */
    private $deviceConfig;

    private DiffMergeService $diffMergeService;

    public function create(
        BackupContext $backupContext,
        int $transferAttempt,
        AgentTransferResult $agentTransferResult,
        BackupImageFile $backupImageFile,
        DiffMergeService $diffMergeService,
        AgentConfig $agentConfig = null,
        DeviceConfig $deviceConfig = null
    ): BackupApiContext {
        $this->backupContext = $backupContext;
        $this->transferAttempt = $transferAttempt;
        $this->agentTransferResult = $agentTransferResult;
        $this->diffMergeService = $diffMergeService;
        $this->agentConfig = $agentConfig ?: new AgentConfig($backupContext->getAsset()->getKeyName());
        $this->deviceConfig = $deviceConfig ?: new DeviceConfig();
        $hostOverride = $this->agentConfig->get('hostOverride') ?: '';
        /** @var Agent $agent */
        $agent = $backupContext->getAsset();
        $osVersion = $agent->getOperatingSystem()->getVersion() ?? '';

        $backupApiContext = new BackupApiContext(
            $backupContext->getAsset()->getKeyName(),
            $backupContext->getBackupTransport(),
            $this->isDiffMergeAllVolumes(),
            $this->getDiffMergeVolumeGuids(),
            $this->getForceFull(),
            $this->getWriteSize(),
            $this->getBackupEngineConfigured(),
            $this->getBackupEngineUsed(),
            $this->getVssExclusions(),
            $this->isRollbackOnFailureEnabled(),
            $this->useCachedWrites(),
            $this->getQuiescingScripts(),
            $backupContext->getAsset()->getType(),
            $hostOverride,
            $osVersion,
            $agent->getVolumes()->getArrayCopy(),
            $backupImageFile
        );

        return $backupApiContext;
    }

    /**
     * @return bool
     */
    private function isDiffMergeAllVolumes(): bool
    {
        $forceFull = $this->getForceFull();
        $asset = $this->backupContext->getAsset();
        if ($asset->supportsDiffMerge()) {
            return !$forceFull && $this->diffMergeService->getDiffMergeSettings($asset->getKeyName())->isAllVolumes();
        } else {
            return false;
        }
    }

    /**
     * @return array
     */
    private function getDiffMergeVolumeGuids(): array
    {
        if ($this->getForceFull()) {
            return [];
        }

        $asset = $this->backupContext->getAsset();
        if ($asset->supportsDiffMerge()) {
            return $this->diffMergeService->getDiffMergeSettings($asset->getKeyName())->getVolumeGuids();
        }
        return [];
    }

    /**
     * @return bool
     */
    private function getForceFull(): bool
    {
        return $this->agentConfig->has('forceFull');
    }

    /**
     * Get the write size.
     * Null enables variable size writes, which is the default
     *
     * @return int
     */
    private function getWriteSize()
    {
        $writeSize = 0;
        $asset = $this->backupContext->getAsset();
        /** @var Agent $agent */
        $agent = $asset;
        $platform = $agent->getPlatform();
        if ($platform === AgentPlatform::SHADOWSNAP() &&
            $this->agentConfig->has('writeSize')) {
            $writeSize = (int)$this->agentConfig->get('writeSize');
        }
        return $writeSize;
    }

    /**
     * @return string
     */
    private function getBackupEngineConfigured(): string
    {
        $backupEngine = self::BACKUP_TYPE_NONE;

        $asset = $this->backupContext->getAsset();
        if ($asset->isType(AssetType::WINDOWS_AGENT)) {
            /** @var WindowsAgent $agent */
            $agent = $asset;
            $backupEngine = $agent->getBackupSettings()->getBackupEngine();
        }

        return $backupEngine;
    }

    /**
     * @return string
     */
    private function getBackupEngineUsed(): string
    {
        $backupEngine = $this->getBackupEngineConfigured();

        if ($backupEngine === self::BACKUP_TYPE_BOTH) {
            $asset = $this->backupContext->getAsset();
            /** @var WindowsAgent $agent */
            $agent = $asset;
            $platform = $agent->getPlatform();
            if ($platform === AgentPlatform::SHADOWSNAP()) {
                $backupEngine = $this->getShadowSnapWindowsAgentBackupEngine();
            } elseif ($platform === AgentPlatform::DATTO_WINDOWS_AGENT()) {
                $backupEngine = $this->getDattoWindowsAgentBackupEngine();
            }
        }

        return $backupEngine;
    }

    /**
     * @return array
     */
    private function getVssExclusions(): array
    {
        $vssExclusions = [];

        $asset = $this->backupContext->getAsset();
        if ($asset->isType(AssetType::WINDOWS_AGENT)) {
            /** @var WindowsAgent $asset */

            $vssExclusions = $asset->getVssWriterSettings()->getAvailableExcludedIds();
        }

        return $vssExclusions;
    }

    /**
     * @return bool
     */
    private function isRollbackOnFailureEnabled(): bool
    {
        $enableRollback = false;
        $asset = $this->backupContext->getAsset();
        /** @var Agent $agent */
        $agent = $asset;
        $platform = $agent->getPlatform();
        if ($platform === AgentPlatform::SHADOWSNAP()) {
            $enableRollback = $this->deviceConfig->has('doRollbacks');
        }
        return $enableRollback;
    }

    /**
     * @return bool
     */
    private function useCachedWrites(): bool
    {
        $cacheWrites = false;
        $asset = $this->backupContext->getAsset();
        /** @var Agent $agent */
        $agent = $asset;
        $platform = $agent->getPlatform();
        if ($platform === AgentPlatform::SHADOWSNAP()) {
            $cacheWrites = $this->agentConfig->has('cacheWrite');
        }
        return $cacheWrites;
    }

    /**
     * @inheritdoc
     */
    private function getQuiescingScripts(): array
    {
        $quiescingScripts = [];
        $asset = $this->backupContext->getAsset();
        /** @var Agent $agent */
        $agent = $asset;
        $platform = $agent->getPlatform();
        if ($platform === AgentPlatform::DATTO_LINUX_AGENT()) {
            /** @var LinuxAgent $linuxAgent */
            $linuxAgent = $asset;
            $includeVolumeGuids = $this->backupContext->getIncludedVolumeGuids();
            foreach ($includeVolumeGuids as $includeVolumeGuid) {
                $quiescingScripts[$includeVolumeGuid] = $linuxAgent
                    ->getPrePostScripts()
                    ->getApiFormattedScripts($includeVolumeGuid);
            }
        }
        return $quiescingScripts;
    }

    /**
     * @return string
     */
    private function getShadowSnapWindowsAgentBackupEngine(): string
    {
        $firstAttempt = ($this->transferAttempt === 1);
        return $firstAttempt ? self::BACKUP_TYPE_VSS : self::BACKUP_TYPE_STC;
    }

    /**
     * @return string
     */
    private function getDattoWindowsAgentBackupEngine(): string
    {
        $firstAttempt = ($this->transferAttempt === 1);
        $wasSnapshotFailure =
            $this->agentTransferResult === AgentTransferResult::FAILURE_SNAPSHOT() ||
            $this->agentTransferResult === AgentTransferResult::FAILURE_BOTH();
        $forceDbd =
            $this->transferAttempt > self::UNKNOWN_RESULT_VSS_ATTEMPT_LIMIT &&
            $this->agentTransferResult === AgentTransferResult::FAILURE_UNKNOWN();

        $useVss = !$forceDbd && ($firstAttempt || !$wasSnapshotFailure);
        $snapshotMethod = $useVss ? self::BACKUP_TYPE_VSS : self::BACKUP_TYPE_DBD;

        return $snapshotMethod;
    }
}
