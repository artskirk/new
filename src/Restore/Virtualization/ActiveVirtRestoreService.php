<?php

namespace Datto\Restore\Virtualization;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Encryption\AbstractPassphraseException;
use Datto\Config\AgentConfigFactory;
use Datto\Connection\Libvirt\AbstractLibvirtConnection;
use Datto\Connection\Libvirt\KvmConnection;
use Datto\Connection\Service\ConnectionService;
use Datto\Feature\FeatureService;
use Datto\Log\LoggerAwareTrait;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Datto\Restore\AssetCloneManager;
use Datto\Restore\CloneSpec;
use Datto\Restore\FileExclusionService;
use Datto\Restore\RestoreService;
use Datto\Restore\RestoreType;
use Datto\Utility\Security\SecretString;
use Datto\Utility\Systemd\Systemctl;
use Datto\Utility\Systemd\SystemdRunningStatus;
use Datto\Virtualization\Exceptions\RemoteStorageException;
use Datto\Virtualization\Hypervisor\Config\AbstractVmSettings;
use Datto\Virtualization\Hypervisor\Config\VmSettingsFactory;
use Datto\Virtualization\Providers\NetworkOptions;
use Datto\Virtualization\VirtualMachine;
use Exception;
use Psr\Log\LoggerAwareInterface;
use RuntimeException;
use Throwable;

