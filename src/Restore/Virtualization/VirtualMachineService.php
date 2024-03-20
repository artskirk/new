<?php

namespace Datto\Restore\Virtualization;

use Datto\AppKernel;
use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Agentless\Generic\GenericAgentless;
use Datto\Asset\Agent\Agentless\Linux\LinuxAgent as AgentlessLinux;
use Datto\Asset\Agent\Agentless\Windows\WindowsAgent as AgentlessWindows;
use Datto\Asset\Agent\Backup\AgentSnapshot;
use Datto\Asset\Agent\Backup\AgentSnapshotService;
use Datto\Asset\Agent\Linux\LinuxAgent;
use Datto\Asset\Agent\OperatingSystem;
use Datto\Asset\Agent\VirtualizationSettings;
use Datto\Asset\Agent\Windows\WindowsAgent;
use Datto\Asset\Asset;
use Datto\Asset\AssetType;
use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Resource\Sleep;
use Datto\Common\Utility\Filesystem;
use Datto\Config\DeviceConfig;
use Datto\Config\FileConfigFactory;
use Datto\Config\Virtualization\VirtualDisks;
use Datto\Config\Virtualization\VirtualDisksFactory;
use Datto\Connection\ConnectionType;
use Datto\Connection\Libvirt\AbstractLibvirtConnection;
use Datto\Connection\Libvirt\EsxConnection;
use Datto\Connection\Libvirt\KvmConnection;
use Datto\Connection\Service\ConnectionService;
use Datto\Feature\FeatureService;
use Datto\Log\DeviceLoggerInterface;
use Datto\Log\LoggerFactory;
use Datto\Log\SanitizedException;
use Datto\Restore\AgentHir;
use Datto\Restore\AgentHirFactory;
use Datto\Restore\CloneSpec;
use Datto\Security\PasswordGenerator;
use Datto\Service\Security\FirewallService;
use Datto\System\Hardware;
use Datto\System\Inspection\Injector\InjectorAdapter;
use Datto\Util\RetryHandler;
use Datto\Utility\File\Lock;
use Datto\Utility\File\LockFactory;
use Datto\Utility\Network\IpHelper;
use Datto\Utility\Security\SecretScrubber;
use Datto\Virtualization\EsxVirtualMachine;
use Datto\Virtualization\EsxVmdkPrepTask;
use Datto\Virtualization\Hypervisor\Config\AbstractVmSettings;
use Datto\Virtualization\Libvirt\Domain\NetworkMode;
use Datto\Virtualization\Libvirt\VmDefinitionContext;
use Datto\Virtualization\Libvirt\VmDefinitionFactory;
use Datto\Virtualization\Libvirt\VmHostProperties;
use Datto\Virtualization\LocalVirtualizationUnsupportedException;
use Datto\Virtualization\RemoteHypervisorStorageFactory;
use Datto\Virtualization\VirtualMachine;
use Datto\Virtualization\VirtualMachineFactory;
use Datto\Virtualization\VmInfo;
use Datto\Websockify\WebsockifyService;
use Exception;
use RuntimeException;
use Throwable;
use Vmwarephp\Exception\Soap;

