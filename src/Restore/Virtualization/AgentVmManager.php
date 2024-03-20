<?php

namespace Datto\Restore\Virtualization;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Encryption\TempAccessService;
use Datto\Asset\Agent\EncryptionService;
use Datto\Config\AgentConfigFactory;
use Datto\Config\Virtualization\Exception\MissingVirtualDisksException;
use Datto\Config\Virtualization\VirtualDisksFactory;
use Datto\Connection\Libvirt\AbstractRemoteConsoleInfo;
use Datto\Connection\Service\ConnectionService;
use Datto\Core\Network\DeviceAddress;
use Datto\Log\LoggerAwareTrait;
use Datto\RemoteWeb\RemoteWebService;
use Datto\Common\Utility\Filesystem;
use Datto\Connection\Libvirt\EsxConnection;
use Datto\Restore\CloneSpec;
use Datto\Restore\RestoreType;
use Datto\Rly\Client;
use Datto\Service\Rly\ConsoleTunnel;
use Datto\Verification\VerificationService;
use Datto\Virtualization\Hypervisor\Config\VmSettingsFactory;
use Datto\Virtualization\LocalVirtualMachine;
use Datto\Virtualization\VirtualMachine;
use Datto\Websockify\WebsockifyService;
use Psr\Log\LoggerAwareInterface;
use RuntimeException;

