<?php

namespace Datto\App\Controller\Api\V1\Device\Asset\Agent;

use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Encryption\AbstractPassphraseException;
use Datto\Asset\Agent\Linux\LinuxAgent;
use Datto\Asset\Agent\Windows\WindowsAgent;
use Datto\Asset\Asset;
use Datto\Asset\AssetType;
use Datto\Config\AgentConfigFactory;
use Datto\Connection\ConnectionType;
use Datto\Connection\Libvirt\AbstractLibvirtConnection;
use Datto\Connection\Libvirt\KvmConnection;
use Datto\Connection\Service\ConnectionService;
use Datto\Log\SanitizedException;
use Datto\RemoteWeb\RemoteWebService;
use Datto\Restore\Virtualization\ActiveVirtRestoreService;
use Datto\Restore\Virtualization\AgentVmManager;
use Datto\Restore\Virtualization\ChangeResourcesRequest;
use Datto\Service\Rly\ConsoleTunnel;
use Datto\Utility\Security\SecretString;
use Datto\Virtualization\Hypervisor\Config\VmSettingsFactory;
use Datto\Virtualization\VirtualMachine;
use Exception;
use Throwable;

/**
 * Virtualization JSON-RPC endpoint. Handles all VM actions and virtualization options
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 * @author John Fury Christ <jchrist@datto.com>
 * @author Jason Miesionczek <json@datto.com>
 */
class Virtualization extends AbstractAgentEndpoint
{
    private const PERMISSION_READ = 1;
    private const PERMISSION_WRITE = 2;

    private ActiveVirtRestoreService $virtRestoreService;
    private AgentConfigFactory $agentConfigFactory;
    private ConnectionService $connectionService;
    private AgentVmManager $agentVmManager;
    private ConsoleTunnel $consoleTunnel;

    public function __construct(
        ActiveVirtRestoreService $virtualizationRestoreService,
        AgentVmManager $agentVmManager,
        AgentConfigFactory $agentConfigFactory,
        AgentService $agentService,
        ConnectionService $connectionService,
        ConsoleTunnel $consoleTunnel
    ) {
        parent::__construct($agentService);
        $this->virtRestoreService = $virtualizationRestoreService;
        $this->agentVmManager = $agentVmManager;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->connectionService = $connectionService;
        $this->consoleTunnel = $consoleTunnel;
    }

    /**
     * Create a VM.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_VIRTUALIZATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     * @param string $agentName internal name of agent
     * @param int $snap restore point epoch
     * @param null|string $passphrase password for encrypted agent
     * @param null|string $connectionName name of hypervisor connection
     * @return bool
     */
    public function createVm(string $agentName, int $snap, $passphrase = null, $connectionName = null)
    {
        return $this->checkPermissionsAndStartVm($agentName, $snap, $passphrase, $connectionName, false, false);
    }

    /**
     * Starts a VM. Will create if it does not already exist.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_VIRTUALIZATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     * @param string $agentName internal name of agent
     * @param int $snap restore point epoch
     * @param null|string $passphrase password for encrypted agent
     * @param null|string $connectionName name of hypervisor connection
     * @param bool $hasNativeConfiguration
     * @return bool
     */
    public function startVm(
        string $agentName,
        int $snap,
        string $passphrase = null,
        string $connectionName = null,
        bool $hasNativeConfiguration = false
    ) {
        return $this->checkPermissionsAndStartVm(
            $agentName,
            $snap,
            $passphrase,
            $connectionName,
            $hasNativeConfiguration,
            true
        );
    }

    /**
     * Stop a running VM
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_VIRTUALIZATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     * @param string $agentName internal name of agent
     * @return bool
     */
    public function stopVm($agentName)
    {
        $this->tryCloseRemoteConsoleConnection($agentName);
        $this->virtRestoreService->stopVm($agentName);
        return true;
    }

    /**
     * Stops and unmounts a VM
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_VIRTUALIZATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_WRITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_DELETE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     * @param string $agentName internal name of agent
     * @param int $point restore point epoch
     * @return bool
     */
    public function destroyVm($agentName, $point)
    {
        $this->tryCloseRemoteConsoleConnection($agentName);
        $this->virtRestoreService->destroyVm($agentName, $point);
        return true;
    }

    /**
     * Restarts a running VM
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_VIRTUALIZATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     * @param string $agentName internal name of agent
     * @return bool
     */
    public function restartVm($agentName)
    {
        $this->virtRestoreService->restartVm($agentName);
        return true;
    }