/**
 * Manages the lifecycle of virtual machines
 *
 * This class should have no knowledge of clones or restores.  It operates on existing agents and directories.
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class VirtualMachineService
{
    const VNC_PASSWORD_LENGTH = 8; // VNC has max pw length of 8

    const VNC_PORT_LOCK_PATH = '/dev/shm/vncPort.lock';
    const VNC_PORT_LOCK_WAIT_TIMEOUT = 10;

    /** @var FileConfigFactory */
    private $fileConfigFactory;

    /** @var LoggerFactory */
    private $loggerFactory;

    /** @var ConnectionService */
    private $connectionService;

    /** @var VirtualMachineFactory */
    private $virtualMachineFactory;

    /** @var Hardware */
    private $hardware;

    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var RemoteHypervisorStorageFactory */
    private $remoteStorageFactory;

    /** @var Filesystem */
    private $filesystem;

    /** @var IpHelper */
    private $ipHelper;

    /** @var VmDefinitionFactory */
    private $vmDefinitionFactory;

    /** @var WebsockifyService */
    private $websockifyService;

    /** @var AgentSnapshotService */
    private $agentSnapshotService;

    /** @var LockFactory */
    private $lockFactory;

    /** @var Lock */
    private $vncPortLock;

    /** @var RetryHandler */
    private $retryHandler;

    /** @var VirtualDisksFactory */
    private $virtualDisksFactory;

    private SecretScrubber $secretScrubber;

    private AgentHirFactory $agentHirFactory;

    public function __construct(
        FileConfigFactory $fileConfigFactory = null,
        LoggerFactory $loggerFactory = null,
        ConnectionService $connectionService = null,
        VirtualMachineFactory $virtualMachineFactory = null,
        RemoteHypervisorStorageFactory $remoteStorageFactory = null,
        Hardware $hardware = null,
        DeviceConfig $deviceConfig = null,
        Filesystem $filesystem = null,
        IpHelper $ipHelper = null,
        VmDefinitionFactory $vmDefinitionFactory = null,
        WebsockifyService $websockifyService = null,
        AgentSnapshotService $agentSnapshotService = null,
        LockFactory $lockFactory = null,
        RetryHandler $retryHandler = null,
        VirtualDisksFactory $virtualDisksFactory = null,
        SecretScrubber $secretScrubber = null,
        AgentHirFactory $agentHirFactory = null,
        FirewallService $firewallService = null
    ) {
        $this->filesystem = $filesystem ?? new Filesystem(new ProcessFactory());
        $this->fileConfigFactory = $fileConfigFactory ?? new FileConfigFactory($this->filesystem);
        $this->loggerFactory = $loggerFactory ?? new LoggerFactory();
        $this->connectionService = $connectionService ?? new ConnectionService();
        $this->virtualMachineFactory = $virtualMachineFactory
            ?? new VirtualMachineFactory(
                $this->filesystem,
                new Sleep(),
                $firewallService ?? AppKernel::getBootedInstance()->getContainer()->get(FirewallService::class)
            );
        $this->hardware = $hardware ?? new Hardware();
        $this->deviceConfig = $deviceConfig ?? new DeviceConfig();
        $this->remoteStorageFactory = $remoteStorageFactory ?? new RemoteHypervisorStorageFactory();
        $this->ipHelper = $ipHelper ?? new IpHelper(new ProcessFactory());
        $this->vmDefinitionFactory = $vmDefinitionFactory ?? new VmDefinitionFactory();
        $this->websockifyService = $websockifyService ?? new WebsockifyService();
        $this->agentSnapshotService = $agentSnapshotService ?? new AgentSnapshotService($this->filesystem);
        $this->lockFactory = $lockFactory ?? new LockFactory();
        $this->vncPortLock = $this->lockFactory->getProcessScopedLock(self::VNC_PORT_LOCK_PATH);
        $this->retryHandler = $retryHandler ?? new RetryHandler();
        $this->virtualDisksFactory = $virtualDisksFactory ?? new VirtualDisksFactory($this->agentSnapshotService);
        $this->secretScrubber = $secretScrubber ?? new SecretScrubber();
        $this->agentHirFactory = $agentHirFactory ?? new AgentHirFactory();
    }

    /**
     * Get the VM instance based on the metadata in the given storage directory.
     *
     * If the metadata does not exist, or the libvirt domain is not defined, null is returned.
     *
     * @param string $storageDir
     * @param DeviceLoggerInterface $logger
     * @return VirtualMachine|null
     */
    public function getVm(string $storageDir, DeviceLoggerInterface $logger)
    {
        $config = $this->fileConfigFactory->create($storageDir);

        if (!$config->loadRecord($vmInfo = new VmInfo())) {
            return null;
        }

        $uuid = $vmInfo->getUuid();
        if (empty($uuid)) {
            return null;
        }

        $connection = $this->connectionService->get($vmInfo->getConnectionName());
        if ($connection === null) {
            return null;
        }

        $libvirt = $connection->getLibvirt();

        if ($libvirt->getDomainObject($uuid) === false) {
            $logger->warning(
                'VMX0682 Get VM for storage directory has non-existent uuid.',
                ['storageDir' => $storageDir, 'uuid' => $uuid]
            );
            return null;
        }

        return $this->virtualMachineFactory->create($vmInfo, $storageDir, $connection, $logger);
    }

    /**
     * Create a VM instance for an Agent, and register it with libvirt.  After a successful call to
     * createVm, the vm instance can be retrieved in the future by calling getVm
     *
     * @param Agent $agent use Agent metadata to construct the vm
     * @param string $vmName the name of the VM
     * @param CloneSpec $cloneSpec the details of the clone
     * @param string $snapshotName the snapshot name from which we grab volume metadata
     * @param AbstractLibvirtConnection $connection the hypervisor connection where this vm will be run
     * @param AbstractVmSettings $vmSettings properties used to configure the vm
     * @param bool $useInjector true if the VM should be prepared for Lakitu injection
     * @param bool $hasNativeConfiguration override OS2 VM configuration with the one that was taken with backup, if any
     * @param bool $skipHirOnPendingReboot whether to allow skipping of HIR when a pending reboot is detected
     * @return VirtualMachine|null the instance representing the newly registered vm
     */
    public function createAgentVm(
        Agent $agent,
        string $vmName,
        CloneSpec $cloneSpec,
        string $snapshotName,
        AbstractLibvirtConnection $connection,
        AbstractVmSettings $vmSettings,
        bool $useInjector,
        bool $hasNativeConfiguration = false,
        bool $skipHirOnPendingReboot = false,
        InjectorAdapter $injectorAdapter = null
    ): ?VirtualMachine {
        $this->assertCanVirtualizeAgent($agent);

        $storageDir = $cloneSpec->getTargetMountpoint();

        $logger = $this->loggerFactory->getAsset($agent->getKeyName());

        if (!is_null($vm = $this->getVm($storageDir, $logger))) {
            $logger->warning(
                'VMX0683 createVm called for existing vm',
                ['agentKey' => $agent->getKeyName(), 'storageDir' => $storageDir]
            );
            return $vm;
        }

        try {
            if ($agent->isSupportedOperatingSystem()) {
                $agentHir = $this->agentHirFactory->create(
                    $agent->getKeyName(),
                    $storageDir,
                    $connection,
                    null,
                    null,
                    $skipHirOnPendingReboot
                );
                $hirResult = $agentHir->execute();
                if ($hirResult->getRebootPending() && $skipHirOnPendingReboot) {
                    $logger->info("VMX0851 HIR detected pending reboot with skipHirOnPendingReboot set to true");
                    return null;
                }
            }
            if ($useInjector && !is_null($injectorAdapter)) {
                try {
                    $injectorAdapter->injectLakitu($agent, $snapshotName, $cloneSpec);
                } catch (Exception $e) {
                    $logger->critical('VER0303 Attempt to inject Lakitu failed', ['exception' => $e]);
                }
            }

            $agentSnapshot = $this->agentSnapshotService->get($agent->getKeyName(), $snapshotName);
            $vmVirtualDisks = $this->virtualDisksFactory->getVirtualDisks($cloneSpec);
        } catch (Throwable $throwable) {
            throw new VirtualMachineCreationException(
                "Failed to define vm '$vmName' for '$storageDir'",
                $throwable->getCode(),
                $throwable
            );
        }

        if (!$agent instanceof WindowsAgent &&
            !$agent instanceof LinuxAgent &&
            !$agent instanceof AgentlessWindows &&
            !$agent instanceof AgentlessLinux &&
            !$agent instanceof GenericAgentless) {
            return null;
        }

        return $this->createVm(
            $agentSnapshot,
            $vmName,
            $storageDir,
            $connection,
            $vmSettings,
            $vmVirtualDisks,
            $agentSnapshot->getOperatingSystem(),
            $useInjector,
            $agent->getEncryption()->isEnabled(),
            $agent->getVirtualizationSettings()->getEnvironment() === VirtualizationSettings::ENVIRONMENT_MODERN,
            $hasNativeConfiguration,
            $logger
        );
    }

    /**
     * Create a VM instance using the given parameters, and register it with libvirt.  After a successful call to
     * createVm, the vm instance can be retrieved in the future by calling getVm
     *
     * @param AgentSnapshot $agentSnapshot
     * @param string $vmName
     * @param string $storageDir
     * @param AbstractLibvirtConnection $connection
     * @param AbstractVmSettings $vmSettings
     * @param VirtualDisks $vmVirtualDisks
     * @param OperatingSystem $vmOperatingSystem
     * @param bool $useInjector
     * @param bool $encrypted
     * @param bool $modernEnvironment
     * @param bool $hasNativeConfiguration
     * @param DeviceLoggerInterface $logger
     * @return VirtualMachine
     */
    private function createVm(
        AgentSnapshot $agentSnapshot,
        string $vmName,
        string $storageDir,
        AbstractLibvirtConnection $connection,
        AbstractVmSettings $vmSettings,
        VirtualDisks $vmVirtualDisks,
        OperatingSystem $vmOperatingSystem,
        bool $useInjector,
        bool $encrypted,
        bool $modernEnvironment,
        bool $hasNativeConfiguration,
        DeviceLoggerInterface $logger
    ): VirtualMachine {
        $storageConfig = $this->fileConfigFactory->create($storageDir);
        $vmInfo = new VmInfo($vmName, $connection->getName() ?? '', $connection->getType());

        if (!is_null($vm = $this->getVm($storageDir, $logger))) {
            $logger->warning(
                "VMX0664 createVm called for existing vm in storageDir.",
                ['storageDir' => $storageDir]
            );
            return $vm;
        }

        $this->assertLocalHwAssistedVirt($connection);
        $this->assertVmNameDoesNotExist($vmName, $connection);

        $logger->debug(
            "VMX0667 Creating VM using {$connection->getName()} connection, type {$connection->getType()->value()}"
        );

        try {
            // write vmInfo ASAP so that connection information is available.
            $storageConfig->saveRecord($vmInfo);

            // For remote hypervisors, offload local disks and get their remote "view".
            if ($connection->isRemote()) {
                $remoteStorage = $this->remoteStorageFactory->create($connection, $logger);
                $vmVirtualDisks = $remoteStorage->offload($vmName, $storageDir, $encrypted, $vmVirtualDisks);
            }

            if ($connection->isEsx()) {
                $vmdkPrepTask = new EsxVmdkPrepTask($vmOperatingSystem, $this->filesystem);
                $vmdkPrepTask->setVmdkAdapterType($storageDir);
            }

            //  Context used to build the Libvirt domain
            $context = new VmDefinitionContext(
                $agentSnapshot,
                $vmName,
                new VmHostProperties($connection->getType(), $connection->getLibvirt()->hostGetCpuModel() ?? ''),
                $vmSettings,
                $vmOperatingSystem,
                $vmVirtualDisks,
                $modernEnvironment,
                $useInjector,
                $hasNativeConfiguration
            );

            if ($connection->getType() === ConnectionType::LIBVIRT_ESX()) {
                /** @var EsxConnection $connection */
                $context->getVmHostProperties()->setHost($connection->getEsxHost());
            }

            if ($connection->getType() === ConnectionType::LIBVIRT_KVM()) {
                $this->prepareVmContextForKvm($storageDir, $context);
            }

            try {
                if ($connection->supportsVnc()) {
                    // establish lock to block multiple create calls trying to pull the same vnc port
                    $this->vncPortLock->assertExclusiveAllowWait(self::VNC_PORT_LOCK_WAIT_TIMEOUT);
                    $this->prepareVmContextForVnc($connection, $context, $logger);
                }

                $dom = $this->defineDomain($connection, $context, $logger);
            } finally {
                // domain has been defined, safe to unlock for other calls
                if ($connection->supportsVnc()) {
                    $this->vncPortLock->unlock();
                }
            }

            // save the uuid in the vminfo file for future lookup
            $vmInfo->setUuid($connection->getLibvirt()->domainGetUuid($dom));
            $storageConfig->saveRecord($vmInfo);

            if ($connection->getType() === ConnectionType::LIBVIRT_HV() && isset($remoteStorage)) {
                $deviceId = $this->deviceConfig->getDeviceId();
                $assetName = $context->getAgentSnapshot()->getKeyName();
                $snapshot = $context->getAgentSnapshot()->getEpoch();
                $iscsiTargetHost = $connection->getHost();
                $remoteStorage->addNotes($vmName, $assetName, $snapshot, $deviceId, $iscsiTargetHost);
            }

            return $this->virtualMachineFactory->create($vmInfo, $storageDir, $connection, $logger);
        } catch (Throwable $e) {
            // attempt to cleanup after failed vm creation
            $this->tryUndefineDomain($vmName, $connection, $logger);
            $this->tryTeardownRemoteStorage(
                $vmName,
                $storageDir,
                $encrypted,
                $connection,
                $logger
            );

            // clear vminfo file
            $storageConfig->clearRecord($vmInfo);

            $logger->error(
                'VMX0671 Failed to create vm for storageDir.',
                ['vmName' => $vmName, 'storageDir' => $storageDir, 'exception' => $e]
            );
            throw new VirtualMachineCreationException(
                $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Destroy the VM instance by unregistering it from libvirt, and cleaning up metadata
     *
     * @param Agent $agent
     * @param VirtualMachine $vm
     */
    public function destroyAgentVm(Agent $agent, VirtualMachine $vm)
    {
        $logger = $this->loggerFactory->getAsset($agent->getKeyName());
        $this->destroyVm($vm, $agent->getEncryption()->isEnabled(), $logger);

        // delete any vnc websockify tokens
        $this->websockifyService->removeTarget($this->websockifyService->formatAgentToken($agent->getKeyName()));
    }

    /**
     * @param VirtualMachine $vm
     * @param bool $encrypted
     * @param DeviceLoggerInterface $logger
     */
    public function destroyVm(VirtualMachine $vm, bool $encrypted, DeviceLoggerInterface $logger)
    {
        $connection = $vm->getConnection();
        $libvirt = $connection->getLibvirt();
        $uuid = $vm->getUuid();
        $vmName = $vm->getName();
        $dom = $libvirt->getDomainObject($uuid);

        $logger->debug('VMX0848 VM Destroy started.', ['vmName' => $vmName, 'uuid' => $uuid]);

        if ($dom === false) {
            $logger->warning(
                'VMX0680 Destroy Vm called for non-existent libvirt domain.',
                ['name' => $vmName, 'uuid' => $uuid]
            );
        } else {
            if ($vm instanceof EsxVirtualMachine && $vm->isEsxVmMigrated()) {
                $logger->warning(
                    "VMX0681 ESX VM has been migrated and will not be destroyed.",
                    ['vmName' => $vmName, 'uuid' => $uuid]
                );
            } else {
                // This retry is meant to resolve the issue when vm stop fails with the error "The attempted
                // operation cannot be performed in the current state (Powered on)".  The root cause is unknown, but
                // this is an unexpected response from VMWare, and can be resolved by trying again.
                $this->retryHandler->executeAllowRetry(
                    function () use ($vm, $libvirt, $dom, $vmName, $uuid) {
                        $vm->stop();
                        if ($libvirt->domainUndefine($dom) === false) {
                            throw new RuntimeException(
                                "Failure to undefine libvirt domain name '$vmName', uuid '$uuid'. "
                                . $libvirt->getLastError()
                            );
                        }
                    }
                );
            }
        }

        if ($connection->isRemote()) {
            $remoteStorage = $this->remoteStorageFactory->create($connection, $logger);

            // unfortunately EsxRemoteStorage needs to know if the agent is using encrypted datto files in order
            // to properly share them over NFS, otherwise this method would not need to know about $agent
            $remoteStorage->tearDown($vmName, $vm->getStorageDir(), $encrypted);
        }

        $storageConfig = $this->fileConfigFactory->create($vm->getStorageDir());
        $storageConfig->clearRecord(new VmInfo());
    }

    public function generateVmName(Asset $asset, string $suffix): string
    {
        return "{$asset->getPairName()}-{$asset->getUuid()}-$suffix";
    }

    /**
     * Try to undefine the given domain, log any errors
     *
     * @param string $vmName
     * @param AbstractLibvirtConnection $connection
     * @param DeviceLoggerInterface $logger
     */
    private function tryUndefineDomain(string $vmName, AbstractLibvirtConnection $connection, DeviceLoggerInterface $logger)
    {
        try {
            $libvirt = $connection->getLibvirt();
            $libvirt->domainUndefine($vmName);
        } catch (Throwable $e) {
            $logger->error('VMX0672 Error undefining libvirt domain.', ['vmName' => $vmName, 'exception' => $e]);
        }
    }

    /**
     * Try to clean up remote storage, log any errors
     *
     * @param string $vmName
     * @param string $storageDir
     * @param bool $encrypted
     * @param AbstractLibvirtConnection $connection
     * @param DeviceLoggerInterface $logger
     */
    private function tryTeardownRemoteStorage(
        string $vmName,
        string $storageDir,
        bool $encrypted,
        AbstractLibvirtConnection $connection,
        DeviceLoggerInterface $logger
    ) {
        try {
            if ($connection->isRemote()) {
                $remoteStorage = $this->remoteStorageFactory->create($connection, $logger);
                $remoteStorage->tearDown($vmName, $storageDir, $encrypted);
            }
        } catch (Throwable $e) {
            $logger->error(
                'VMX0673 Error tearing down remote hypervisor storage for libvirt domain.',
                ['vmName' => $vmName, 'connection' => $connection->getName(), 'exception' => $e]
            );
        }
    }

    private function prepareVmContextForKvm(string $storageDir, VmDefinitionContext $context)
    {
        // KVM Virt also needs the local CPU model
        $context->getVmHostProperties()->setLocalCpuModel($this->hardware->getCpuModel());

        // KVM Virt needs network bridge configured
        $nicMode = $context->getVmSettings()->getNetworkMode();
        if ($nicMode === NetworkMode::BRIDGED()) {
            $bridgeTargetName = $context->getVmSettings()->getBridgeTarget();
            $bridgeInterface = $this->ipHelper->getInterface($bridgeTargetName);

            // While any settings files created recently will have this as the bridge interface itself (e.g. br0),
            // settings files created a while ago will have one of the bridge slaves stored (e.g. eth0). We check
            // for this, and attempt to set the correct interface regardless.
            if ($bridgeInterface && $bridgeInterface->isBridgeMember()) {
                $bridgeInterface = $this->ipHelper->getInterface($bridgeInterface->getMemberOf());
            }

            // Make sure the bridge interface actually exists
            if ($bridgeInterface === null) {
                throw new RuntimeException('Could not prepare VM Context. Invalid Network Interface: ' . $bridgeTargetName);
            }

            $context->getVmHostProperties()->setNetworkBridgeInterfaceName($bridgeInterface->getName());
        }
    }

    /**
     * Prepare the vm definition context for use of VNC
     *
     * @param AbstractLibvirtConnection $connection
     * @param VmDefinitionContext $context
     * @param DeviceLoggerInterface $logger
     * @throws Exception
     */
    private function prepareVmContextForVnc(
        AbstractLibvirtConnection $connection,
        VmDefinitionContext $context,
        DeviceLoggerInterface $logger
    ): void {
        // ESX, KVM use VNC, prepare port
        $connection->getLibvirt()->setLogger($logger);
        $vncPort = $connection->getLibvirt()->hostGetFreeVncPort();
        $vncPassword = PasswordGenerator::generate(self::VNC_PASSWORD_LENGTH);
        $context->setVncParameters($vncPort, $vncPassword);

        if ($connection->getType() === ConnectionType::LIBVIRT_ESX()) {
            // For ESX we need to adjust the firewall rules
            // on the hypervisor to allow VNC through.
            try {
                if ($connection instanceof EsxConnection) {
                    /** @psalm-suppress UndefinedDocblockClass */
                    $connection->getEsxApi()->getFirewallSystem()->EnableRuleset(['id' => 'gdbserver']);
                }
            } catch (Soap $e) {
                $logger->error(
                    'VMX0674 Error occurred opening VNC ports on ESX connection.',
                    ['connection' => $connection->getName(), 'exception' => $e]
                );
            }
        }
    }

    /**
     * Throw an exception if this is a local virt and HW Assisted virt is not supported.
     *
     * @param AbstractLibvirtConnection $connection
     */
    private function assertLocalHwAssistedVirt(AbstractLibvirtConnection $connection)
    {
        if (($connection instanceof KvmConnection) && !$this->hardware->supportsHwAssistedVirt()) {
            $suggestedFix = $this->deviceConfig->has('isVirtual') ?
                'Ask your hypervisor administrator to expose hardware assisted virtualization to this virtual backup device and reboot it.' :
                'Check BIOS settings to determine if hardware assisted virtualization is enabled on this backup device.';
            $suggestedFix .= ' Alternatively, configure a hypervisor connection for screenshot purposes.';
            throw new LocalVirtualizationUnsupportedException($suggestedFix);
        }
    }

    /**
     * Throw an exception if a vm with the given name already exists.
     *
     * @param string $vmName
     * @param AbstractLibvirtConnection $connection
     */
    private function assertVmNameDoesNotExist(string $vmName, AbstractLibvirtConnection $connection)
    {
        $libvirt = $connection->getLibvirt();
        $dom = $libvirt->getDomainObject($vmName);

        if ($dom !== false) {
            throw new DuplicateVmException($vmName, $connection->getName());
        }
    }

    /**
     * Throw an exception if virtualization is not supported for the given agent type
     *
     * @param Agent $agent
     */
    private function assertCanVirtualizeAgent(Agent $agent)
    {
        $acceptableAgentTypes = [
            AssetType::WINDOWS_AGENT,
            AssetType::LINUX_AGENT,
            AssetType::AGENTLESS_WINDOWS,
            AssetType::AGENTLESS_LINUX,
            AssetType::AGENTLESS_GENERIC,
        ];

        if (!in_array($agent->getType(), $acceptableAgentTypes)) {
            throw new RuntimeException(
                "Agent '{$agent->getKeyName()}' cannot be virtualized, type '{$agent->getType()}' is not supported."
            );
        }
    }

    /**
     * Define the libvirt domain using the VmDefinitionContext
     *
     * @param AbstractLibvirtConnection $connection
     * @param VmDefinitionContext $context
     * @param DeviceLoggerInterface $logger
     * @return resource
     */
    private function defineDomain(
        AbstractLibvirtConnection $connection,
        VmDefinitionContext $context,
        DeviceLoggerInterface $logger
    ) {
        // generate the libvirt xml
        $vmDefinition = $this->vmDefinitionFactory->create($context);
        $vmDefinitionXml = (string)$vmDefinition;
        // copy and sanitize
        $sanitizedXml = $this->secretScrubber->scrubSecrets(
            [$context->getVncPassword()],
            trim(preg_replace('/\s+/', ' ', $vmDefinitionXml))
        );
        $logger->debug("VMX0665 Libvirt XML configuration.", ['sanitizedXml' => $sanitizedXml]);

        // attempt to define the domain using the xml
        // This extra try catch is to clear the stack in case of exception which can expose plaintext Sec
        try {
            $dom = $connection->getLibvirt()->domainDefine($vmDefinitionXml);
        } catch (Throwable $t) {
            throw new SanitizedException($t, [$context->getVncPassword()]);
        }
        if ($dom === false) {
            $error = $connection->getLibvirt()->getLastError();
            $message = 'Libvirt failed to define VM: ' . $error;
            $logger->error('VMX0666 Libvirt failed to define VM.', ['message' => $message]);
            throw new RuntimeException($message);
        }

        return $dom;
    }
}