/**
 * Functions to interact with a restored agent vm (active or rescue)
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class AgentVmManager implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private AgentService $agentService;
    private VirtualMachineService $virtualMachineService;
    private WebsockifyService $websockifyService;
    private AgentConfigFactory $agentConfigFactory;
    private Filesystem $filesystem;
    private VerificationService $verificationService;
    private ConsoleTunnel $consoleTunnel;
    private DeviceAddress $deviceAddress;
    private VirtualDisksFactory $virtualDisksFactory;
    private Client $rlyClient;
    private EncryptionService $encryptionService;
    private TempAccessService $tempAccessService;
    private ConnectionService $connectionService;

    private string $cachedVmAgentKey;
    private ?VirtualMachine $cachedVm;

    public function __construct(
        AgentService $agentService,
        AgentConfigFactory $agentConfigFactory,
        VirtualMachineService $virtualMachineService,
        WebsockifyService $websockifyService,
        Filesystem $filesystem,
        VerificationService $verificationService,
        DeviceAddress $deviceAddress,
        ConsoleTunnel $consoleTunnel,
        VirtualDisksFactory $virtualDisksFactory,
        Client $rlyClient,
        EncryptionService $encryptionService,
        TempAccessService $tempAccessService,
        ConnectionService $connectionService
    ) {
        $this->virtualMachineService = $virtualMachineService;
        $this->agentService = $agentService;
        $this->websockifyService = $websockifyService;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->filesystem = $filesystem;
        $this->verificationService = $verificationService;
        $this->deviceAddress = $deviceAddress;
        $this->consoleTunnel = $consoleTunnel;
        $this->virtualDisksFactory = $virtualDisksFactory;
        $this->rlyClient = $rlyClient;
        $this->encryptionService = $encryptionService;
        $this->tempAccessService = $tempAccessService;
        $this->connectionService = $connectionService;

        $this->cachedVmAgentKey = '';
        $this->cachedVm = null;
    }

    /**
     * Returns true if the specified agent's VM exists, running or not. False otherwise.
     *
     * @param string $agentKey
     * @return bool
     */
    public function vmExists(string $agentKey): bool
    {
        $vm = $this->getVm($agentKey);
        return $vm !== null;
    }

    /**
     * Returns true if the specified agent's VM is running. False otherwise.
     *
     * @param string $agentKey
     * @return bool
     */
    public function isVmRunning(string $agentKey): bool
    {
        $vm = $this->getVm($agentKey);
        return $vm && $vm->isRunning();
    }

    /**
     * Update the settings for the agent vm
     */
    public function updateVmSettings(
        string $agentKey,
        int $cpuCount,
        int $memory,
        string $controller,
        string $nicType,
        string $videoController,
        string $networkController
    ) {
        $vm = $this->getVm($agentKey);

        // TODO: remove this when ESX updating is added
        if ($vm instanceof LocalVirtualMachine) {
            $settings = VmSettingsFactory::create($vm->getConnection()->getType());
            $agentConfig = $this->agentConfigFactory->create($agentKey);
            $agentConfig->loadRecord($settings);

            $vm->updateCpuCount($cpuCount)
                ->updateMemory($memory)
                ->updateStorageController($controller)
                ->updateNetwork($nicType, $networkController)
                ->updateVideo($videoController)
                ->updateVm();
        }
    }

    /**
     * Send CTRL+ALT+DEL to the agent vm
     *
     * @param string $agentKey
     */
    public function sendKeyCtlAltDel(string $agentKey)
    {
        $vm = $this->getVm($agentKey);
        static::assertVmNotNull($agentKey, $vm);
        $vm->sendKeyCodes(VirtualMachine::KEYS_CTRL_ALT_DEL);
    }

    /**
     * Send ALT+TAB to the agent vm
     *
     * @param string $agentKey
     */
    public function sendKeyAltTab(string $agentKey)
    {
        $vm = $this->getVm($agentKey);
        static::assertVmNotNull($agentKey, $vm);
        $vm->sendKeyCodes(VirtualMachine::KEYS_ALT_TAB);
    }

    /**
     * Creates a new remote console target for the specified VM.
     * This is applicable to any hypervisor which supports VNC or WMKS.
     *
     * @param string $agentKey
     * @return AbstractRemoteConsoleInfo
     *   Information about the remote target
     */
    public function createRemoteConsoleTarget(string $agentKey): AbstractRemoteConsoleInfo
    {
        $vm = $this->getVm($agentKey);
        static::assertVmNotNull($agentKey, $vm);

        $connectionInfo = $vm->getRemoteConsoleInfo();
        if ($connectionInfo === null) {
            throw new RuntimeException('Cannot create Remote Target for this agent.');
        }

        if ($connectionInfo->getType() === ConsoleType::VNC) {
            $token = $this->websockifyService->formatAgentToken($agentKey);
            $this->websockifyService->createTarget($token, $connectionInfo->getHost(), $connectionInfo->getPort() ?? 5900);

            $connectionInfo->setExtra('token', $token);
        } elseif ($connectionInfo->getType() === ConsoleType::WMKS && RemoteWebService::isRlyRequest()) {
            $info = $this->consoleTunnel->getConnectionInfo($vm->getName());
            if (!isset($info['consoleHost'])) {
                throw new RuntimeException('Failed to get WMKS connection');
            }
            $connectionInfo->setHost($info['consoleHost']);
            $connectionInfo->setPort((int) $info['consolePort']);
        }

        return $connectionInfo;
    }

    /**
     * Retrieve the VNC port and password
     *
     * @param string $agentName
     * @return array
     */
    public function getVncConnectionDetails(string $agentName): array
    {
        $virtualMachine = $this->getVm($agentName);
        self::assertVmNotNull($agentName, $virtualMachine);

        return [
            'port' => $virtualMachine->getVncPort(),
            'password' => $virtualMachine->getVncPassword()
        ];
    }

    /**
     * Get the root directory of the ZFS clone for a local virt
     *
     * @param Agent $agent
     * @return string
     */
    public function getStorageDir(Agent $agent): string
    {
        if ($agent->isRescueAgent()) {
            $cloneSpec = CloneSpec::fromRescueAgent($agent);
        } else {
            // snapshot is not technically necessary to derive mountpoint
            $cloneSpec = CloneSpec::fromAsset($agent, 0, RestoreType::ACTIVE_VIRT);
        }

        return $cloneSpec->getTargetMountpoint();
    }

    /**
     * Get information for display on the virtualization page
     *
     * @param string $agentKey
     * @param int $snapshot
     * @return array
     */
    public function getCompleteVmInfo(string $agentKey, int $snapshot): array
    {
        $vm = $this->getVm($agentKey);

        $agent = $this->agentService->get($agentKey);
        $agentConfig = $this->agentConfigFactory->create($agentKey);
        $this->logger->setAssetContext($agentKey);

        $vmMounted = true;
        $storageLocation = $this->getStorageDir($agent);
        if (!$this->filesystem->exists($storageLocation)) {
            $vmMounted = false;
        }

        $isAgentSealed = $this->encryptionService->isAgentSealed($agentKey);

        $isAgentTempAccessEnabled = $this->tempAccessService->isCryptTempAccessEnabled($agentKey);

        try {
            $disks = $this->virtualDisksFactory->getVirtualDisksCount($agentKey, $snapshot);
        } catch (MissingVirtualDisksException $ex) {
            // TODO: The getCompleteVmInfo method is called from UI polling code,
            //       where it's not synchronized well with start/destroy requests
            //       resulting in intermittent inconsistent state in the filesystem.
            //       It's safe to capture and ignore the exception here as this method
            //       is not critical to functioning virt-restores.
            $this->logger->error('AVM0001 Failed to get list of virtual disks used by the VM');
            $disks = 0;
        }

        $isRunning = false;
        $isControllerIDE = false;

        $consoleType = null;
        $connectionInfo = ['consoleHost' => null, 'consolePort' => null];
        if ($vm !== null) {
            $isRunning = $vm->isRunning();
            $connection = $vm->getConnection();
            $settings = VmSettingsFactory::create($connection->getType());
            $agentConfig->loadRecord($settings);
            $isControllerIDE = $settings->isIde();
            $consoleType = $connection->getRemoteConsoleType();
            $connectionInfo = $this->getConsoleConnectionInfo($vm);
        }

        if ($agentConfig->has('vmStatus')) {
            list($percent, $message, $error) = explode('|', $agentConfig->get('vmStatus'));
            $vmStarting = [
                'percent' => $percent,
                'message' => $message,
                'error' => empty($error) ? false : $error
            ];
        }

        return [
            'isVmMounted' => $vmMounted,
            'isVmRunning' => $isRunning,
            'isAgentSealed' => $isAgentSealed,
            'isAgentTempAccessEnabled' => $isAgentTempAccessEnabled,
            'isRemote' => RemoteWebService::isRlyRequest(),
            'vncPassword' => $vm ? $vm->getVncPassword() : null,
            'consoleType' => $consoleType,
            'consoleHost' => $connectionInfo['consoleHost'],
            'consolePort' => $connectionInfo['consolePort'],
            'screenshot' => $isRunning ? base64_encode($vm->getScreenshotBytes(150)) : null,
            'disks' => $disks,
            'ideExceedsVolumeLimit' => $disks > 4 && $isControllerIDE,
            'vmStarting' => $vmStarting ?? null,
            'isScreenshotting' => $this->verificationService->hasInProgressVerification($agent->getKeyName())
        ];
    }

    /**
     * Get the restore vm (active or rescue) for the given agent
     *
     * @param string $agentKey
     * @return VirtualMachine|null
     */
    public function getVm(string $agentKey)
    {
        if ($agentKey !== $this->cachedVmAgentKey) {
            $agent = $this->agentService->get($agentKey);
            $storageDir = $this->getStorageDir($agent);
            $this->logger->setAssetContext($agentKey);
            $this->cachedVmAgentKey = $agentKey;
            $this->cachedVm = $this->virtualMachineService->getVm($storageDir, $this->logger);
        }

        return $this->cachedVm;
    }

    /**
     * Check whether agent vm config has IDE as storage controller and there are more than 4 disks.
     * @param string $agentKey
     * @param int $snapshot
     * @return bool
     */
    public function ideExceedsVolumeLimit(string $agentKey, int $snapshot): bool
    {
        $connection = $this->connectionService->find($agentKey);
        $agentConfig = $this->agentConfigFactory->create($agentKey);
        $vmSettings = VmSettingsFactory::create($connection->getType());
        $agentConfig->loadRecord($vmSettings);
        return $vmSettings->isIde() && $this->virtualDisksFactory->getVirtualDisksCount($agentKey, $snapshot) >4;
    }

    /**
     * @param string $agentKeyName
     * @param $vm
     */
    private static function assertVmNotNull(string $agentKeyName, $vm)
    {
        if (is_null($vm)) {
            throw new RuntimeException("Could not find vm for agentKey '$agentKeyName'");
        }
    }

    /**
     * Get the remote console host that should be accessible to the user, depending on how they are connected
     * to the device
     *
     * @param VirtualMachine $vm
     * @return (string|null)[] Contains 2 elements, `consoleHost` and `consolePort`.
     *      These are both null if no connection is available (ex: wrong virt type, or a local connection)
     */
    private function getConsoleConnectionInfo(VirtualMachine $vm): array
    {
        if (RemoteWebService::isRlyRequest()) {
            // returns null for vncHost and vncPort value if no connection is available (ex: pending connection, or expired)
            return $this->consoleTunnel->getConnectionInfo($vm->getName());
        } else {
            $connection = $vm->getConnection();
            $connectionType = $connection->getRemoteConsoleType();
            if ($connectionType == ConsoleType::VNC) {
                $consoleHost = ($connection->isEsx() && $connection instanceof EsxConnection) ?
                    $connection->getEsxHost() : $this->deviceAddress->getLocalIp();
                $consolePort = $vm->getVncPort();
            } else {
                $consoleHost = null;
                $consolePort = null;
            }
            return ['consoleHost' => $consoleHost, 'consolePort' => $consolePort];
        }
    }
}
