<?php

namespace Datto\Backup;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Agentless\AgentlessSystem;
use Datto\Asset\Agent\Agentless\Api\AgentlessProxyApi;
use Datto\Asset\Agent\Agentless\Linux\LinuxAgent;
use Datto\Asset\Agent\Agentless\Windows\WindowsAgent;
use Datto\Asset\Agent\Api\AgentApi;
use Datto\Asset\Agent\Api\AgentApiFactory;
use Datto\Asset\Agent\Api\DattoAgentApi;
use Datto\Asset\Agent\DattoImageFactory;
use Datto\Asset\Asset;
use Datto\Asset\AssetType;
use Datto\Backup\File\BackupImageFileFactory;
use Datto\Backup\Stages\AgentPreflightChecks;
use Datto\Backup\Stages\BackupLock;
use Datto\Backup\Stages\Cleanup;
use Datto\Backup\Stages\ClearVolumeHeader;
use Datto\Backup\Stages\CommonPreflightChecks;
use Datto\Backup\Stages\CopyAgentConfig;
use Datto\Backup\Stages\IscsiSessionCleanup;
use Datto\Backup\Stages\PostCleanup;
use Datto\Backup\Stages\PrepareAgentVolumes;
use Datto\Backup\Stages\PrepareLocalVerifications;
use Datto\Backup\Stages\QueueVerification;
use Datto\Backup\Stages\RescueAgentPreflightChecks;
use Datto\Backup\Stages\RestoreVolumeHeader;
use Datto\Backup\Stages\RetrieveRegistryServices;
use Datto\Backup\Stages\RollbackSnapshot;
use Datto\Backup\Stages\RunFileIntegrityCheck;
use Datto\Backup\Stages\RunRansomware;
use Datto\Backup\Stages\RunRetention;
use Datto\Backup\Stages\SharePreflightChecks;
use Datto\Backup\Stages\TakeSnapshot;
use Datto\Backup\Stages\TransferAgentData;
use Datto\Backup\Stages\TransferExternalNasData;
use Datto\Backup\Stages\UpdateAgentAllowedCommandsList;
use Datto\Backup\Stages\UpdateAsset;
use Datto\Backup\Stages\LogMissingVolumes;
use Datto\Backup\Stages\UpdateAzureVmMetadata;
use Datto\Backup\Stages\UpdateDeviceWeb;
use Datto\Backup\Stages\UpdateRescueAgentAsset;
use Datto\Backup\Stages\UpdateShareAsset;
use Datto\Backup\Stages\WaitForOpenAgentlessSessions;
use Datto\Config\AgentConfig;
use Datto\Config\DeviceConfig;
use Datto\Connection\Libvirt\EsxConnection;
use Datto\Connection\Service\EsxConnectionService;
use Datto\DirectToCloud\Api\DirectToCloudAgentApi;
use Datto\Feature\FeatureService;
use Datto\Log\DeviceLoggerInterface;
use Datto\Resource\DateTimeService;
use Datto\System\Transaction\NestedTransaction;
use Datto\System\Transaction\Transaction;
use Datto\System\Transaction\TransactionFailureType;
use Exception;

