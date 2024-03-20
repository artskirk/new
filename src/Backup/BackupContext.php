<?php

namespace Datto\Backup;

use Datto\Alert\AlertManager;
use Datto\Asset\Agent\Api\AgentApi;
use Datto\Asset\Agent\Api\BackupApiContextFactory;
use Datto\Asset\Agent\DattoImage;
use Datto\Asset\Agent\DattoImageFactory;
use Datto\Asset\Agent\VolumeMetadata;
use Datto\Asset\Asset;
use Datto\Asset\AssetService;
use Datto\Asset\RansomwareResults;
use Datto\Backup\File\BackupImageFile;
use Datto\Backup\Transport\BackupTransport;
use Datto\Config\AgentConfig;
use Datto\Config\DeviceConfig;
use Datto\Filesystem\FilesystemCheckResult;
use Datto\Log\CodeCounter;
use Datto\Reporting\Backup\BackupReportContext;
use Datto\Resource\DateTimeService;
use Datto\Log\DeviceLoggerInterface;

/**
 * This class holds the context that is used by the backup stages.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class BackupContext
{
    /** Location of agents storage path */
    const AGENTS_PATH = '/home/agents/';

    /** Format string for the location of the backup job uuid file */
    const BACKUP_UUID_FILE_FORMAT = '/dev/shm/%s.backupJobUuid';

    /** Default value that snapshotTime is set to before the snapshot is taken */
    const SNAPSHOT_TIME_DEFAULT = -1;

    /** Default time we should wait for the zfs snapshot call to come back (seconds) */
    const SNAPSHOT_TIMEOUT_DEFAULT = 3600;

    private Asset $asset;
    private bool $forced;
    private bool $fullBackup;
    private DeviceConfig $deviceConfig;
    private DattoImageFactory $dattoImageFactory;
    private DeviceLoggerInterface $logger;
    private AgentApi $agentApi;
    private BackupReportContext $backupReportContext;
    private BackupLock $backupLock;
    private DateTimeService $dateTimeService;
    private BackupStatusService $backupStatus;
    private AssetService $assetService;
    private AlertManager $alertManager;
    private AgentConfig $agentConfig;
    private CurrentJob $currentJob;
    private int $startTime;
    private int $snapshotTime;
    private int $snapshotTimeout;
    private BackupTransport $backupTransport;
    private bool $hasChecksumFile;
    private int $amountTransferred;
    private string $backupEngineConfigured;
    private string $backupEngineUsed;
    private bool $offsiteQueued;
    private CodeCounter $codeCounter;
    private bool $osUpdatePending;
    private BackupImageFile $backupImageFile;
    private bool $skipVolumeValidation;
    private array $imageLoopsOrFiles;
    private array $checksumFiles;

    /** @var array List of GUIDs for included volumes */
    private array $includedVolumeGuids;

    /** @var array List of all volumes */
    private array $allVolumes;

    /** @var array List of GUIDs for the included full disks */
    private array $includedDiskGuids;

    /** @var array List of all full disks */
    private array $allDisks;

    /** @var string[] */
    private array $volumeBackupTypes;

    /** @var DattoImage[] */
    private array $localVerificationDattoImages;

    private bool $expectRansomwareChecks;
    private ?RansomwareResults $ransomwareResults;

    private bool $expectFilesystemChecks;
    /** @var FilesystemCheckResult[] */
    private array $filesystemCheckResults;

    private bool $expectMissingVolumesChecks;
    /** @var VolumeMetadata[]|null */
    private ?array $missingVolumesResult;

    private bool $failedForcePartitionRewrite;

    public function __construct(
        Asset $asset,
        bool $forced,
        bool $fullBackup,
        DeviceConfig $deviceConfig,
        DattoImageFactory $dattoImageFactory,
        BackupLockFactory $backupLockFactory,
        DeviceLoggerInterface $logger,
        CodeCounter $codeCounter = null,
        DateTimeService $dateTimeService = null,
        BackupStatusService $backupStatus = null,
        AssetService $assetService = null,
        AlertManager $alertManager = null,
        AgentConfig $agentConfig = null,
        CurrentJob $currentJob = null
    ) {
        $this->asset = $asset;
        $this->forced = $forced;
        $this->fullBackup = $fullBackup;
        $this->deviceConfig = $deviceConfig;
        $this->dattoImageFactory = $dattoImageFactory;
        $this->logger = $logger;
        $this->codeCounter = $codeCounter ?? CodeCounter::get($asset->getKeyName());
        $this->backupLock = $backupLockFactory->create($this->asset->getKeyName());
        $this->dateTimeService = $dateTimeService ?: new DateTimeService();
        $this->backupStatus = $backupStatus ?? new BackupStatusService($this->asset->getKeyName(), $this->logger);
        $this->assetService = $assetService ?? new AssetService();
        $this->alertManager = $alertManager ?? new AlertManager();
        $this->agentConfig = $agentConfig ?? new AgentConfig($asset->getKeyName());
        $this->currentJob = $currentJob ?? new CurrentJob($this->agentConfig);

        $this->backupReportContext = new BackupReportContext($this->asset->getKeyName());
        $this->startTime = $this->dateTimeService->getTime();
        $this->snapshotTime = self::SNAPSHOT_TIME_DEFAULT;
        $this->snapshotTimeout = self::SNAPSHOT_TIMEOUT_DEFAULT;
        $this->includedVolumeGuids = [];
        $this->allVolumes = [];
        $this->imageLoopsOrFiles = [];
        $this->checksumFiles = [];
        $this->hasChecksumFile = true;
        $this->amountTransferred = 0;
        $this->backupEngineConfigured = BackupApiContextFactory::BACKUP_TYPE_NONE;
        $this->backupEngineUsed = BackupApiContextFactory::BACKUP_TYPE_NONE;
        $this->volumeBackupTypes = [];
        $this->offsiteQueued = false;
        $this->localVerificationDattoImages = [];
        $this->osUpdatePending = false;
        $this->skipVolumeValidation = false;

        $this->expectRansomwareChecks = false;
        $this->ransomwareResults = null;

        $this->expectFilesystemChecks = false;
        $this->filesystemCheckResults = [];

        $this->expectMissingVolumesChecks = false;
        $this->missingVolumesResult = null;

        $this->failedForcePartitionRewrite = false;
    }

    /**
     * @return Asset
     */
    public function getAsset(): Asset
    {
        return $this->asset;
    }

    /**
     * @return bool
     */
    public function isForced(): bool
    {
        return $this->forced;
    }

    /**
     * @return bool
     */
    public function isScheduled(): bool
    {
        return !$this->forced;
    }

    /**
     * @return bool
     */
    public function isFullBackup(): bool
    {
        return $this->fullBackup;
    }

    /**
     * @return DeviceConfig
     */
    public function getDeviceConfig(): DeviceConfig
    {
        return $this->deviceConfig;
    }

    /**
     * @return DattoImageFactory
     */
    public function getDattoImageFactory(): DattoImageFactory
    {
        return $this->dattoImageFactory;
    }

    /**
     * @return DeviceLoggerInterface
     */
    public function getLogger(): DeviceLoggerInterface
    {
        return $this->logger;
    }

    /**
     * @return AgentApi
     */
    public function getAgentApi(): AgentApi
    {
        return $this->agentApi;
    }

    /**
     * @param AgentApi $agentApi
     */
    public function setAgentApi(AgentApi $agentApi)
    {
        $this->agentApi = $agentApi;
    }

    /**
     * @return BackupLock
     */
    public function getBackupLock(): BackupLock
    {
        return $this->backupLock;
    }

    /**
     * @return DateTimeService
     */
    public function getDateTimeService(): DateTimeService
    {
        return $this->dateTimeService;
    }

    /**
     * @return int
     */
    public function getStartTime(): int
    {
        return $this->startTime;
    }

    /**
     * @param int $startTime
     */
    public function setStartTime(int $startTime)
    {
        $this->startTime = $startTime;
    }

    /**
     * @return int
     */
    public function getSnapshotTime(): int
    {
        return $this->snapshotTime;
    }

    /**
     * @param int $snapshotTime
     */
    public function setSnapshotTime(int $snapshotTime)
    {
        $this->snapshotTime = $snapshotTime;
    }

    /**
     * @param int $snapshotTimeout
     */
    public function setSnapshotTimeout(int $snapshotTimeout)
    {
        $this->snapshotTimeout = $snapshotTimeout;
    }

    /**
     * @return int
     */
    public function getSnapshotTimeout(): int
    {
        return $this->snapshotTimeout;
    }

    /**
     * @return BackupTransport
     */
    public function getBackupTransport(): BackupTransport
    {
        return $this->backupTransport;
    }

    /**
     * @param BackupTransport $backupTransport
     */
    public function setBackupTransport(BackupTransport $backupTransport)
    {
        $this->backupTransport = $backupTransport;
    }

    /**
     * @return array
     */
    public function getIncludedVolumeGuids(): array
    {
        return $this->includedVolumeGuids;
    }

    /**
     * @param string[] $includedVolumeGuids
     */
    public function setIncludedVolumeGuids(array $includedVolumeGuids)
    {
        $this->includedVolumeGuids = $includedVolumeGuids;
    }

    /**
     * @return array
     */
    public function getAllVolumes(): array
    {
        return $this->allVolumes;
    }

    /**
     * @param array $allVolumes
     */
    public function setAllVolumes(array $allVolumes)
    {
        $this->allVolumes = $allVolumes;
    }

    /**
     * @return string[]
     */
    public function getIncludedDiskGuids(): array
    {
        return $this->includedDiskGuids;
    }

    /**
     * @param string[] $includedDiskGuids
     */
    public function setIncludedDiskGuids(array $includedDiskGuids)
    {
        $this->includedDiskGuids = $includedDiskGuids;
    }

    /**
     * @return array
     */
    public function getAllDisks(): array
    {
        return $this->allDisks;
    }

    /**
     * @param array $allDisks
     */
    public function setAllDisks(array $allDisks)
    {
        $this->allDisks = $allDisks;
    }

    /**
     * @return array
     */
    public function getImageLoopsOrFiles(): array
    {
        return $this->imageLoopsOrFiles;
    }

    /**
     * @param array $imageLoopsOrFiles
     */
    public function setImageLoopsOrFiles(array $imageLoopsOrFiles)
    {
        $this->imageLoopsOrFiles = $imageLoopsOrFiles;
    }

    /**
     * @return array
     */
    public function getChecksumFiles(): array
    {
        return $this->checksumFiles;
    }

    /**
     * @param array $checksumFiles
     */
    public function setChecksumFiles(array $checksumFiles)
    {
        $this->checksumFiles = $checksumFiles;
    }

    /**
     * @return bool
     */
    public function hasChecksumFile(): bool
    {
        return $this->hasChecksumFile;
    }

    /**
     * @param bool $hasChecksumFile
     */
    public function setHasChecksumFile(bool $hasChecksumFile)
    {
        $this->hasChecksumFile = $hasChecksumFile;
    }

    /**
     * @return int
     */
    public function getAmountTransferred(): int
    {
        return $this->amountTransferred;
    }

    /**
     * @param int $amountTransferred
     */
    public function setAmountTransferred(int $amountTransferred)
    {
        $this->amountTransferred = $amountTransferred;
    }

    /**
     * @return string
     */
    public function getBackupEngineConfigured(): string
    {
        return $this->backupEngineConfigured;
    }

    /**
     * @param string $backupEngineConfigured
     */
    public function setBackupEngineConfigured(string $backupEngineConfigured)
    {
        $this->backupEngineConfigured = $backupEngineConfigured;
    }

    /**
     * @return string
     */
    public function getBackupEngineUsed(): string
    {
        return $this->backupEngineUsed;
    }

    /**
     * @param string $backupEngineUsed
     */
    public function setBackupEngineUsed(string $backupEngineUsed)
    {
        $this->backupEngineUsed = $backupEngineUsed;
    }

    /**
     * @return FilesystemCheckResult[]
     */
    public function getFilesystemCheckResults(): array
    {
        return $this->filesystemCheckResults;
    }

    /**
     * @param FilesystemCheckResult[] $filesystemCheckResults
     */
    public function setFilesystemCheckResults(array $filesystemCheckResults)
    {
        $this->filesystemCheckResults = $filesystemCheckResults;
    }

    /**
     * @return bool
     */
    public function isExpectRansomwareChecks(): bool
    {
        return $this->expectRansomwareChecks;
    }

    /**
     * @param bool $expectRansomwareChecks
     */
    public function setExpectRansomwareChecks(bool $expectRansomwareChecks): void
    {
        $this->expectRansomwareChecks = $expectRansomwareChecks;
    }

    /**
     * @return RansomwareResults|null
     */
    public function getRansomwareResults(): ?RansomwareResults
    {
        return $this->ransomwareResults;
    }

    /**
     * @param RansomwareResults|null $ransomwareResults
     */
    public function setRansomwareResults(?RansomwareResults $ransomwareResults): void
    {
        $this->ransomwareResults = $ransomwareResults;
    }

    /**
     * @return bool
     */
    public function isExpectFilesystemChecks(): bool
    {
        return $this->expectFilesystemChecks;
    }

    /**
     * @param bool $expectFilesystemChecks
     */
    public function setExpectFilesystemChecks(bool $expectFilesystemChecks): void
    {
        $this->expectFilesystemChecks = $expectFilesystemChecks;
    }

    /**
     * @return bool
     */
    public function isExpectMissingVolumesChecks(): bool
    {
        return $this->expectMissingVolumesChecks;
    }

    /**
     * @param bool $expectMissingVolumesChecks
     */
    public function setExpectMissingVolumesChecks(bool $expectMissingVolumesChecks): void
    {
        $this->expectMissingVolumesChecks = $expectMissingVolumesChecks;
    }

    /**
     * @return VolumeMetadata[]|null
     */
    public function getMissingVolumesResult(): ?array
    {
        return $this->missingVolumesResult;
    }

    /**
     * @param VolumeMetadata[]|null $missingVolumesResult
     */
    public function setMissingVolumesResult(?array $missingVolumesResult): void
    {
        $this->missingVolumesResult = $missingVolumesResult;
    }

    /**
     * @return string[]
     */
    public function getVolumeBackupTypes(): array
    {
        return $this->volumeBackupTypes;
    }

    /**
     * @param string[] $volumeBackupTypes
     */
    public function setVolumeBackupTypes(array $volumeBackupTypes)
    {
        $this->volumeBackupTypes = $volumeBackupTypes;
    }

    /**
     * @return bool
     */
    public function isOffsiteQueued(): bool
    {
        return $this->offsiteQueued;
    }

    /**
     * @param bool $offsiteQueued
     */
    public function setOffsiteQueued(bool $offsiteQueued)
    {
        $this->offsiteQueued = $offsiteQueued;
    }

    /**
     * @return AgentConfig
     */
    public function getAgentConfig(): AgentConfig
    {
        return $this->agentConfig;
    }

    /**
     * @return CurrentJob
     */
    public function getCurrentJob(): CurrentJob
    {
        return $this->currentJob;
    }

    /**
     * Update the current backup status
     *
     * @param string $state
     * @param array $additional
     * @param string|null $backupType
     */
    public function updateBackupStatus(
        string $state,
        array $additional = [],
        string $backupType = null
    ) {
        $this->backupStatus->updateBackupStatus(
            $this->getStartTime(),
            $state,
            $additional,
            $backupType
        );
    }

    /**
     * Get the full path of the backup job uuid file
     *
     * @return string
     */
    public function getBackupJobUuidFile(): string
    {
        return sprintf(self::BACKUP_UUID_FILE_FORMAT, $this->asset->getKeyName());
    }

    public function getInhibitRollback(): bool
    {
        return $this->agentConfig->has('inhibitRollback');
    }

    /**
     * Reload the asset from the configuration files.
     * @return Asset The newly reloaded asset
     */
    public function reloadAsset(): Asset
    {
        $assetKeyName = $this->asset->getKeyName();
        //[BCDR-17398] In a low-memory situation, having 2 copies of asset has been shown to cause PHP Seg Faults
        unset($this->asset);
        $this->asset = $this->assetService->get($assetKeyName);
        return $this->asset;
    }

    /**
     * Clear one alert code on this asset
     *
     * @param string $code Single Internal Message Codes
     */
    public function clearAlert(string $code)
    {
        $this->alertManager->clearAlert($this->asset->getKeyName(), $code);
    }

    /**
     * Clear multiple alert codes on this asset
     *
     * @param string[] $codes List of Internal Message Codes
     */
    public function clearAlerts(array $codes)
    {
        $this->alertManager->clearAlerts($this->asset->getKeyName(), $codes);
    }

    /**
     * @return CodeCounter
     */
    public function getCodeCounter(): CodeCounter
    {
        return $this->codeCounter;
    }

    /**
     * @return DattoImage[]
     */
    public function getLocalVerificationDattoImages(): array
    {
        return $this->localVerificationDattoImages;
    }

    /**
     * @param DattoImage[] $dattoImages
     */
    public function setLocalVerificationDattoImages(array $dattoImages)
    {
        $this->localVerificationDattoImages = $dattoImages;
    }

    /**
     * @return bool
     */
    public function isOsUpdatePending(): bool
    {
        return $this->osUpdatePending;
    }

    /**
     * @param bool $osUpdatePending
     */
    public function setOsUpdatePending(bool $osUpdatePending)
    {
        $this->osUpdatePending = $osUpdatePending;
    }

    /**
     * @return bool
     */
    public function shouldSkipVolumeValidation(): bool
    {
        return $this->skipVolumeValidation;
    }

    /**
     * @param bool $skipVolumeValidation
     */
    public function setSkipVolumeValidation(bool $skipVolumeValidation)
    {
        $this->skipVolumeValidation = $skipVolumeValidation;
    }

    /**
     * @return BackupImageFile
     */
    public function getBackupImageFile(): BackupImageFile
    {
        return $this->backupImageFile;
    }

    /**
     * @param BackupImageFile $backupImageFile
     */
    public function setBackupImageFile(BackupImageFile $backupImageFile)
    {
        $this->backupImageFile = $backupImageFile;
    }

    /**
     * @return BackupReportContext
     */
    public function getBackupReportContext(): BackupReportContext
    {
        return $this->backupReportContext;
    }

    public function isFailedForcePartitionRewrite(): bool
    {
        return $this->failedForcePartitionRewrite;
    }

    public function setFailedForcePartitionRewrite(bool $failedForcePartitionRewrite): void
    {
        $this->failedForcePartitionRewrite = $failedForcePartitionRewrite;
    }
}
