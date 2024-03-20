<?php

namespace Datto\Asset\Agent\Rescue;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentRepository;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Encryption\TempAccessService;
use Datto\Asset\Agent\Rescue\Stages\BackupRescueAgentStage;
use Datto\Asset\Agent\Rescue\Stages\CloneSourceAgentDataset;
use Datto\Asset\Agent\Rescue\Stages\CloudUpdateStage;
use Datto\Asset\Agent\Rescue\Stages\CreateAssetStage;
use Datto\Asset\Agent\Rescue\Stages\HideFilesStage;
use Datto\Asset\Agent\Rescue\Stages\PauseAgentStage;
use Datto\Asset\Agent\Rescue\Stages\SpeedSyncSetup;
use Datto\Asset\Agent\Rescue\Stages\StartVirtualMachine;
use Datto\Asset\RecoveryPoint\RecoveryPoint;
use Datto\Asset\RecoveryPoint\RecoveryPointHistoryRecord;
use Datto\Asset\UuidGenerator;
use Datto\Cloud\AgentVolumeService;
use Datto\Cloud\SpeedSync;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\AgentConfigFactory;
use Datto\Connection\Service\ConnectionService;
use Datto\Dataset\DatasetFactory;
use Datto\Feature\FeatureService;
use Datto\Log\LoggerAwareTrait;
use Datto\Reporting\Backup\BackupReport;
use Datto\Reporting\Backup\BackupReportContext;
use Datto\Reporting\Backup\BackupReportManager;
use Datto\Resource\DateTimeService;
use Datto\Restore\AssetCloneManager;
use Datto\Restore\CloneSpec;
use Datto\Restore\FileExclusionService;
use Datto\Restore\Restore;
use Datto\Restore\RestoreService;
use Datto\Restore\RestoreType;
use Datto\Restore\Virtualization\VirtualizationRestoreTool;
use Datto\Restore\Virtualization\VirtualMachineService;
use Datto\System\Transaction\TransactionException;
use Datto\Common\Utility\Filesystem;
use Datto\Utility\File\LockFactory;
use Datto\Utility\Security\SecretString;
use Datto\Virtualization\VirtualMachine;
use Datto\ZFS\ZfsService;
use Exception;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Service class to facilitate the creation of rescue agents.
 *
 * @author Christopher R. Wicks <cwicks@datto.com>
 */