    /**
     * Change Virtualization Settings
     *
     * FIXME: "nicType" is kind of confusing because it represents the networking mode, such as bridged, firewalled, etc
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_VIRTUALIZATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agent" = @Datto\App\Security\Constraints\AssetExists(type="agent"),
     *   "controller" = @Symfony\Component\Validator\Constraints\Regex(pattern="~^\w+$~"),
     *   "connectionName" = @Datto\App\Security\Constraints\ConnectionExists(),
     *   "videoController" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^\w+$~"),
     *   "networkController" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^\w+$~")
     * })
     * @param string $agent internal name of agent
     * @param int $cpuCount number of CPU cores
     * @param int $memory size of System RAM
     * @param string $nicType Network Options
     * @param string $connectionName
     * @param string $controller Storage Controller
     * @param string $videoController Video Controller
     * @param string $networkController Network Controller
     * @return bool
     */
    public function changeResources(
        string $agent,
        int $cpuCount,
        int $memory,
        string $nicType,
        string $connectionName,
        string $controller = '',
        string $videoController = '',
        string $networkController = ''
    ): bool {
        $this->checkFeaturesAndPermissions(self::PERMISSION_WRITE, $connectionName);

        $request = (new ChangeResourcesRequest())
            ->setCpuCount($cpuCount)
            ->setMemoryInMB($memory)
            ->setStorageController($controller)
            ->setNetworkMode($nicType)
            ->setVideoController($videoController)
            ->setNetworkController($networkController);

        $this->virtRestoreService->changeResources(
            $agent,
            $connectionName,
            $request
        );

        return true;
    }

    /**
     * Returns available RAM of a hypervisor host
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_READ")
     * @param null|string $connectionName name of hypervisor connection
     * @return array
     */
    public function getResources($connectionName = null)
    {
        $this->checkFeaturesSettings();

        if ($connectionName === KvmConnection::CONNECTION_NAME) {
            $this->checkPermissionsLocal(self::PERMISSION_READ);
        } else {
            $this->checkPermissionsHypervisor(self::PERMISSION_READ);
        }

        /** @var AbstractLibvirtConnection $connection */
        $connection = $this->connectionService->find($connectionName);
        $totalRam = $connection->getHostTotalMemoryMiB();
        $freeRam = $connection->getHostFreeMemoryMiB();
        $freeRam -= 200;
        $provisionedRam = $this->virtRestoreService->getAllActiveRestoresProvisionedRam($connection->getName());

        return [
            'freeRam' => $freeRam,
            'totalRam' => $totalRam,
            'provisionedRam' => $provisionedRam
        ];
    }

    /**
     * Returns complete information of a VM
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_VIRTUALIZATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Datto\App\Security\Constraints\AssetExists(type="agent"),
     *   "connectionName" = @Datto\App\Security\Constraints\ConnectionExists()
     * })
     * @param string $agentName name of agent
     * @param string $connectionName hypervisor connection name
     * @param int $snapshot restore point epoch
     * @return array
     */
    public function getCompleteVmInfo(string $agentName, string $connectionName, int $snapshot): array
    {
        $this->checkFeaturesAndPermissions(self::PERMISSION_READ, $connectionName);

        return $this->agentVmManager->getCompleteVmInfo($agentName, $snapshot);
    }

    /**
     * Runs the RLY command to open the tunnel for the remote console connection
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_VIRTUALIZATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentKey" = @Datto\App\Security\Constraints\AssetExists(type="agent"),
     *   "connectionName" = @Datto\App\Security\Constraints\ConnectionExists()
     * })
     * @param string $agentKey The agent identifier
     * @param bool $restrictIpToHost True to enforce restricting console access to the host IP
     * @param string $connectionName The connection name being used to virtualize this agent
     */
    public function openRemoteConsole(string $agentKey, string $connectionName, bool $restrictIpToHost = true): void
    {
        $this->checkFeaturesAndPermissions(self::PERMISSION_WRITE, $connectionName);

        $vm = $this->getVm($agentKey);
        $this->consoleTunnel->openRemoteConnection($vm, $restrictIpToHost);
    }

    /**
     * Runs the RLY command to close the tunnel for the remote console connection
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_VIRTUALIZATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Datto\App\Security\Constraints\AssetExists(type="agent"),
     *   "connectionName" = @Datto\App\Security\Constraints\ConnectionExists()
     * })
     *
     * @param string $agentKey The agent identifier
     * @param string $connectionName The connection name being used to virtualize this agent
     */
    public function closeRemoteConsole(string $agentKey, string $connectionName): void
    {
        $this->checkFeaturesAndPermissions(self::PERMISSION_WRITE, $connectionName);

        $vm = $this->getVm($agentKey);
        $this->consoleTunnel->closeRemoteConnection($vm);
    }