/**
 * Manage agent active virt restores
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class ActiveVirtRestoreService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const STATUS_CLONE_DATASET = 'cloneDataset';

    /** @var AgentService */
    private $agentService;

    /** @var AssetCloneManager */
    private $cloneManager;

    /** @var ConnectionService */
    private $connectionService;

    /** @var VirtualMachineService */
    private $virtualMachineService;

    /** @var VirtualizationRestoreTool */
    private $virtRestoreTool;

    /** @var RestoreService */
    private $restoreService;

    /** @var FileExclusionService */
    private $fileExclusionService;

    /** @var Collector */
    private $collector;

    /** @var AgentConfigFactory */
    private $agentConfigFactory;

    /** @var AgentVmManager */
    private $agentVmManager;
    
    /** @var NetworkOptions */
    private $networkOptions;

    /** @var Systemctl */
    private $systemctl;

    /** @var FeatureService */
    private $featureService;

    public function __construct(
        AgentService $agentService,
        VirtualMachineService $virtualMachineService,
        AssetCloneManager $cloneManager,
        ConnectionService $connectionService,
        VirtualizationRestoreTool $virtRestoreTool,
        RestoreService $restoreService,
        FileExclusionService $fileExclusionService,
        Collector $collector,
        AgentConfigFactory $agentConfigFactory,
        AgentVmManager $agentVmManager,
        NetworkOptions $networkOptions,
        Systemctl $systemctl,
        FeatureService $featureService
    ) {
        $this->virtualMachineService = $virtualMachineService;
        $this->agentService = $agentService;
        $this->cloneManager = $cloneManager;
        $this->connectionService = $connectionService;
        $this->virtRestoreTool = $virtRestoreTool;
        $this->restoreService = $restoreService;
        $this->fileExclusionService = $fileExclusionService;
        $this->collector = $collector;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->agentVmManager = $agentVmManager;
        $this->networkOptions = $networkOptions;
        $this->systemctl = $systemctl;
        $this->featureService = $featureService;
    }

    /**
     * Create a virtualization restore vm. Prepares zfs clone, defines libvirt vm, tracks UI restore, and optionally
     * starts the vm. If restore has already been created, the existing vm definition and restore
     * will be used, and the vm will be started.
     *
     * @param string $agentKeyName
     * @param string $snapshot
     * @param string $connectionName
     * @param SecretString|null $passphrase
     * @param bool $hasNativeConfiguration
     * @param bool $start
     */
    public function startVm(
        string $agentKeyName,
        string $snapshot,
        string $connectionName,
        SecretString $passphrase = null,
        bool $hasNativeConfiguration = false,
        bool $start = true
    ) {
        $currentSystemState = $this->systemctl->isSystemRunning();
        if ($currentSystemState === SystemdRunningStatus::INITIALIZING()) {
            throw new Exception("System has not initialized all necessary services, and is unable to start VMs at this time");
        }
        if ($currentSystemState === SystemdRunningStatus::STOPPING()) {
            throw new Exception("System is currently stopping, and should not be starting VMs at this time");
        }

        $totalSteps = 2 + ($start ? 1 : 0);

        try {
            /** @var Agent $agent */
            $agent = $this->agentService->get($agentKeyName);
            $this->logger->setAssetContext($agentKeyName);

            if ($connectionName === KvmConnection::CONNECTION_NAME) {
                $type = Metrics::RESTORE_TYPE_VIRT_LOCAL;
                $this->featureService->assertSupported(FeatureService::FEATURE_RESTORE_VIRTUALIZATION_LOCAL, null, $agent);
            } else {
                $type = Metrics::RESTORE_TYPE_VIRT_HYPERVISOR;
                $this->featureService->assertSupported(FeatureService::FEATURE_RESTORE_VIRTUALIZATION_HYPERVISOR, null, $agent);
            }

            $this->collector->increment(Metrics::RESTORE_STARTED, [
                'type' => $type,
                'is_replicated' => $agent->getOriginDevice()->isReplicated(),
            ]);

            $this->logger->info(
                'VMX0500 Starting active virt', // log code is used by device-web see DWI-2252
                [
                    'agentKey' => $agentKeyName,
                    'connection' => $connectionName
                ]
            );

            $cloneSpec = CloneSpec::fromAsset($agent, $snapshot, RestoreType::ACTIVE_VIRT);

            // check if the vm has already been created
            $vm = $this->getVm($agentKeyName);

            $vmDoesNotExist = is_null($vm);

            if ($vmDoesNotExist) {
                if (is_null($connection = $this->connectionService->get($connectionName))) {
                    throw new RuntimeException("Cannot find hypervisor connection with name '$connectionName'");
                }

                //Password required to create a VM for an encrypted drive
                $this->virtRestoreTool->decryptAgentKey($agentKeyName, $passphrase ?? new SecretString(''));
                $vm = $this->createVm(
                    $agent,
                    $cloneSpec,
                    $connection,
                    $totalSteps,
                    $hasNativeConfiguration
                );
            } else {
                //Password required to restart VM if no key in stash (ie: after a device reboot)
                if ($this->virtRestoreTool->isAgentSealed($agentKeyName)) {
                    $this->virtRestoreTool->decryptAgentKey($agentKeyName, $passphrase ?? new SecretString(''));
                }
                $this->cloneManager->ensureAgentCloneDecrypted($agent, $cloneSpec);
            }

            if ($start) {
                $currentStep = 2;
                $this->virtRestoreTool->updateVmStatusStart($agentKeyName, $currentStep, $totalSteps);

                try {
                    $vm->start();
                } catch (Throwable $e) {
                    if ($vmDoesNotExist) {
                        // only attempt to tear everything down if this vm did not already exist.
                        $this->logger->error('VMX0504 Failed to start active virt, cleaning up', ['agentKey' => $agentKeyName]);

                        // vm failed to start, so attempt to destroy it
                        $this->tryDestroyVm($agent, $vm);
                        $this->tryDestroyClone($cloneSpec);
                        $this->tryDeleteRestore($agentKeyName);
                    }
                    throw $e;
                }
            }

            $this->virtRestoreTool->updateRestorePowerState($agentKeyName, RestoreType::ACTIVE_VIRT, $start);

            $this->logger->info('VMX0510 Active virt started successfully.', ['agentKey' => $agentKeyName]);
        } catch (Throwable $e) {
            $this->logger->error('VMX1001 Active virt failed to start', ['exception' => $e, 'agentKey' => $agentKeyName]);

            if ($e instanceof RemoteStorageException || $e instanceof AbstractPassphraseException) {
                // Note: these error messages are displayed in the UI without translation, should be fixed
                throw $e;
            }

            throw new ActiveVirtRestoreException(
                "Failed to start vm: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        } finally {
            $this->virtRestoreTool->clearVmStatus($agentKeyName);
        }
    }

    /**
     * Restart an existing virtualization
     *
     * @param string $agentKeyName
     */
    public function restartVm(string $agentKeyName)
    {
        $currentSystemState = $this->systemctl->isSystemRunning();
        if ($currentSystemState === SystemdRunningStatus::INITIALIZING()) {
            throw new Exception("System has not initialized all necessary services, and is unable to restart VMs at this time");
        }
        if ($currentSystemState === SystemdRunningStatus::STOPPING()) {
            throw new Exception("System is currently stopping, and should not be restarting VMs at this time");
        }

        $totalSteps = 2;

        try {
            $this->logger->setAssetContext($agentKeyName);
            $this->logger->info('VMX0515 Restarting active virt', ['agentKey' => $agentKeyName]);
            $vm = $this->getVm($agentKeyName);
            $this->virtRestoreTool->assertVmNotNull($agentKeyName, $vm);

            $currentStep = 1;
            $this->virtRestoreTool->updateVmStatusStart($agentKeyName, $currentStep, $totalSteps);

            $vm->restart();

            $this->virtRestoreTool->updateRestorePowerState($agentKeyName, RestoreType::ACTIVE_VIRT, true);
            $this->logger->info('VMX0518 Restart of active virt is successful', ['agentKey' => $agentKeyName]);
        } catch (Throwable $e) {
            throw new ActiveVirtRestoreException(
                "Failed to restart vm for agent '$agentKeyName'. {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        } finally {
            $this->virtRestoreTool->clearVmStatus($agentKeyName);
        }
    }

    /**
     * Stop a vm associated with active restore of this agent
     *
     * @param string $agentKeyName
     * @param bool $skipRestoreUpdate Whether to skip updating the powered on state in the UIRestores file for this vm
     */
    public function stopVm(string $agentKeyName, bool $skipRestoreUpdate = false): void
    {
        $totalSteps = 2;

        try {
            $this->logger->setAssetContext($agentKeyName);
            $this->logger->info('VMX0520 Stopping active virt', ['agentKey' => $agentKeyName]); // log code is used by device-web see DWI-2252
            $vm = $this->getVm($agentKeyName);
            $this->virtRestoreTool->assertVmNotNull($agentKeyName, $vm);

            $currentStep = 1;
            $this->virtRestoreTool->updateVmStatusStop($agentKeyName, $currentStep, $totalSteps);

            if (!$skipRestoreUpdate) {
                $this->virtRestoreTool->updateRestorePowerState($agentKeyName, RestoreType::ACTIVE_VIRT, false);
            }

            $vm->stop();

            $this->logger->info('VMX0525 Active virt stopped successfully', ['agentKey' => $agentKeyName]);
        } catch (Throwable $e) {
            throw new ActiveVirtRestoreException(
                "Failed to stop vm for agent '$agentKeyName'. {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        } finally {
            $this->virtRestoreTool->clearVmStatus($agentKeyName);
        }
    }

    /**
     * Destroy vm, clone for an active restore
     *
     * @param string $agentKeyName
     * @param string $snapshot
     */
    public function destroyVm(string $agentKeyName, string $snapshot)
    {
        $this->systemctl->assertSystemRunning();

        $totalSteps = 2;

        try {
            $agent = $this->agentService->get($agentKeyName);
            $this->logger->setAssetContext($agentKeyName);
            $this->logger->info('VMX0530 Destroying active virt', ['agentKey' => $agentKeyName]); // log code is used by device-web see DWI-2252

            $vm = $this->getVm($agentKeyName);
            if (is_null($vm)) {
                $this->logger->warning('VMX0533 Skipping destroy, VM does not exist', ['agentKey' => $agentKeyName, 'snapshot' => $snapshot]);
            } else {
                $this->logger->debug('VMX0848 VM Destroy started', ['vmName' => $vm->getName()]);

                $currentStep = 1;
                $this->virtRestoreTool->updateVmStatusDestroy($agentKeyName, $currentStep, $totalSteps);

                $this->virtualMachineService->destroyAgentVm($agent, $vm);
            }

            $cloneSpec = CloneSpec::fromAsset($agent, $snapshot, RestoreType::ACTIVE_VIRT);
            $this->cloneManager->destroyClone($cloneSpec);

            $this->virtRestoreTool->deleteRestore($agentKeyName, RestoreType::ACTIVE_VIRT);

            $this->logger->info('VMX0535 Active virt destroyed successfully.', ['agentKey' => $agentKeyName]);
        } catch (Throwable $e) {
            throw new ActiveVirtRestoreException(
                "Failed to destroy vm for agent '$agentKeyName', snapshot '$snapshot'. {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        } finally {
            $this->virtRestoreTool->clearVmStatus($agentKeyName);
        }
    }

    public function getResources(string $agent, string $connectionName): AbstractVmSettings
    {
        $agentConfig = $this->agentConfigFactory->create($agent);

        // Changes vmSettings File
        $connection = $this->connectionService->get($connectionName);
        $settings = VmSettingsFactory::create($connection->getType());
        $agentConfig->loadRecord($settings);

        return $settings;
    }

    /**
     * Change VM resources for a given agent. If there is an active VM, the settings will be applied to it.
     *
     * @param string $agent
     * @param string $connectionName
     * @param ChangeResourcesRequest $request
     */
    public function changeResources(
        string $agent,
        string $connectionName,
        ChangeResourcesRequest $request
    ): void {
        $this->logger->setAssetContext($agent);
        $this->logger->info('VMX0900 Changing virtual machine resources', ['agentKey' => $agent]);

        $agentConfig = $this->agentConfigFactory->create($agent);

        // Changes vmSettings File
        $connection = $this->connectionService->get($connectionName);
        $settings = VmSettingsFactory::create($connection->getType());
        $agentConfig->loadRecord($settings);

        // $request is mutable, any conditional checks should be done on variables
        $cpuCount = $request->getCpuCount();
        $memoryInMB = $request->getMemoryInMB();
        $storageController = $request->getStorageController();
        $networkMode = $request->getNetworkMode();
        $videoController = $request->getVideoController();
        $networkController = $request->getNetworkController();

        if ($cpuCount !== null) {
            $maxCpuCount = $connection->getLibvirt()->hostGetNodeCpuCount();
            if ($cpuCount < 1 || $cpuCount > $maxCpuCount) {
                throw new \InvalidArgumentException(sprintf(
                    'CPU count must be in the range %d to %d.',
                    1,
                    $maxCpuCount
                ));
            }

            $settings->setCpuCount($cpuCount);
        }
        if ($memoryInMB !== null) {
            if ($memoryInMB <= 0) {
                throw new \InvalidArgumentException('Memory in MB must be greater than 0');
            }

            $settings->setRam($memoryInMB);
        }
        if (!empty($storageController)) {
            if (!in_array($storageController, $settings->getSupportedStorageControllers())) {
                throw new \InvalidArgumentException(sprintf(
                    'Storage controller "%s" is not supported for this hypervisor connection. Supported values are ["%s"].',
                    $storageController,
                    implode('","', $settings->getSupportedStorageControllers())
                ));
            }
            $settings->setStorageController($storageController);
        }
        if (!empty($networkMode)) {
            $supported = $this->networkOptions->getSupportedNetworkModes($connection);
            if (!in_array($networkMode, $supported)) {
                throw new \InvalidArgumentException(sprintf(
                    'Network mode "%s" is not supported for this hypervisor connection. Supported values are ["%s"].',
                    $networkMode,
                    implode('","', $supported)
                ));
            }

            $settings->setNetworkMode($networkMode);
        }
        if (!empty($videoController)) {
            if (!in_array($videoController, $settings->getSupportedVideoControllers())) {
                throw new \InvalidArgumentException(sprintf(
                    'Video controller "%s" is not supported for this hypervisor connection. Supported values are ["%s"].',
                    $videoController,
                    implode('","', $settings->getSupportedVideoControllers())
                ));
            }

            $settings->setVideoController($videoController);
        }
        if (!empty($networkController)) {
            if (!in_array($networkController, $settings->getSupportedNetworkControllers())) {
                throw new \InvalidArgumentException(sprintf(
                    'Network controller "%s" is not supported for this hypervisor connection. Supported values are ["%s"].',
                    $networkController,
                    implode('","', $settings->getSupportedNetworkControllers())
                ));
            }
            $settings->setNetworkController($networkController);
        }
        $settings->setUserDefined(true);

        $this->logger->debug('VMX0901 Persisting virtual machine resources', ['agentKey' => $agent]);

        $agentConfig->saveRecord($settings);

        $this->logger->debug('VMX0902 Updating active virtual machine resources if one exists', ['agentKey' => $agent]);

        // Changes libvirt's domain XML
        $this->agentVmManager->updateVmSettings(
            $agent,
            $settings->getCpuCount(),
            $settings->getRam(),
            $settings->getStorageController(),
            $settings->getNetworkModeRaw(),
            $settings->getVideoController(),
            $settings->getNetworkController()
        );

        $this->logger->debug('VMX0903 Virtual machine resources updated successfully', ['agentKey' => $agent]);
    }

    /**
     * Get all active virts
     */
    public function getAllActiveRestores(): array
    {
        return $this->restoreService->getAllForAssets(null, [RestoreType::ACTIVE_VIRT]);
    }

    /**
     * Gets the amount of provisioned memory for all active virts
     */
    public function getAllActiveRestoresProvisionedRam(string $connectionName): int
    {
        $activeVirts = $this->getAllActiveRestores();
        $provisionedRam = 0;
        foreach ($activeVirts as $activeVirt) {
            $resources = $this->getResources($activeVirt->getAssetKey(), $connectionName);
            $provisionedRam += $resources->getRam();
        }

        return $provisionedRam;
    }

    /**
     * Destroy vm, clone for all active restores
     */
    public function destroyAllActiveRestores()
    {
        $activeRestores = $this->restoreService->getAllForAssets(null, [RestoreType::ACTIVE_VIRT]);
        foreach ($activeRestores as $restore) {
            $this->destroyVm(
                $restore->getAssetKey(),
                $restore->getPoint()
            );
        }
    }

    /**
     * Return an existing vm if it exists for this agent
     *
     * @param string $agentKeyName
     * @return VirtualMachine|null
     */
    private function getVm(string $agentKeyName)
    {
        $agent = $this->agentService->get($agentKeyName);
        $this->logger->setAssetContext($agentKeyName);

        // snapshot not necessary to derive target mountpoint of active virt
        $cloneSpec = CloneSpec::fromAsset($agent, 0, RestoreType::ACTIVE_VIRT);
        return $this->virtualMachineService->getVm($cloneSpec->getTargetMountpoint(), $this->logger);
    }

    private function createVm(
        Agent $agent,
        CloneSpec $cloneSpec,
        AbstractLibvirtConnection $connection,
        int $totalSteps,
        bool $hasNativeConfiguration
    ): VirtualMachine {
        $vmName = $this->virtualMachineService->generateVmName(
            $agent,
            $cloneSpec->getSuffix()
        );
        $this->logger->debug('VMX0844 VM create requested', ['vmName' => $vmName]);

        $currentStep = 1;
        $this->virtRestoreTool->updateVmStatus(
            $agent->getKeyName(),
            $currentStep,
            $totalSteps,
            self::STATUS_CLONE_DATASET
        );

        $this->virtRestoreTool->createRestore(
            $agent->getKeyName(),
            $cloneSpec->getSnapshotName(),
            RestoreType::ACTIVE_VIRT,
            $connection->getName()
        );

        $this->cloneManager->createClone($cloneSpec);

        // TODO revisit whether file exclusion failure should fail the whole vm creation
        try {
            $this->fileExclusionService->exclude($cloneSpec);
        } catch (Exception $e) {
            $this->logger->warning('VMX0890 Failed file exclusions but continuing on', ['exception' => $e]);
        }

        $vmSettings = $this->virtRestoreTool->getVmSettings($agent, $connection->getType());

        $currentStep = 2;
        $this->virtRestoreTool->updateVmStatusStart($agent->getKeyName(), $currentStep, $totalSteps);

        try {
            $vm = $this->virtualMachineService->createAgentVm(
                $agent,
                $vmName,
                $cloneSpec,
                $cloneSpec->getSnapshotName(),
                $connection,
                $vmSettings,
                $useInjector = false,
                $hasNativeConfiguration
            );

            return $vm;
        } catch (Throwable $e) {
            // cleanup after failed virtualization before rethrowing original error
            $this->tryDeleteRestore($agent->getKeyName());
            $this->tryDestroyClone($cloneSpec);
            throw $e;
        }
    }

    private function tryDestroyVm(Agent $agent, VirtualMachine $vm)
    {
        try {
            $this->virtualMachineService->destroyAgentVm($agent, $vm);
        } catch (Throwable $e) {
            $this->logger->error('VMX0700 Error occurred while trying to destroy vm', ['vmName' => $vm->getName(), 'exception' => $e]);
        }
    }

    private function tryDeleteRestore(string $agentKey)
    {
        try {
            $this->virtRestoreTool->deleteRestore($agentKey, RestoreType::ACTIVE_VIRT);
        } catch (Throwable $e) {
            $this->logger->error('VMX0701 Error occurred while trying to delete active virt Restore', ['agentKey' => $agentKey, 'exception' => $e]);
        }
    }

    private function tryDestroyClone(CloneSpec $cloneSpec)
    {
        try {
            $this->cloneManager->destroyClone($cloneSpec);
        } catch (Throwable $e) {
            $this->logger->error('VMX0702 Error occurred while trying to destroy clone', ['clone' => $cloneSpec->getTargetDatasetName(), 'exception' => $e]);
        }
    }
}