/**
 * Creates backup transactions based on asset type.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class BackupTransactionFactory
{
    private DeviceConfig $deviceConfig;
    private BackupImageFileFactory $imageFileFactory;
    private BackupCancelManager $backupCancelManager;
    private BackupContext $backupContext;
    private BackupLockFactory $backupLockFactory;
    private EsxConnectionService $esxConnectionService;
    private DattoImageFactory $dattoImageFactory;
    private BackupLock $backupLock;
    private Cleanup $cleanup;
    private UpdateAsset $updateAsset;
    private LogMissingVolumes $logMissingVolumes;
    private CopyAgentConfig $copyAgentConfig;
    private PrepareAgentVolumes $prepareAgentVolumes;
    private TakeSnapshot $takeSnapshot;
    private UpdateDeviceWeb $updateDeviceWeb;
    private RunRetention $runRetention;
    private RollbackSnapshot $rollbackSnapshot;
    private TransferAgentData $transferAgentData;
    private AgentPreflightChecks $agentPreflightChecks;
    private PostCleanup $postCleanup;
    private ClearVolumeHeader $clearVolumeHeader;
    private RestoreVolumeHeader $restoreVolumeHeader;
    private PrepareLocalVerifications $prepareLocalVerifications;
    private RunFileIntegrityCheck $runFileIntegrityCheck;
    private RunRansomware $runRansomware;
    private UpdateAgentAllowedCommandsList $updateAgentAllowedCommandsList;
    private IscsiSessionCleanup $iscsiSessionCleanup;
    private QueueVerification $queueVerification;
    private WaitForOpenAgentlessSessions $waitForOpenAgentlessSessions;
    private RetrieveRegistryServices $retrieveRegistryServices;
    private RescueAgentPreflightChecks $rescueAgentPreflightChecks;
    private UpdateRescueAgentAsset $updateRescueAgentAsset;
    private SharePreflightChecks $sharePreflightChecks;
    private UpdateShareAsset $updateShareAsset;
    private AgentApiFactory $agentApiFactory;
    private TransferExternalNasData $transferExternalNasData;
    private UpdateAzureVmMetadata $updateAzureVmMetadata;
    private FeatureService $featureService;
    private CommonPreflightChecks $commonPreflightChecks;
    private DateTimeService $dateTimeService;

    public function __construct(
        DeviceConfig $deviceConfig,
        BackupImageFileFactory $imageFileFactory,
        BackupCancelManager $backupCancelManager,
        EsxConnectionService $esxConnectionService,
        DattoImageFactory $dattoImageFactory,
        BackupLockFactory $backupLockFactory,
        BackupLock $backupLock,
        Cleanup $cleanup,
        UpdateAsset $updateAsset,
        LogMissingVolumes $logMissingVolumes,
        CopyAgentConfig $copyAgentConfig,
        PrepareAgentVolumes $prepareAgentVolumes,
        TakeSnapshot $takeSnapshot,
        UpdateDeviceWeb $updateDeviceWeb,
        RunRetention $runRetention,
        RollbackSnapshot $rollbackSnapshot,
        TransferAgentData $transferAgentData,
        AgentPreflightChecks $agentPreflightChecks,
        PostCleanup $postCleanup,
        ClearVolumeHeader $clearVolumeHeader,
        RestoreVolumeHeader $restoreVolumeHeader,
        PrepareLocalVerifications $prepareLocalVerifications,
        RunFileIntegrityCheck $runFileIntegrityCheck,
        RunRansomware $runRansomware,
        UpdateAgentAllowedCommandsList $updateAgentAllowedCommandsList,
        IscsiSessionCleanup $iscsiSessionCleanup,
        QueueVerification $queueVerification,
        WaitForOpenAgentlessSessions $waitForOpenAgentlessSessions,
        RetrieveRegistryServices $retrieveRegistryServices,
        RescueAgentPreflightChecks $rescueAgentPreflightChecks,
        UpdateRescueAgentAsset $updateRescueAgentAsset,
        SharePreflightChecks $sharePreflightChecks,
        UpdateShareAsset $updateShareAsset,
        AgentApiFactory $agentApiFactory,
        TransferExternalNasData $transferExternalNasData,
        UpdateAzureVmMetadata $updateAzureVmMetadata,
        FeatureService $featureService,
        CommonPreflightChecks $commonPreflightChecks,
        DateTimeService $dateTimeService
    ) {
        $this->deviceConfig = $deviceConfig;
        $this->imageFileFactory = $imageFileFactory;
        $this->backupCancelManager = $backupCancelManager;
        $this->esxConnectionService = $esxConnectionService;
        $this->dattoImageFactory = $dattoImageFactory;
        $this->backupLockFactory = $backupLockFactory;
        $this->backupLock = $backupLock;
        $this->cleanup = $cleanup;
        $this->updateAsset = $updateAsset;
        $this->logMissingVolumes = $logMissingVolumes;
        $this->copyAgentConfig = $copyAgentConfig;
        $this->prepareAgentVolumes = $prepareAgentVolumes;
        $this->takeSnapshot = $takeSnapshot;
        $this->updateDeviceWeb = $updateDeviceWeb;
        $this->runRetention = $runRetention;
        $this->rollbackSnapshot = $rollbackSnapshot;
        $this->transferAgentData = $transferAgentData;
        $this->agentPreflightChecks = $agentPreflightChecks;
        $this->postCleanup = $postCleanup;
        $this->clearVolumeHeader = $clearVolumeHeader;
        $this->restoreVolumeHeader = $restoreVolumeHeader;
        $this->prepareLocalVerifications = $prepareLocalVerifications;
        $this->runFileIntegrityCheck = $runFileIntegrityCheck;
        $this->runRansomware = $runRansomware;
        $this->updateAgentAllowedCommandsList = $updateAgentAllowedCommandsList;
        $this->iscsiSessionCleanup = $iscsiSessionCleanup;
        $this->queueVerification = $queueVerification;
        $this->waitForOpenAgentlessSessions = $waitForOpenAgentlessSessions;
        $this->retrieveRegistryServices = $retrieveRegistryServices;
        $this->rescueAgentPreflightChecks = $rescueAgentPreflightChecks;
        $this->updateRescueAgentAsset = $updateRescueAgentAsset;
        $this->sharePreflightChecks = $sharePreflightChecks;
        $this->updateShareAsset = $updateShareAsset;
        $this->agentApiFactory = $agentApiFactory;
        $this->transferExternalNasData = $transferExternalNasData;
        $this->updateAzureVmMetadata = $updateAzureVmMetadata;
        $this->featureService = $featureService;
        $this->commonPreflightChecks = $commonPreflightChecks;
        $this->dateTimeService = $dateTimeService;
    }

    /**
     * Create the backup transaction
     */
    public function create(
        Asset $asset,
        DeviceLoggerInterface $logger,
        bool $wasForced,
        array $metadata
    ): Transaction {
        $this->createBackupContext($asset, $wasForced, $logger, $metadata);

        /** @var Agent $asset */
        if ($asset->isType(AssetType::AGENT) && $asset->isRescueAgent()) {
            $transaction = $this->createRescueAgentTransaction($logger);
        } elseif ($asset->isType(AssetType::AGENT) && $asset->isDirectToCloudAgent()) {
            $transaction = $this->createDirectToCloudAgentTransaction($asset, $logger, $metadata);
        } elseif ($asset->isType(AssetType::AGENTLESS_GENERIC) && $asset->isFullDiskBackup()) {
            /** @var AgentlessSystem $asset */
            $transaction = $this->createGenericAgentlessTransaction($asset, $logger);
        } elseif ($asset->isType(AssetType::WINDOWS_AGENT)) {
            $transaction = $this->createWindowsAgentTransaction($asset, $logger);
        } elseif ($asset->isType(AssetType::LINUX_AGENT)) {
            $transaction = $this->createLinuxAgentTransaction($asset, $logger);
        } elseif ($asset->isType(AssetType::MAC_AGENT)) {
            $transaction = $this->createMacAgentTransaction($asset, $logger);
        } elseif ($asset->isType(AssetType::AGENTLESS_WINDOWS)) {
            /** @var WindowsAgent $asset */
            $transaction = $this->createAgentlessWindowsTransaction($asset, $logger);
        } elseif ($asset->isType(AssetType::AGENTLESS_LINUX)) {
            /** @var LinuxAgent $asset */
            $transaction = $this->createAgentlessLinuxTransaction($asset, $logger);
        } elseif ($asset->isType(AssetType::EXTERNAL_NAS_SHARE)) {
            $transaction = $this->createExternalNasShareTransaction($logger);
        } elseif ($asset->isType(AssetType::SHARE)) {
            $transaction = $this->createShareTransaction($logger);
        } else {
            throw new BackupException('Asset type not supported!');
        }

        $transaction->setOnCancelCallback(
            function () use ($asset) {
                return $this->backupCancelManager->isCancelling($asset);
            },
            function () use ($asset) {
                $this->backupCancelManager->cleanup($asset);
            }
        );
        return $transaction;
    }

    /**
     * Create a prepare backup transaction.
     *
     * Prepares a backup by writing out the volume table file to the live dataset,
     * and ensures that volume and checksum sparse files exist.
     *
     */
    public function createPrepare(
        Asset $asset,
        DeviceLoggerInterface $logger,
        bool $wasForced,
        array $metadata
    ): Transaction {

        $this->createBackupContext($asset, $wasForced, $logger, $metadata);

        if (empty($metadata['hostInfo'])) {
            throw new \InvalidArgumentException("Backup metadata is missing 'hostInfo' field");
        }

        $this->backupContext->setBackupImageFile($this->imageFileFactory->createWindows());
        $this->backupContext->setAgentApi($this->createDirectToCloudAgentApi($metadata['hostInfo']));

        /** @var Agent $asset */
        if ($asset->isType(AssetType::AGENT) && $asset->isDirectToCloudAgent()) {
            $transaction = $this->createDirectToCloudAgentPrepareTransaction($logger, $metadata);
        } else {
            throw new BackupException('Asset type not supported!');
        }

        $transaction->setOnCancelCallback(
            function () use ($asset) {
                return $this->backupCancelManager->isCancelling($asset);
            },
            function () use ($asset) {
                $this->backupCancelManager->cleanup($asset);
            }
        );

        return $transaction;
    }

    /**
     * Get the backup context
     */
    public function getContext(): BackupContext
    {
        return $this->backupContext;
    }

    /**
     * Create the backup context
     */
    private function createBackupContext(
        Asset $asset,
        bool $wasForced,
        DeviceLoggerInterface $logger,
        array $metadata
    ): void {
        $agentConfig = new AgentConfig($asset->getKeyName());
        $doFullBackup = $agentConfig->has('forceFull');
        $this->backupContext = new BackupContext(
            $asset,
            $wasForced,
            $doFullBackup,
            $this->deviceConfig,
            $this->dattoImageFactory,
            $this->backupLockFactory,
            $logger
        );

        // Adjusting the start time for Azure and CC4PC
        if (isset($metadata['elapsedTime'])) {
            $this->backupContext->setStartTime($this->dateTimeService->getTime() - $metadata['elapsedTime']);
        }
    }

    private function createDirectToCloudAgentTransaction(
        Agent $agent,
        DeviceLoggerInterface $logger,
        array $metadata
    ): Transaction {
        if (empty($metadata['hostInfo'])) {
            throw new \InvalidArgumentException("Backup metadata is missing 'hostInfo' field");
        }

        if (empty($metadata['backupInfo'])) {
            throw new \InvalidArgumentException("Backup metadata is missing 'backupInfo' field");
        }

        $backupStatus = DattoAgentApi::getBackupStatusFromResponse($metadata['backupInfo']);
        $this->backupContext->setAmountTransferred($backupStatus->getTotal());
        $this->backupContext->setVolumeBackupTypes($backupStatus->getVolumeBackupTypes());
        $this->backupContext->setIncludedVolumeGuids($backupStatus->getVolumeGuids());

        $this->backupContext->setBackupImageFile($this->imageFileFactory->createWindows());
        $this->backupContext->setAgentApi($this->createDirectToCloudAgentApi($metadata['hostInfo']));
        $this->backupContext->setSkipVolumeValidation(true);

        if (isset($metadata['snapshotTimeout'])) {
            $this->backupContext->setSnapshotTimeout($metadata['snapshotTimeout']);
        }

        $localVerificationTransaction = new NestedTransaction(
            TransactionFailureType::STOP_ON_FAILURE(),
            $logger,
            $this->backupContext
        );
        $localVerificationTransaction
            ->add($this->prepareLocalVerifications)
            ->add($this->runFileIntegrityCheck)
            ->add($this->runRansomware);

        $retentionFromBackupTransactionSupported = $this->featureService->isSupported(
            FeatureService::FEATURE_DIRECT_TO_CLOUD_AGENTS_RETENTION_DURING_BACKUP
        );

        $publicCloudMetaDataSupported = $this->featureService->isSupported(
            FeatureService::FEATURE_PUBLIC_CLOUD_METADATA_RETRIEVAL
        );

        $backupTransaction = new Transaction(TransactionFailureType::STOP_ON_FAILURE(), $logger, $this->backupContext);
        $backupTransaction
            ->add($this->backupLock)
            ->add($this->cleanup)
            ->add($this->commonPreflightChecks)
            ->add($this->agentPreflightChecks)
            ->add($this->updateAsset)
            ->add($this->copyAgentConfig)
            ->add($this->prepareAgentVolumes)
            ->addIf($publicCloudMetaDataSupported, $this->updateAzureVmMetadata)
            ->add($this->takeSnapshot)
            ->add($this->updateAsset)
            ->add($this->updateDeviceWeb)
            ->addIf($this->backupContext->getDeviceConfig()->isAzureDevice(), $localVerificationTransaction)
            ->addIf($retentionFromBackupTransactionSupported, $this->runRetention);

        return $backupTransaction;
    }

    private function createDirectToCloudAgentPrepareTransaction(
        DeviceLoggerInterface $logger,
        array $metadata
    ): Transaction {
        if (empty($metadata['hostInfo'])) {
            throw new \InvalidArgumentException("Backup metadata is missing 'hostInfo' field");
        }

        if (empty($metadata['backupInfo'])) {
            throw new \InvalidArgumentException("Backup metadata is missing 'backupInfo' field");
        }

        $rollback = $metadata['rollback'] ?? false;

        $backupStatus = DattoAgentApi::getBackupStatusFromResponse($metadata['backupInfo']);
        $this->backupContext->setAmountTransferred($backupStatus->getTotal());
        $this->backupContext->setVolumeBackupTypes($backupStatus->getVolumeBackupTypes());

        $this->backupContext->setBackupImageFile($this->imageFileFactory->createWindows());
        $this->backupContext->setAgentApi($this->createDirectToCloudAgentApi($metadata['hostInfo']));

        $publicCloudMetaDataSupported = $this->featureService->isSupported(
            FeatureService::FEATURE_PUBLIC_CLOUD_METADATA_RETRIEVAL
        );

        $backupTransaction = new Transaction(
            TransactionFailureType::STOP_ON_FAILURE(),
            $logger,
            $this->backupContext
        );
        $backupTransaction
            ->add($this->backupLock)
            ->addIf($rollback, $this->rollbackSnapshot)
            ->add($this->cleanup)
            ->add($this->updateAsset)
            ->add($this->logMissingVolumes)
            ->add($this->commonPreflightChecks)
            ->add($this->agentPreflightChecks)
            ->add($this->prepareAgentVolumes)
            ->addIf($publicCloudMetaDataSupported, $this->updateAzureVmMetadata);

        return $backupTransaction;
    }

    private function createGenericAgentlessTransaction(
        AgentlessSystem $agent,
        DeviceLoggerInterface $logger
    ): Transaction {
        $this->backupContext->setHasChecksumFile(true);
        $this->backupContext->setAgentApi($this->createAgentlessProxyApi($agent, $logger));
        $this->backupContext->setBackupImageFile($this->imageFileFactory->createFullDisk());

        $transferDataTransaction = new NestedTransaction(
            TransactionFailureType::STOP_ON_FAILURE(),
            $logger,
            $this->backupContext
        );
        $transferDataTransaction
            ->add($this->prepareAgentVolumes)
            ->add($this->transferAgentData);

        $backupTransaction = new Transaction(TransactionFailureType::STOP_ON_FAILURE(), $logger, $this->backupContext);
        $backupTransaction
            ->add($this->backupLock)
            ->add($this->rollbackSnapshot)
            ->add($this->cleanup)
            ->add($this->commonPreflightChecks)
            ->add($this->agentPreflightChecks)
            ->add($this->waitForOpenAgentlessSessions)
            ->add($this->updateAsset)
            ->add($this->logMissingVolumes)
            ->add($this->copyAgentConfig)
            ->add($transferDataTransaction)
            ->add($this->takeSnapshot)
            ->add($this->updateAsset)
            ->add($this->postCleanup)
            ->add($this->updateDeviceWeb)
            ->add($this->runRetention);

        return $backupTransaction;
    }

    /**
     * Create a backup transaction for a Windows agent
     */
    private function createWindowsAgentTransaction(
        Agent $agent,
        DeviceLoggerInterface $logger
    ): Transaction {
        $agentConfig = new AgentConfig($agent->getKeyName());
        if ($agentConfig->has('shadowSnap')) {
            $backupTransaction = $this->createWindowsShadowSnapAgentTransaction($agent, $logger);
        } else {
            $backupTransaction = $this->createWindowsDattoAgentTransaction($agent, $logger);
        }
        return $backupTransaction;
    }

    /**
     * Create a backup transaction for a ShadowSnap Windows agent
     */
    private function createWindowsShadowSnapAgentTransaction(
        Agent $agent,
        DeviceLoggerInterface $logger
    ): Transaction {
        $this->backupContext->setAgentApi($this->agentApiFactory->createFromAgent($agent));
        $this->backupContext->setBackupImageFile($this->imageFileFactory->createWindows());

        $isEncrypted = $agent->getEncryption()->isEnabled();

        $transferDataTransaction = new NestedTransaction(
            TransactionFailureType::STOP_ON_FAILURE(),
            $logger,
            $this->backupContext
        );
        $transferDataTransaction
            ->add($this->prepareAgentVolumes)
            ->addIf($isEncrypted, $this->clearVolumeHeader)
            ->add($this->transferAgentData)
            ->addIf($isEncrypted, $this->restoreVolumeHeader);

        $localVerificationTransaction = new NestedTransaction(
            TransactionFailureType::STOP_ON_FAILURE(),
            $logger,
            $this->backupContext
        );
        $localVerificationTransaction
            ->add($this->prepareLocalVerifications)
            ->add($this->runFileIntegrityCheck)
            ->add($this->runRansomware);

        $backupTransaction = new Transaction(TransactionFailureType::STOP_ON_FAILURE(), $logger, $this->backupContext);
        $backupTransaction
            ->add($this->backupLock)
            ->add($this->rollbackSnapshot)
            ->add($this->cleanup)
            ->add($this->commonPreflightChecks)
            ->add($this->agentPreflightChecks)
            ->add($this->updateAgentAllowedCommandsList)
            ->addIf($isEncrypted, $this->iscsiSessionCleanup)
            ->add($this->updateAsset)
            ->add($this->logMissingVolumes)
            ->add($this->copyAgentConfig)
            ->add($transferDataTransaction)
            ->add($this->takeSnapshot)
            ->add($this->updateAsset)
            ->add($this->postCleanup)
            ->add($this->queueVerification)
            ->add($this->updateDeviceWeb)
            ->add($localVerificationTransaction)
            ->add($this->runRetention);

        $this->backupContext->setHasChecksumFile(false);

        return $backupTransaction;
    }

    /**
     * Create a backup transaction for a Datto Windows agent
     */
    private function createWindowsDattoAgentTransaction(
        Agent $agent,
        DeviceLoggerInterface $logger
    ): Transaction {
        $this->backupContext->setAgentApi($this->agentApiFactory->createFromAgent($agent));
        $this->backupContext->setBackupImageFile($this->imageFileFactory->createWindows());

        $transferDataTransaction = new NestedTransaction(
            TransactionFailureType::STOP_ON_FAILURE(),
            $logger,
            $this->backupContext
        );
        $transferDataTransaction
            ->add($this->prepareAgentVolumes)
            ->add($this->transferAgentData);

        $localVerificationTransaction = new NestedTransaction(
            TransactionFailureType::STOP_ON_FAILURE(),
            $logger,
            $this->backupContext
        );
        $localVerificationTransaction
            ->add($this->prepareLocalVerifications)
            ->add($this->runFileIntegrityCheck)
            ->add($this->runRansomware);

        $backupTransaction = new Transaction(TransactionFailureType::STOP_ON_FAILURE(), $logger, $this->backupContext);
        $backupTransaction
            ->add($this->backupLock)
            ->addIf(!$this->backupContext->getInhibitRollback(), $this->rollbackSnapshot)
            ->add($this->cleanup)
            ->add($this->commonPreflightChecks)
            ->add($this->agentPreflightChecks)
            ->add($this->updateAsset)
            ->add($this->logMissingVolumes)
            ->add($this->copyAgentConfig)
            ->add($transferDataTransaction)
            ->add($this->takeSnapshot)
            ->add($this->updateAsset)
            ->add($this->postCleanup)
            ->add($this->queueVerification)
            ->add($this->updateDeviceWeb)
            ->add($localVerificationTransaction)
            ->add($this->runRetention);

        return $backupTransaction;
    }

    /**
     * Create a backup transaction for an Agentless Windows System
     */
    private function createAgentlessWindowsTransaction(
        WindowsAgent $agent,
        DeviceLoggerInterface $logger
    ): Transaction {
        $this->backupContext->setHasChecksumFile(true);
        $this->backupContext->setAgentApi($this->createAgentlessProxyApi($agent, $logger));
        $this->backupContext->setBackupImageFile($this->imageFileFactory->createWindows());

        $transferDataTransaction = new NestedTransaction(
            TransactionFailureType::STOP_ON_FAILURE(),
            $logger,
            $this->backupContext
        );
        $transferDataTransaction
            ->add($this->prepareAgentVolumes)
            ->add($this->transferAgentData);

        $localVerificationTransaction = new NestedTransaction(
            TransactionFailureType::STOP_ON_FAILURE(),
            $logger,
            $this->backupContext
        );
        $localVerificationTransaction
            ->add($this->prepareLocalVerifications)
            ->add($this->runFileIntegrityCheck)
            ->add($this->runRansomware);

        $backupTransaction = new Transaction(TransactionFailureType::STOP_ON_FAILURE(), $logger, $this->backupContext);
        $backupTransaction
            ->add($this->backupLock)
            ->add($this->rollbackSnapshot)
            ->add($this->cleanup)
            ->add($this->commonPreflightChecks)
            ->add($this->agentPreflightChecks)
            ->add($this->waitForOpenAgentlessSessions)
            ->add($this->updateAsset)
            ->add($this->logMissingVolumes)
            ->add($this->copyAgentConfig)
            ->add($transferDataTransaction)
            ->add($this->takeSnapshot)
            ->add($this->updateAsset)
            ->add($this->postCleanup)
            ->add($this->queueVerification)
            ->add($this->updateDeviceWeb)
            ->add($this->retrieveRegistryServices)
            ->add($localVerificationTransaction)
            ->add($this->runRetention);

        return $backupTransaction;
    }

    /**
     * Create a backup transaction for an Agentless Linux System
     */
    private function createAgentlessLinuxTransaction(
        LinuxAgent $agent,
        DeviceLoggerInterface $logger
    ): Transaction {
        $this->backupContext->setHasChecksumFile(true);
        $this->backupContext->setAgentApi($this->createAgentlessProxyApi($agent, $logger));
        $this->backupContext->setBackupImageFile($this->imageFileFactory->createAgentlessLinux());

        $transferDataTransaction = new NestedTransaction(
            TransactionFailureType::STOP_ON_FAILURE(),
            $logger,
            $this->backupContext
        );
        $transferDataTransaction
            ->add($this->prepareAgentVolumes)
            ->add($this->transferAgentData);

        $localVerificationTransaction = new NestedTransaction(
            TransactionFailureType::STOP_ON_FAILURE(),
            $logger,
            $this->backupContext
        );
        $localVerificationTransaction
            ->add($this->prepareLocalVerifications)
            ->add($this->runFileIntegrityCheck);

        $backupTransaction = new Transaction(TransactionFailureType::STOP_ON_FAILURE(), $logger, $this->backupContext);
        $backupTransaction
            ->add($this->backupLock)
            ->add($this->rollbackSnapshot)
            ->add($this->cleanup)
            ->add($this->commonPreflightChecks)
            ->add($this->agentPreflightChecks)
            ->add($this->waitForOpenAgentlessSessions)
            ->add($this->updateAsset)
            ->add($this->logMissingVolumes)
            ->add($this->copyAgentConfig)
            ->add($transferDataTransaction)
            ->add($this->takeSnapshot)
            ->add($this->updateAsset)
            ->add($this->postCleanup)
            ->add($this->queueVerification)
            ->add($this->updateDeviceWeb)
            ->add($localVerificationTransaction)
            ->add($this->runRetention);

        return $backupTransaction;
    }

    /**
     * Create a backup transaction for a Linux agent
     */
    private function createLinuxAgentTransaction(
        Agent $agent,
        DeviceLoggerInterface $logger
    ): Transaction {
        $this->backupContext->setAgentApi($this->agentApiFactory->createFromAgent($agent));
        $this->backupContext->setBackupImageFile($this->imageFileFactory->createLinux());

        $transferDataTransaction = new NestedTransaction(
            TransactionFailureType::STOP_ON_FAILURE(),
            $logger,
            $this->backupContext
        );
        $transferDataTransaction
            ->add($this->prepareAgentVolumes)
            ->add($this->transferAgentData);

        $localVerificationTransaction = new NestedTransaction(
            TransactionFailureType::STOP_ON_FAILURE(),
            $logger,
            $this->backupContext
        );
        $localVerificationTransaction
            ->add($this->prepareLocalVerifications)
            ->add($this->runFileIntegrityCheck);

        $backupTransaction = new Transaction(TransactionFailureType::STOP_ON_FAILURE(), $logger, $this->backupContext);
        $backupTransaction
            ->add($this->backupLock)
            ->add($this->rollbackSnapshot)
            ->add($this->cleanup)
            ->add($this->commonPreflightChecks)
            ->add($this->agentPreflightChecks)
            ->add($this->updateAsset)
            ->add($this->logMissingVolumes)
            ->add($this->copyAgentConfig)
            ->add($transferDataTransaction)
            ->add($this->takeSnapshot)
            ->add($this->updateAsset)
            ->add($this->postCleanup)
            ->add($this->queueVerification)
            ->add($this->updateDeviceWeb)
            ->add($localVerificationTransaction)
            ->add($this->runRetention);

        return $backupTransaction;
    }

    /**
     * Create a backup transaction for a Mac agent
     */
    private function createMacAgentTransaction(
        Agent $agent,
        DeviceLoggerInterface $logger
    ): Transaction {
        $this->backupContext->setAgentApi($this->agentApiFactory->createFromAgent($agent));
        $this->backupContext->setBackupImageFile($this->imageFileFactory->createMac());

        $transferDataTransaction = new NestedTransaction(
            TransactionFailureType::STOP_ON_FAILURE(),
            $logger,
            $this->backupContext
        );
        $transferDataTransaction
            ->add($this->prepareAgentVolumes)
            ->add($this->transferAgentData);

        $localVerificationTransaction = new NestedTransaction(
            TransactionFailureType::STOP_ON_FAILURE(),
            $logger,
            $this->backupContext
        );
        $localVerificationTransaction
            ->add($this->prepareLocalVerifications)
            ->add($this->runFileIntegrityCheck);

        $backupTransaction = new Transaction(TransactionFailureType::STOP_ON_FAILURE(), $logger, $this->backupContext);
        $backupTransaction
            ->add($this->backupLock)
            ->add($this->rollbackSnapshot)
            ->add($this->cleanup)
            ->add($this->commonPreflightChecks)
            ->add($this->agentPreflightChecks)
            ->add($this->updateAsset)
            ->add($this->logMissingVolumes)
            ->add($this->copyAgentConfig)
            ->add($transferDataTransaction)
            ->add($this->takeSnapshot)
            ->add($this->updateAsset)
            ->add($this->postCleanup)
            ->add($this->updateDeviceWeb)
            ->add($localVerificationTransaction)
            ->add($this->runRetention);

        return $backupTransaction;
    }

    private function createRescueAgentTransaction(DeviceLoggerInterface $logger): Transaction
    {
        $backupTransaction = new Transaction(TransactionFailureType::STOP_ON_FAILURE(), $logger, $this->backupContext);
        $backupTransaction
            ->add($this->backupLock)
            ->add($this->rescueAgentPreflightChecks)
            ->add($this->takeSnapshot)
            ->add($this->updateRescueAgentAsset)
            ->add($this->runRetention);

        return $backupTransaction;
    }

    private function createShareTransaction(DeviceLoggerInterface $logger): Transaction
    {
        $backupTransaction = new Transaction(TransactionFailureType::STOP_ON_FAILURE(), $logger, $this->backupContext);
        $backupTransaction
            ->add($this->backupLock)
            ->add($this->commonPreflightChecks)
            ->add($this->sharePreflightChecks)
            ->add($this->takeSnapshot)
            ->add($this->updateShareAsset)
            ->add($this->updateDeviceWeb)
            ->add($this->runRetention);

        return $backupTransaction;
    }

    private function createExternalNasShareTransaction(DeviceLoggerInterface $logger): Transaction
    {
        $backupTransaction = new Transaction(TransactionFailureType::STOP_ON_FAILURE(), $logger, $this->backupContext);
        $backupTransaction
            ->add($this->backupLock)
            ->add($this->commonPreflightChecks)
            ->add($this->sharePreflightChecks)
            ->add($this->transferExternalNasData)
            ->add($this->takeSnapshot)
            ->add($this->updateShareAsset)
            ->add($this->updateDeviceWeb)
            ->add($this->runRetention);

        return $backupTransaction;
    }

    private function createAgentlessProxyApi(AgentlessSystem $agent, DeviceLoggerInterface $logger): AgentApi
    {
        $esxInfo = $agent->getEsxInfo();
        $moRef = $esxInfo->getMoRef();
        $connectionName = $esxInfo->getConnectionName();

        $logger->info("BTF0001 Creating AgentlessProxyApi for VM", [
            'moRef' => $moRef,
            'connectionName' => $connectionName
        ]);

        $esxConnection = $this->esxConnectionService->get($connectionName);
        if (!$esxConnection || !($esxConnection instanceof EsxConnection)) {
            $msg = "ESX connection with name '$connectionName' not found.";
            $logger->error("BTF0002 ESX connection not found", [
                'connectionName' => $connectionName
            ]);
            throw new Exception($msg);
        }

        $this->backupContext->clearAlert("BTF0002");

        $backupApi = new AgentlessProxyApi(
            $moRef,
            $esxConnection,
            $agent->getPlatform(),
            $agent->getKeyName(),
            $logger,
            false,
            $agent->isFullDiskBackup()
        );
        return $backupApi;
    }

    private function createDirectToCloudAgentApi(array $hostInfo): DirectToCloudAgentApi
    {
        $backupApi = new DirectToCloudAgentApi($hostInfo);
        return $backupApi;
    }
}