    /**
     * Returns true if the specified agent's VM is currently running.
     * Returns false otherwise.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_VIRTUALIZATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     * @param string $agentName internal name of agent
     * @return bool
     */
    public function isVmRunning($agentName)
    {
        return $this->agentVmManager->isVmRunning($agentName);
    }

    /**
     * Get RDP connection information for a given VM
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_VIRTUALIZATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     * @param string $agentName internal name of agent
     * @return array
     */
    public function getRemoteConnectionDetails(string $agentName): array
    {
        return [
            'vnc' => $this->agentVmManager->getVncConnectionDetails($agentName)
        ];
    }

    /**
     * Retrieves the core components virtualization environment for the given agent.
     * Windows 2000 and some Windows 2003 agents would use $environmentType = 1
     * while more modern operating systems would use $environmentType = 0
     *
     * This is a KVM only endpoint.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_LOCAL_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     * @param string $agentName name of an agent
     * @return int Environment type of MODERN (0) or LEGACY (1)
     */
    public function getEnvironment($agentName)
    {
        $this->checkFeaturesLocalSettings();

        $agent = $this->agentService->get($agentName);

        self::assertIsWindowsOrLinux($agent);

        /** @var WindowsAgent|LinuxAgent $agent */
        return $agent->getVirtualizationSettings()->getEnvironment();
    }

    /**
     * Sets the core components virtualization environment for the given agent.
     * Windows 2000 and some Windows 2003 agents would use $environmentType = 1
     * while more modern operating systems would use $environmentType = 0
     *
     * This is a KVM only endpoint.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_LOCAL_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Datto\App\Security\Constraints\AssetExists(type="agent"),
     *   "environmentType" = @Symfony\Component\Validator\Constraints\Range(min = 0, max = 1)
     * })
     * @param string $agentName name of an agent
     * @param $environmentType
     */
    public function setEnvironment($agentName, $environmentType): void
    {
        $this->checkFeaturesLocalSettings();

        $agent = $this->agentService->get($agentName);

        self::assertIsWindowsOrLinux($agent);

        /** @var WindowsAgent|LinuxAgent $agent */
        $agent->getVirtualizationSettings()->setEnvironment($environmentType);
        $this->agentService->save($agent);
    }

    /**
     * Get Default ESX Virtualization Storage Controller (ESX only)
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_HYPERVISOR_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     * @param string $agentName agent name
     * @return string default ESX Storage Controller
     */
    public function getEsxStorageController($agentName)
    {
        $this->checkFeaturesHypervisorSettings();

        $agent = $this->agentService->get($agentName);
        $agentConfig = $this->agentConfigFactory->create($agentName);

        self::assertIsWindowsOrLinux($agent);

        $settings = VmSettingsFactory::create(ConnectionType::LIBVIRT_ESX());
        $agentConfig->loadRecord($settings);

        return $settings->getStorageController();
    }

    /**
     * Get Default KVM Virtualization Storage Controller (KVM only)
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_LOCAL_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     * @param string $agentName agent name
     * @return string default KVM Storage Controller
     */
    public function getKvmStorageController($agentName)
    {
        $this->checkFeaturesLocalSettings();

        $agent = $this->agentService->get($agentName);
        $agentConfig = $this->agentConfigFactory->create($agentName);

        self::assertIsWindowsOrLinux($agent);

        $settings = VmSettingsFactory::create(ConnectionType::LIBVIRT_KVM());
        $agentConfig->loadRecord($settings);

        return $settings->getStorageController();
    }

    /**
     * Set Default Virtualization Storage Controllers (KVM and ESX)
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     * @param string $agentName agent name
     * @param array $storageController new storage controller
     *                   kvm => storage controller
     *                   esx => storage controller
     */
    public function setStorageControllers($agentName, $storageController): void
    {
        $this->checkFeaturesSettings();

        $agent = $this->agentService->get($agentName);
        $agentConfig = $this->agentConfigFactory->create($agentName);

        self::assertIsWindowsOrLinux($agent);

        if (array_key_exists('kvm', $storageController)) {
            $this->checkFeaturesLocalSettings();
            $this->checkPermissionsLocal(self::PERMISSION_WRITE);

            $settings = VmSettingsFactory::create(ConnectionType::LIBVIRT_KVM());
            $agentConfig->loadRecord($settings);
            $settings->setStorageController($storageController['kvm']);
            $agentConfig->saveRecord($settings);
        }

        if (array_key_exists('esx', $storageController)) {
            $this->checkFeaturesHypervisorSettings();
            $this->checkPermissionsHypervisor(self::PERMISSION_WRITE);

            $settings = VmSettingsFactory::create(ConnectionType::LIBVIRT_ESX());
            $agentConfig->loadRecord($settings);
            $settings->setStorageController($storageController['esx']);
            $agentConfig->saveRecord($settings);
        }
    }