class RescueAgentService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const LOCK_FILE_FORMAT = '/dev/shm/rescue-%s.lock';
    const NAME_TEMPLATE = 'Rescue-%s-%d';

    private AgentService $agentService;
    private SpeedSync $speedSync;
    private AssetCloneManager $cloneManager;
    private FeatureService $featureService;
    private Filesystem $fileSystem;
    private AgentRepository $agentRepository;
    private ZfsService $zfsService;
    private RestoreService $restoreService;
    private VirtualMachineService $virtualMachineService;
    private VirtualizationRestoreTool $virtRestoreTool;
    private ConnectionService $connectionService;
    private AgentVolumeService $agentVolumeService;
    private UuidGenerator $uuidGenerator;
    private FileExclusionService $fileExclusionService;
    private ProcessFactory $processFactory;
    private AgentConfigFactory $agentConfigFactory;
    private LockFactory $lockFactory;
    private DateTimeService $dateTimeService;
    private DatasetFactory $datasetFactory;
    private TempAccessService $tempAccessService;
    private BackupReportManager $backupReportManager;

    public function __construct(
        AgentService $agentService,
        VirtualMachineService $virtualMachineService,
        SpeedSync $speedSync,
        AssetCloneManager $cloneManager,
        FeatureService $featureService,
        Filesystem $fileSystem,
        RestoreService $restoreService,
        AgentRepository $agentRepository,
        VirtualizationRestoreTool $virtRestoreTool,
        ZfsService $zfsService,
        ConnectionService $connectionService,
        AgentVolumeService $agentVolumeService,
        UuidGenerator $uuidGenerator,
        FileExclusionService $fileExclusionService,
        ProcessFactory $processFactory,
        AgentConfigFactory $agentConfigFactory,
        LockFactory $lockFactory,
        DateTimeService $dateTimeService,
        DatasetFactory $datasetFactory,
        TempAccessService $tempAccessService,
        BackupReportManager $backupReportManager
    ) {
        $this->agentService = $agentService;
        $this->virtualMachineService = $virtualMachineService;
        $this->speedSync = $speedSync;
        $this->cloneManager = $cloneManager;
        $this->featureService = $featureService;
        $this->fileSystem = $fileSystem;
        $this->dateTimeService = $dateTimeService;
        $this->restoreService = $restoreService;
        $this->agentRepository = $agentRepository;
        $this->virtRestoreTool = $virtRestoreTool;
        $this->zfsService = $zfsService;
        $this->connectionService = $connectionService;
        $this->agentVolumeService= $agentVolumeService;
        $this->uuidGenerator = $uuidGenerator;
        $this->fileExclusionService = $fileExclusionService;
        $this->processFactory = $processFactory;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->lockFactory = $lockFactory;
        $this->datasetFactory = $datasetFactory;
        $this->tempAccessService = $tempAccessService;
        $this->backupReportManager = $backupReportManager;
    }

    /**
     * Create a rescue agent for the given agent, using the snapshot at the given epoch time.
     *
     * @param string $agentKeyName The key name of the agent for which to create a rescue agent
     * @param int $snapshotEpoch The epoch time of the snapshot to initialize the rescue agent
     * @param bool $pauseSourceAgent Whether to pause the source agent on create
     * @param string $connectionName The hypervisor connection name if remote or 'Local KVM' for a local virtualization.
     * @param bool $hasNativeConfiguration
     * @param SecretString|null $encryptionPassphrase The encryption passphrase for the agent, if any
     * @param CloneSourceAgentDataset|null $cloneStage
     * @param CreateAssetStage|null $agentStage
     * @param BackupRescueAgentStage|null $backupStage
     * @param StartVirtualMachine|null $virtualMachineStage
     * @param PauseAgentStage|null $pauseStage
     * @param CloudUpdateStage|null $cloudStage
     * @param SpeedSyncSetup|null $speedSyncSetup
     * @param HideFilesStage|null $hideFilesStage
     * @param CreationTransaction|null $transaction
     * @param RescueAgentCreationContext|null $context
     *
     * @return Agent the newly created rescue agent
     */
    public function create(
        string $agentKeyName,
        int $snapshotEpoch,
        bool $pauseSourceAgent,
        string $connectionName,
        bool $hasNativeConfiguration = false,
        SecretString $encryptionPassphrase = null,
        CloneSourceAgentDataset $cloneStage = null,
        CreateAssetStage $agentStage = null,
        BackupRescueAgentStage $backupStage = null,
        StartVirtualMachine $virtualMachineStage = null,
        PauseAgentStage $pauseStage = null,
        CloudUpdateStage $cloudStage = null,
        SpeedSyncSetup $speedSyncSetup = null,
        HideFilesStage $hideFilesStage = null,
        CreationTransaction $transaction = null,
        RescueAgentCreationContext $context = null
    ): Agent {
        // TODO: Refactor this method and create a transaction factory that
        // will be injected in constructor rather than passing stages to this
        // method for sake of unit tests only.
        
        $this->doFeatureCheck();

        $this->logger->setAssetContext($agentKeyName);
        $this->logger->info('RSC0001 Rescue agent requested', ['agent' => $agentKeyName]);

        $sourceAgent = $this->agentService->get($agentKeyName);
        $rescueAgentName = $this->getNextRescueAgentName($sourceAgent);
        $rescueAgentUuid = $this->uuidGenerator->get();

        $context = $context ?: new RescueAgentCreationContext(
            $sourceAgent,
            $rescueAgentName,
            $rescueAgentUuid,
            $snapshotEpoch,
            $encryptionPassphrase ?? new SecretString('')
        );

        $transaction = $transaction ?: new CreationTransaction($context, $this->logger, $this->virtRestoreTool);

        $agentStage = $agentStage ?: new CreateAssetStage(
            $context,
            $this->agentService,
            $this->agentRepository,
            $this->fileSystem,
            $this->logger
        );

        $cloneStage = $cloneStage ?: new CloneSourceAgentDataset(
            $context,
            $this->virtRestoreTool,
            $this->cloneManager,
            $this->fileSystem,
            $this->logger,
            $this->datasetFactory,
            $this->tempAccessService
        );

        $hideFilesStage = $hideFilesStage ?: new HideFilesStage(
            $context,
            $this->fileExclusionService,
            $this->logger
        );

        $backupStage = $backupStage ?: new BackupRescueAgentStage(
            $context,
            $this->agentService,
            $this->logger,
            $this
        );

        $virtualMachineStage = $virtualMachineStage ?: new StartVirtualMachine(
            $context,
            $connectionName,
            $hasNativeConfiguration,
            $this->virtualMachineService,
            $this->connectionService,
            $this->virtRestoreTool,
            $this->logger
        );

        $speedSyncSetup = $speedSyncSetup ?: new SpeedSyncSetup(
            $context,
            $this->speedSync,
            $this->logger
        );

        $pauseStage = $pauseStage ?: new PauseAgentStage(
            $context,
            $this->agentService,
            $this->logger
        );

        $cloudStage = $cloudStage ?: new CloudUpdateStage(
            $context,
            $this->logger,
            $this->agentVolumeService,
            $this->processFactory
        );

        $transaction
            ->add($agentStage)
            ->add($cloneStage)
            ->add($hideFilesStage)
            ->add($backupStage)
            ->add($virtualMachineStage)
            ->addIf(!$sourceAgent->getOriginDevice()->isReplicated(), $speedSyncSetup)
            ->addIf($pauseSourceAgent, $pauseStage)
            ->add($cloudStage);

        try {
            $this->logger->info('RSC0002 Beginning transaction - creating new rescue agent', ['rescueAgentName' => $rescueAgentName]);
            $transaction->commit();
        } catch (TransactionException $e) {
            throw $e->getPrevious() ?: $e;
        }

        $this->logger->info('RSC0003 Rescue agent successfully created', ['rescueAgentName' => $rescueAgentName]);

        return $context->getRescueAgent();
    }

    /**
     * Stop the given rescue agent. This takes a snapshot, powers down the VM, and pauses the agent.
     *
     * @param string $agentKeyName The key name of the rescue agent to stop
     * @param int|null $currentEpochTime Optional injectable parameter; used for the snapshot timestamp
     * @param bool $skipRestoreUpdate
     */
    public function stop(
        string $agentKeyName,
        int $currentEpochTime = null,
        bool $skipRestoreUpdate = false
    ): void {
        try {
            $snapshotTimestamp = $currentEpochTime ?: time();
            $this->logger->setAssetContext($agentKeyName);
            $agent = $this->agentService->get($agentKeyName);
            if (!$agent->isRescueAgent()) {
                $this->logger->error('RSC0016 Attempt to stop was made for a non-rescue agent', ['agent' => $agentKeyName]);
                throw new \Exception($agentKeyName . ' is not a rescue agent.');
            }
            $this->logger->info('RSC0017 Stopping rescue agent', ['agent' => $agentKeyName]);
            $cloneSpec = CloneSpec::fromRescueAgent($agent);
            $vm = $this->virtualMachineService->getVm($cloneSpec->getTargetMountpoint(), $this->logger);
            $this->virtRestoreTool->assertVmNotNull($agentKeyName, $vm);
            $currentStep = 1;
            $totalSteps = 2;
            $this->virtRestoreTool->updateVmStatusStop($agentKeyName, $currentStep, $totalSteps);
            if (!$skipRestoreUpdate) {
                $this->virtRestoreTool->updateRestorePowerState($agentKeyName, RestoreType::RESCUE, false);
            }
            $vm->shutdown();
            $this->backupRescueAgent($agent, $snapshotTimestamp);
            $agent->getLocal()->setPaused(true);
            $this->agentService->save($agent);
        } finally {
            $this->virtRestoreTool->clearVmStatus($agentKeyName);
        }
    }

    /**
     * Start a rescue agent. This starts up a stopped rescue VM and unpauses the agent.
     *
     * @param string $agentKeyName
     * @param SecretString|null $encryptionPassphrase
     */
    public function start(
        string $agentKeyName,
        SecretString $encryptionPassphrase = null
    ): void {
        try {
            $this->logger->setAssetContext($agentKeyName);
            $agent = $this->agentService->get($agentKeyName);
            if (!$agent->isRescueAgent()) {
                $this->logger->error('RSC0018 Attempt made to start a non-rescue agent', ['agent' => $agentKeyName]);
                throw new \Exception($agentKeyName . ' is not a rescue agent.');
            }
            $this->logger->info('RSC0019 Starting rescue agent', ['agent' => $agentKeyName]);

            if ($this->virtRestoreTool->isAgentSealed($agentKeyName)) {
                $this->virtRestoreTool->decryptAgentKey($agentKeyName, $encryptionPassphrase ?? new SecretString(''));
            }

            $cloneSpec = CloneSpec::fromRescueAgent($agent);
            $this->cloneManager->ensureAgentCloneDecrypted($agent, $cloneSpec);
            $vm = $this->virtualMachineService->getVm($cloneSpec->getTargetMountpoint(), $this->logger);
            $this->virtRestoreTool->assertVmNotNull($agentKeyName, $vm);
            $currentStep = 1;
            $totalSteps = 2;
            $this->virtRestoreTool->updateVmStatusStart($agentKeyName, $currentStep, $totalSteps);
            $vm->start();
            $this->virtRestoreTool->updateRestorePowerState($agentKeyName, RestoreType::RESCUE, true);
            $agent->getLocal()->setPaused(false);
            $this->agentService->save($agent);
        } finally {
            $this->virtRestoreTool->clearVmStatus($agentKeyName);
        }
    }

    /**
     * For a given agent, determine if it has any rescue agents.
     */
    public function hasRescueAgents(string $agentKeyName): bool
    {
        $rescueAgents = $this->getAllAssociatedRescueAgents($agentKeyName);
        return count($rescueAgents) > 0;
    }

    /**
     * Get the source agent name for all rescue agents.  Deleted source agents are filtered out.
     *
     * @return string[]
     */
    public function getAllSourceAgents(): array
    {
        $sourceAgents = array();
        $agents = $this->agentService->getAll();
        foreach ($agents as $agent) {
            if ($agent->isRescueAgent()) {
                $sourceAgentName = $agent->getRescueAgentSettings()->getSourceAgentKeyName();
                if ($this->agentService->exists($sourceAgentName)) {
                    $sourceAgents[] = $sourceAgentName;
                }
            }
        }
        $sourceAgents = array_unique($sourceAgents);
        return $sourceAgents;
    }

    /**
     * Archives a rescue agent. This takes a snapshot and stops the virtual machine, leaving the dataset intact.
     * The vmInfo file is removed from the dataset so that we can tell that the rescue agent has been archived.
     *
     * @param string $agentKeyName The name of the rescue agent to archive
     * @param int|null $currentEpochTime Optional injectable parameter; used for the snapshot timestamp
     */
    public function archive(string $agentKeyName, int $currentEpochTime = null): void
    {
        $this->logger->setAssetContext($agentKeyName);
        $snapshotTimestamp = $currentEpochTime ?: time();
        $agent = $this->agentService->get($agentKeyName);

        $this->logger->info('RSC0020 Attempting to archive rescue agent', ['agent' => $agentKeyName]);

        if (!$agent->isRescueAgent()) {
            $this->logger->error('RSC0026 Attempt to archive was made for a non-rescue agent', ['agent' => $agentKeyName]);
            throw new \Exception($agentKeyName . ' is not a rescue agent.');
        }

        try {
            $currentStep = 1;
            $totalSteps = 2;
            $this->virtRestoreTool->updateVmStatusDestroy($agentKeyName, $currentStep, $totalSteps);
            $this->backupRescueAgent($agent, $snapshotTimestamp);

            $cloneSpec = CloneSpec::fromRescueAgent($agent);

            $this->destroyRescueAgentVirtualMachine($agent, $cloneSpec);
            $this->virtRestoreTool->deleteRestore($agentKeyName, RestoreType::RESCUE);

            if ($agent->getEncryption()->isEnabled()) {
                $this->cloneManager->destroyLoops($cloneSpec->getTargetMountpoint());
            }
        } catch (\Exception $e) {
            $this->logger->error('RSC0006 An exception was thrown while attempting to destroy the virtual machine', ['exception' => $e]);

            throw new \Exception(
                "Cannot archive $agentKeyName, virtual machine could not be destroyed.",
                $e->getCode(),
                $e
            );
        } finally {
            $this->virtRestoreTool->clearVmStatus($agentKeyName);
        }

        $agent->getLocal()->setPaused(true);
        $this->agentService->save($agent);
    }

    /**
     * Get whether the specified rescue agent is in an archived state or not.
     *
     * @param string $agentKeyName The name of the rescue agent
     * @return bool Whether or not the rescue agent is archived
     */
    public function isArchived($agentKeyName): bool
    {
        $agent = $this->agentService->get($agentKeyName);

        if (!$agent->isRescueAgent()) {
            throw new \Exception($agentKeyName . " is not a rescue agent.");
        }

        try {
            $vm = $this->getVm($agentKeyName);
            return is_null($vm);
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Given an agent key name, promote an associated rescue agent if needed.
     *
     * @param string $agentKeyName
     */
    public function promoteIfNecessary($agentKeyName): void
    {
        $agent = $this->agentService->get($agentKeyName);

        if ($this->isRescueAgentPromotionNecessary($agentKeyName)) {
            $this->promoteRescueAgent($agent);
        }
    }

    /**
     * Filter an array of snapshots to remove those that existed before a rescue agent was created.
     *
     * @param string $agentKeyName
     * @param array $snapshotEpochs array of snapshot epochs
     *
     * @return array
     */
    public function filterNonRescueAgentSnapshots($agentKeyName, $snapshotEpochs): array
    {
        if (!is_array($snapshotEpochs)) {
            return array();
        }

        $rescueAgent = $this->agentService->get($agentKeyName);

        if (!$rescueAgent->isRescueAgent()) {
            return $snapshotEpochs;
        }

        $rescueAgentStartingEpoch = $rescueAgent->getRescueAgentSettings()->getSourceAgentSnapshotEpoch();

        foreach ($snapshotEpochs as $index => $point) {
            if ((int) $point < $rescueAgentStartingEpoch) {
                unset($snapshotEpochs[$index]);
            }
        }

        return array_values($snapshotEpochs);
    }

    /**
     * Start up any rescue agents that may not have been booted since system last came up.
     */
    public function reconcileBootStates(): void
    {
        $this->logger->info('RSC0022 Checking for stopped rescue VMs');
        $unencryptedRescueAgentList = $this->getUnencryptedRescueAgents();
        $unencryptedRescueAgentKeyList = array();
        foreach ($unencryptedRescueAgentList as $unencryptedRescueAgent) {
            $unencryptedRescueAgentKeyList[] = $unencryptedRescueAgent->getKeyName();
        }

        if (count($unencryptedRescueAgentKeyList) === 0) {
            return;
        }

        $rescueAgentRestoreList = $this->restoreService->getAllForAssets($unencryptedRescueAgentKeyList);

        $rescueAgentBootList = $this->getRescueAgentBootList($rescueAgentRestoreList);

        foreach ($rescueAgentBootList as $restore) {
            try {
                $this->start($restore->getAssetKey(), null);
            } catch (\Exception $e) {
                // Any exceptions here should be logged and ignored, so that subsequent rescue VMs will get a start call
                $this->logger->error(
                    'RSC0021 An exception was thrown while trying to reconcile the VM boot state',
                    ['exception' => $e]
                );
            }
        }
    }

    /**
     * Get the current state of a rescue agent's virtual machine.
     *
     * @param Agent $agent
     * @return RescueVirtualMachineState
     */
    public function getVirtualMachineState(Agent $agent): RescueVirtualMachineState
    {
        $isPoweredOn = false;
        $isRunning = false;
        $snapshot = null;

        try {
            if ($agent->isRescueAgent()) {
                $restores = $this->restoreService->getForAsset($agent->getKeyName(), [RestoreType::RESCUE]);
                if (count($restores) > 0) {
                    $rescueAgentRestore = reset($restores);
                    $isPoweredOn = $rescueAgentRestore->virtualizationIsRunning();

                    // NOTE: This logic depends on virtualizationIsRunning being based on UIRestores.  That file isn't
                    // updated for "unexpected" shutdowns (e.g. device reboot), so the inconsistency between that and
                    // the actual state from libvirt implies an "unexpected" shutdown that requires a restart.
                    if ($isPoweredOn) {
                        $vm = $this->getVm($agent->getKeyName());
                        $isRunning = $vm->isRunning();
                        $snapshot = $rescueAgentRestore->getPoint();
                    }
                }
            }
        } catch (Throwable $e) {
            // this method is called from when preparing agent data for UI, don't allow it to fail
        }

        return new RescueVirtualMachineState($agent->getKeyName(), $snapshot, $isPoweredOn, $isRunning);
    }

    /**
     * Take a backup of a rescue agent
     *
     * @param Agent $agent
     * @param int $startTime The desired snapshot epoch time of the backup
     * @param bool $forced Whether this is a forced or scheduled backup
     *
     * @return int The snapshot epoch time of the successful backup
     */
    public function doBackup(Agent $agent, int $startTime, bool $forced = false): int
    {
        $lock = $this->lockFactory->create(sprintf(self::LOCK_FILE_FORMAT, $agent->getKeyName()));
        $lock->assertExclusiveAllowWait(10);

        $this->logger->setAssetContext($agent->getKeyName());
        $backupType = $forced ? BackupReport::FORCED_BACKUP : BackupReport::SCHEDULED_BACKUP;

        $backupReportContext = new BackupReportContext($agent->getKeyName());

        $this->backupReportManager->startBackupReport($backupReportContext, $this->dateTimeService->getTime(), $backupType);
        $this->backupReportManager->beginBackupAttempt($backupReportContext);

        $this->logger->info('RSM0002 Running snapshot');
        $epoch = $agent->getDataset()->takeSnapshot($startTime);

        if (is_null($epoch)) {
            $this->logger->error('RSM0004 Failed to take snapshot');
            $this->backupReportManager->endFailedBackupAttempt($backupReportContext, "RSM0004", "Failed to take snapshot");
            $this->backupReportManager->finishBackupReport($backupReportContext);
            throw new Exception("Could not take a snapshot for {$agent->getKeyName()}");
        }

        $transfersFile = Agent::KEYBASE . $agent->getKeyName() . '.transfers';
        $transferString = $epoch . ':' . $agent->getDataset()->getSnapshotSize($epoch) . PHP_EOL;
        $this->fileSystem->touch($transfersFile);
        $this->fileSystem->filePutContents($transfersFile, $transferString, FILE_APPEND);
        $recoveryPointHistory = new RecoveryPointHistoryRecord();

        $agentConfig = $this->agentConfigFactory->create($agent->getKeyName());
        $agentConfig->loadRecord($recoveryPointHistory);
        $recoveryPointHistory->addTransfer($epoch, $agent->getDataset()->getSnapshotSize($epoch));
        $recoveryPointHistory->addTotalUsed($epoch, $agent->getDataset()->getUsedSize());
        $agentConfig->saveRecord($recoveryPointHistory);

        $this->logger->info('RSM0005 Snapshot successfully completed');
        $this->backupReportManager->endSuccessfulBackupAttempt($backupReportContext, "RSM0005");
        $this->backupReportManager->finishBackupReport($backupReportContext);

        $lock->unlock();

        return $epoch;
    }

    /**
     * Return a list of Rescue Agents that should be booted at system boot time
     * @param $rescueAgentVmList
     * @return array
     */
    private function getRescueAgentBootList($rescueAgentVmList): array
    {
        $rescueAgentService = $this;
        $rescueAgentBootList = array_filter($rescueAgentVmList, function (Restore $restore) use ($rescueAgentService) {
            return $rescueAgentService->shouldAttemptVmStart($restore);
        });

        return $rescueAgentBootList;
    }

    /**
     * Return list of all unencrypted rescue agents
     * @return array|Agent[]
     */
    private function getUnencryptedRescueAgents(): array
    {
        $agentList = $this->agentService->getAll();

        $unencryptedRescueAgents = array_filter($agentList, function (Agent $agent) {
            return $agent->isRescueAgent() && !$agent->getEncryption()->isEnabled();
        });

        return $unencryptedRescueAgents;
    }

    /**
     * Used as array_filter callback to determine if a Rescue agent should have VM auto-booted
     * @param Restore $restore
     * @return bool true if VM start should be attempted, false if otherwise
     */
    private function shouldAttemptVmStart(Restore $restore): bool
    {
        $vm = $this->getVm($restore->getAssetKey());
        $this->virtRestoreTool->assertVmNotNull($restore->getAssetKey(), $vm);

        return $restore->virtualizationIsRunning()                  // Check Virt is running according to UIRestores
            && !$vm->isRunning(); // VM is not actually running, according to Virt service
    }

    /**
     * Get all rescue agents associated with the agent with the given key name.
     *
     * @param string $agentKeyName
     * @return Agent[]
     */
    private function getAllAssociatedRescueAgents($agentKeyName): array
    {
        $rescueAgents = array_filter(
            $this->agentService->getAll(),
            function (Agent $agent) use ($agentKeyName) {
                return $agent->isRescueAgent() &&
                    $agent->getRescueAgentSettings()->getSourceAgentKeyName() == $agentKeyName;
            }
        );
        return array_values($rescueAgents);
    }

    /**
     * Get the next available rescue agent name for the agent with the given keyname.
     *
     * @param Agent $agent
     * @return string
     */
    private function getNextRescueAgentName(Agent $agent): string
    {
        $rescueAgents = $this->getAllAssociatedRescueAgents($agent->getKeyName());
        $rescueAgentNames = array_map(
            function (Agent $agent) {
                return $agent->getName();
            },
            $rescueAgents
        );

        if ($rescueAgentNames) {
            sort($rescueAgentNames);
            $mostRecentRescueAgent = end($rescueAgentNames);
            $position = strrpos($mostRecentRescueAgent, '-') + 1;
            $nextNumber = (int)substr($mostRecentRescueAgent, $position) + 1;
        } else {
            $nextNumber = 1;
        }

        return sprintf(self::NAME_TEMPLATE, $agent->getPairName(), $nextNumber);
    }

    /**
     * Returns whether or not a promotion of a rescue agent is needed. The agent key name
     * can either be the source agent key name or a rescue agent key name. This method
     * should only be called when deleting an agent (normal or rescue).
     *
     * @param string $agentKeyName
     * @return bool
     */
    private function isRescueAgentPromotionNecessary($agentKeyName): bool
    {
        $agent = $this->agentService->get($agentKeyName);

        // If the source agent is being deleted, check if other rescue agents exist
        if (!$agent->isRescueAgent()) {
            $associatedRescueAgents = $this->getAllAssociatedRescueAgents($agentKeyName);
            return !empty($associatedRescueAgents);
        }

        // If a rescue agent is being deleted but the source agent still exists, no promotion needed
        $sourceAgentName = $agent->getRescueAgentSettings()->getSourceAgentKeyName();
        if ($this->agentService->exists($sourceAgentName)) {
            return false;
        }

        // The source agent no longer exists and a rescue agent is being deleted
        $mostRecentRescueAgentName = $this->getMostRecentAssociatedRescueAgent($sourceAgentName)->getKeyName();
        $rescueAgents = $this->getAllAssociatedRescueAgents($sourceAgentName);
        $rescueAgentCount = count($rescueAgents);

        $otherRescueAgentsExist = $rescueAgentCount > 1;
        $isMostRecentRescueAgent = $agentKeyName === $mostRecentRescueAgentName;

        if ($otherRescueAgentsExist && $isMostRecentRescueAgent) {
            return true;
        }

        return false;
    }

    /**
     * Promote a rescue agent. This promotes the rescue agent's zfs dataset.
     *
     * @param Agent $agent
     */
    private function promoteRescueAgent(Agent $agent): void
    {
        $agentKeyName = $agent->getKeyName();
        $this->logger->setAssetContext($agentKeyName);

        try {
            $promoteRescueAgent = $this->getMostRecentAssociatedRescueAgent($agentKeyName);
            $promoteZfsPath = $promoteRescueAgent->getDataset()->getZfsPath();
            $this->logger->info('RSC0027 Promoting zfs clone', ['clonePath' => $promoteZfsPath]);
            $this->zfsService->promoteClone($promoteZfsPath);
        } catch (\Exception $e) {
            $this->logger->error(
                'RSC0028 An exception was thrown while attempting to promote the ZFS clone',
                ['error' => $e->getMessage()]
            );
        }
    }

    /**
     * Returns the "most recent" rescue agent, which is the rescue agent that has the highest (most recent)
     * sourceAgentSnapshotEpoch. The most recent rescue agent contains all snapshots that earlier
     * rescue agents are based off of. If the agent from the passed in key name is a rescue agent,
     * then the name is excluded from the search and the source agent's key name is used. In the event that
     * two or more rescue agents with the same sourceAgentSnapshotEpoch exist, the latest of the rescue
     * agents are selected (as determined by getAllAssociatedRescueAgents).
     *
     * @param string $agentKeyName
     * @return bool|Agent False if there are no associated agents, otherwise the most recent rescue agent
     */
    private function getMostRecentAssociatedRescueAgent($agentKeyName)
    {
        $excludedKeyName = false;
        $rescueAgentArray = array();
        $chosenAgentKeyName = $agentKeyName;

        if ($this->agentService->exists($agentKeyName)) {
            $agent = $this->agentService->get($agentKeyName);
            if ($agent->isRescueAgent()) {
                $excludedKeyName = $agentKeyName;
                $chosenAgentKeyName = $agent->getRescueAgentSettings()->getSourceAgentKeyName();
            }
        }

        $rescueAgents = $this->getAllAssociatedRescueAgents($chosenAgentKeyName);

        foreach ($rescueAgents as $agent) {
            if ($excludedKeyName === $agent->getKeyName()) {
                continue;
            }
            $timestamp = $agent->getRescueAgentSettings()->getSourceAgentSnapshotEpoch();
            $rescueAgentArray[$timestamp] = $agent;
        }

        ksort($rescueAgentArray);

        return end($rescueAgentArray);
    }

    /**
     * Check if rescue agents are supported, and throw if they are not.
     */
    private function doFeatureCheck(): void
    {
        if (!$this->featureService->isSupported(FeatureService::FEATURE_RESCUE_AGENTS)) {
            throw new \Exception('Rescue agents are not supported on this device.');
        }
    }

    /**
     * Destroy the virtual machine of a rescue agent
     *
     * @param Agent $agent The rescue agent
     * @param CloneSpec $cloneSpec
     */
    private function destroyRescueAgentVirtualMachine(
        Agent $agent,
        CloneSpec $cloneSpec
    ): void {
        $this->logger->info('RSC0005 Destroying the virtual machine for the rescue agent'); // log code is used by device-web see DWI-2252

        $vm = $this->virtualMachineService->getVm($cloneSpec->getTargetMountpoint(), $this->logger);

        if (is_null($vm)) {
            $this->logger->warning('RSC0024 Skipping Rescue Agent vm destroy, vm does not exist for agentKey', ['agent' => $agent]);
        } else {
            $this->virtualMachineService->destroyAgentVm($agent, $vm);
        }
    }

    /**
     * Snapshot a rescue agent and save the new recovery point
     *
     * @param Agent $agent The rescue agent
     * @param int|null $currentEpochTime Optional injectable parameter; used for the snapshot timestamp
     */
    private function backupRescueAgent($agent, $currentEpochTime = null): void
    {
        $snapshotTimestamp = $currentEpochTime ?: time();
        $this->doBackup($agent, $snapshotTimestamp, $forced = true);
        $agent->getLocal()->getRecoveryPoints()->add(new RecoveryPoint($snapshotTimestamp));
        $this->agentService->save($agent);
    }

    /**
     * Get an existing rescue agent vm instance
     *
     * @param string $agentKeyName
     * @return VirtualMachine|null
     */
    private function getVm(string $agentKeyName): ?VirtualMachine
    {
        $this->logger->setAssetContext($agentKeyName);
        $agent = $this->agentService->get($agentKeyName);
        $cloneSpec = CloneSpec::fromRescueAgent($agent);
        return $this->virtualMachineService->getVm($cloneSpec->getTargetMountpoint(), $this->logger);
    }
}