    /**
     * Set network controllers (KVM and ESX)
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_WRITE")
     * @param string $agentName agent name
     * @param array $networkController new Network controller
     *                   kvm => Network controller
     *                   esx => Network controller
     */
    public function setNetworkControllers($agentName, $networkController): void
    {
        $this->checkFeaturesSettings();

        $agent = $this->agentService->get($agentName);
        $agentConfig = $this->agentConfigFactory->create($agentName);

        self::assertIsWindowsOrLinux($agent);

        if (array_key_exists('kvm', $networkController)) {
            $this->checkFeaturesLocalSettings();
            $this->checkPermissionsLocal(self::PERMISSION_WRITE);

            $settings = VmSettingsFactory::create(ConnectionType::LIBVIRT_KVM());
            $agentConfig->loadRecord($settings);
            $settings->setNetworkController($networkController['kvm']);
            $agentConfig->saveRecord($settings);
        }

        if (array_key_exists('esx', $networkController)) {
            $this->checkFeaturesHypervisorSettings();
            $this->checkPermissionsHypervisor(self::PERMISSION_WRITE);

            $settings = VmSettingsFactory::create(ConnectionType::LIBVIRT_ESX());
            $agentConfig->loadRecord($settings);
            $settings->setNetworkController($networkController['esx']);
            $agentConfig->saveRecord($settings);
        }
    }

    /**
     * Set default video controllers (KVM only)
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_LOCAL_WRITE")
     * @param string $agentName agent name
     * @param array $videoController new video controller
     *
     */
    public function setVideoControllers($agentName, $videoController): void
    {
        $this->checkFeaturesLocalSettings();

        $agent = $this->agentService->get($agentName);
        $agentConfig = $this->agentConfigFactory->create($agentName);

        self::assertIsWindowsOrLinux($agent);

        if (array_key_exists('kvm', $videoController)) {
            $settings = VmSettingsFactory::create(ConnectionType::LIBVIRT_KVM());
            $agentConfig->loadRecord($settings);
            $settings->setVideoController($videoController['kvm']);
            $agentConfig->saveRecord($settings);
        }
    }

    /**
     * Sends CTRL+ALT+DEL to VM.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_VIRTUALIZATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_WRITE")
     * @param string $agentKey
     * @return bool
     */
    public function sendCtrlAltDel(string $agentKey): bool
    {
        $this->agentVmManager->sendKeyCtlAltDel($agentKey);
        return true;
    }

    /**
     * Sends ALT+TAB to VM
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_VIRTUALIZATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_WRITE")
     * @param string $agentKey
     * @return bool
     */
    public function sendAltTab(string $agentKey): bool
    {
        $this->agentVmManager->sendKeyAltTab($agentKey);
        return true;
    }

    /**
     * Check supported agent types
     *
     * @param $agent
     */
    private static function assertIsWindowsOrLinux(Asset $agent): void
    {
        $isWindowsOrLinux = ($agent->isType(AssetType::WINDOWS_AGENT) ||
            $agent->isType(AssetType::AGENTLESS_WINDOWS) ||
            $agent->isType(AssetType::LINUX_AGENT) ||
            $agent->isType(AssetType::AGENTLESS_LINUX));

        if (!$isWindowsOrLinux) {
            throw new Exception(
                "Only Windows or Linux agents are supported for this operation" . get_class($agent)
            );
        }
    }

    /**
     * Check features and permissions based on a given connection and
     * desired read/write access.
     *
     * @param int $permission
     * @param string|null $connectionName
     */
    private function checkFeaturesAndPermissions(int $permission, string $connectionName = null): void
    {
        if ($connectionName === KvmConnection::CONNECTION_NAME) {
            $this->checkFeaturesLocal();
            $this->checkPermissionsLocal($permission);
        } else {
            $this->checkFeaturesHypervisor();
            $this->checkPermissionsHypervisor($permission);
        }
    }

    /**
     * Check if the local virtualization feature is enabled.
     */
    private function checkFeaturesLocal(): void
    {
        $this->denyAccessUnlessGranted('FEATURE_RESTORE_VIRTUALIZATION_LOCAL');
    }

    /**
     * Check if the logged in user has the proper local virtualization permission.
     *
     * @param int $permission
     */
    private function checkPermissionsLocal(int $permission): void
    {
        if ($permission === self::PERMISSION_READ) {
            $this->denyAccessUnlessGranted('PERMISSION_RESTORE_VIRTUALIZATION_LOCAL_READ');
        } else {
            $this->denyAccessUnlessGranted('PERMISSION_RESTORE_VIRTUALIZATION_LOCAL_WRITE');
        }
    }

    /**
     * Check if the hypervisor virtualization feature is enabled.
     */
    private function checkFeaturesHypervisor(): void
    {
        $this->denyAccessUnlessGranted('FEATURE_RESTORE_VIRTUALIZATION_HYPERVISOR');
    }

    /**
     * Check if the logged in user has the proper hypervisor virtualization permission.
     *
     * @param int $permission
     */
    private function checkPermissionsHypervisor(int $permission): void
    {
        if ($permission === self::PERMISSION_READ) {
            $this->denyAccessUnlessGranted('PERMISSION_RESTORE_VIRTUALIZATION_HYPERVISOR_READ');
        } else {
            $this->denyAccessUnlessGranted('PERMISSION_RESTORE_VIRTUALIZATION_HYPERVISOR_WRITE');
        }
    }

    /**
     * Check if the virtualization settings can be modified.
     */
    private function checkFeaturesSettings(): void
    {
        if (!$this->isGranted('FEATURE_VERIFICATIONS') &&
            !$this->isGranted('FEATURE_RESTORE_VIRTUALIZATION')
        ) {
            throw $this->createAccessDeniedException();
        }
    }

    /**
     * Check if the local virtualization settings can be modified.
     */
    private function checkFeaturesLocalSettings(): void
    {
        if (!$this->isGranted('FEATURE_VERIFICATIONS') &&
            !$this->isGranted('FEATURE_RESTORE_VIRTUALIZATION_LOCAL')
        ) {
            throw $this->createAccessDeniedException();
        }
    }

    /**
     * Check if the hypervisor virtualization settings can be modified.
     */
    private function checkFeaturesHypervisorSettings(): void
    {
        if (!$this->isGranted('FEATURE_VERIFICATIONS') &&
            !$this->isGranted('FEATURE_RESTORE_VIRTUALIZATION_HYPERVISOR')
        ) {
            throw $this->createAccessDeniedException();
        }
    }

    /**
     * Check permissions and start a VM.
     *
     * @param string $agentName
     * @param int $snap
     * @param string|null $passphrase
     * @param string|null $connectionName
     * @param bool $hasNativeConfiguration
     * @param bool $start
     * @return bool
     */
    private function checkPermissionsAndStartVm(
        string $agentName,
        int $snap,
        string $passphrase = null,
        string $connectionName = null,
        bool $hasNativeConfiguration = false,
        bool $start = true
    ) {
        try {
            $passphrase = $passphrase ? new SecretString($passphrase) : null;

            // if connection not specified, use the default
            if (empty($connectionName)) {
                $connectionName = $this->connectionService->find()->getName();
            }
            $this->checkFeaturesAndPermissions(self::PERMISSION_WRITE, $connectionName);

            $this->virtRestoreService->startVm(
                $agentName,
                $snap,
                $connectionName,
                $passphrase,
                $hasNativeConfiguration,
                $start
            );

            return true;
        } catch (AbstractPassphraseException $e) {
            // exceptions are shown directly to the user for this endpoint. Make sure "invalid passphrase" isn't garbled by SanitizedException
            throw $e;
        } catch (Throwable $e) {
            throw new SanitizedException($e, [$passphrase]);
        }
    }

    /**
     * Tries to close any open remote VNC connection.
     * This does not generate any exceptions if an error occurs.
     *
     * @param string $agentKey
     */
    private function tryCloseRemoteConsoleConnection($agentKey): void
    {
        if (RemoteWebService::isRlyRequest()) {
            try {
                $this->consoleTunnel->closeRemoteConnection($this->getVm($agentKey));
            } catch (Throwable $e) {
            }
        }
    }

    /**
     * Get the Restore VM (active or rescue) for the given agent and
     * throw an exception if the VM does not exist.
     *
     * @param string $agentKey
     * @return VirtualMachine
     */
    private function getVm(string $agentKey): VirtualMachine
    {
        $vm = $this->agentVmManager->getVm($agentKey);
        if (is_null($vm)) {
            throw new Exception("Restore VM not found.");
        }
        return $vm;
    }
}
