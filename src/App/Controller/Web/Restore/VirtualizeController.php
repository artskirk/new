<?php

namespace Datto\App\Controller\Web\Restore;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Backup\AgentSnapshotService;
use Datto\Asset\Agent\EncryptionService;
use Datto\Asset\Agent\Rescue\RescueAgentService;
use Datto\Backup\VmConfigurationBackupService;
use Datto\Common\Resource\Filesystem;
use Datto\Config\AgentConfigFactory;
use Datto\Config\DeviceConfig;
use Datto\Config\Virtualization\VirtualDisksFactory;
use Datto\Connection\Libvirt\EsxConnection;
use Datto\Connection\Libvirt\KvmConnection;
use Datto\Connection\Service\ConnectionService;
use Datto\Core\Network\DeviceAddress;
use Datto\RemoteWeb\RemoteWebService;
use Datto\Restore\Virtualization\ActiveVirtRestoreService;
use Datto\Restore\Virtualization\AgentVmManager;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Datto\Service\Rly\ConsoleTunnel;
use Datto\Virtualization\Hypervisor\Config\VmSettingsFactory;
use Datto\Virtualization\Providers\NetworkOptions;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * To be used for Restore>Virtualization pages in Siris UI
 *
 * @author Dash Winterson <dash@datto.com>
 */
class VirtualizeController extends AbstractBaseController
{
    private const RESERVED_RAM_MB = 3072;

    private EncryptionService $encryption;
    private AgentService $agentService;
    private ConnectionService $connectionService;
    private DeviceConfig $config;
    private RescueAgentService $rescueAgentService;
    private AgentConfigFactory $agentConfigFactory;
    private AgentVmManager $agentVmManager;
    private VmConfigurationBackupService $vmConfigurationBackupService;
    private DeviceAddress $deviceAddress;
    private AgentSnapshotService $agentSnapshotService;
    private NetworkOptions $networkOptions;
    private ConsoleTunnel $consoleTunnel;
    private VirtualDisksFactory $virtualDisksFactory;
    private ActiveVirtRestoreService $activeVirtRestoreService;

    public function __construct(
        NetworkService $networkService,
        EncryptionService $encryption,
        AgentService $agentService,
        ConnectionService $connectionService,
        DeviceConfig $config,
        RescueAgentService $rescueAgentService,
        AgentConfigFactory $agentConfigFactory,
        AgentVmManager $agentVmManager,
        VmConfigurationBackupService $vmConfigurationBackupService,
        AgentSnapshotService $agentSnapshotService,
        NetworkOptions $networkOptions,
        DeviceAddress $deviceAddress,
        ConsoleTunnel $consoleTunnel,
        VirtualDisksFactory $virtualDisksFactory,
        ActiveVirtRestoreService $activeVirtRestoreService,
        Filesystem $filesystem,
        ClfService $clfService
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->encryption = $encryption;
        $this->agentService = $agentService;
        $this->connectionService = $connectionService;
        $this->config = $config;
        $this->rescueAgentService = $rescueAgentService;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->agentVmManager = $agentVmManager;
        $this->vmConfigurationBackupService = $vmConfigurationBackupService;
        $this->agentSnapshotService = $agentSnapshotService;
        $this->networkOptions = $networkOptions;
        $this->deviceAddress = $deviceAddress;
        $this->consoleTunnel = $consoleTunnel;
        $this->virtualDisksFactory = $virtualDisksFactory;
        $this->activeVirtRestoreService = $activeVirtRestoreService;
    }

    /**
     * Controller action to render initial virt restore page.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_VIRTUALIZATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_READ")
     *
     * @param string $agentKey The agent identifier
     * @param int $point The snapshot timestamp
     * @param string $hypervisor (Optional) The hypervisor connection identifier. If not provided local virtualization will be used
     * @return Response
     */
    public function configureAction($agentKey, $point, $hypervisor = '')
    {
        if ($hypervisor) {
            $this->denyAccessUnlessGranted('FEATURE_RESTORE_VIRTUALIZATION_HYPERVISOR');
            $this->denyAccessUnlessGranted('PERMISSION_RESTORE_VIRTUALIZATION_HYPERVISOR_READ');

            $connection = $this->connectionService->get($hypervisor);
        } else {
            $this->denyAccessUnlessGranted('FEATURE_RESTORE_VIRTUALIZATION_LOCAL');
            $this->denyAccessUnlessGranted('PERMISSION_RESTORE_VIRTUALIZATION_LOCAL_READ');

            $connection = $this->connectionService->getLocal();
        }

        $agentConfig = $this->agentConfigFactory->create($agentKey);
        $point = (int)$point;
        $libvirt = $connection->getLibvirt();
        $encrypted = $this->encryption->isEncrypted($agentKey);
        $agent = $this->agentService->get($agentKey);
        $hostname = $agent->getPairName();

        $isAgentPaused = $agent->getLocal()->isPaused();
        $isRescueAgent = $agent->isRescueAgent();
        $isReplicated = $agent->getOriginDevice()->isReplicated();

        if ($isRescueAgent && $this->rescueAgentService->isArchived($agentKey)) {
            return $this->redirectToRoute('restore');
        }

        $vmSettings = VmSettingsFactory::create($connection->getType());
        $agentConfig->loadRecord($vmSettings);

        if ($connection->isLocal()) {
            $type = 'Local';
            $hypervisor = $connection->getName();
            $useKvm = !$this->config->has('isVirtual');
        } else {
            $type = $connection->getType()->toDisplayName();
            $useKvm = false;
        }

        $canChangeVideoController = !$useKvm || $this->agentService->canChangeVideoController($agentKey);

        $nicModes = $this->networkOptions->getSupportedNetworkModesWithDescriptions($connection);

        $agentSnapshot = $this->agentSnapshotService->get($agent->getKeyName(), $point);

        $vmVirtualDisks = $this->virtualDisksFactory->getVirtualDisksCount($agentKey, $point);
        $hasVmxFile = $this->vmConfigurationBackupService->hasVmConfiguration($agent, $point);

        $vm = $this->agentVmManager->getVm($agentKey);

        $consoleType = !is_null($vm) ? $vm->getConnection()->getRemoteConsoleType() : null;

        if (RemoteWebService::isRlyRequest()) {
            if (!is_null($vm)) {
                $tunnelInfo = $this->consoleTunnel->getConnectionInfo($vm->getName());
                $consoleHost = $tunnelInfo['consoleHost'];
            } else {
                $consoleHost = null;
            }
        } elseif ($connection->isEsx() && $connection instanceof EsxConnection) {
            $consoleHost = $connection->getEsxHost();
        } else {
            $consoleHost = $this->deviceAddress->getLocalIp();
        }

        $this->connectionService->find(KvmConnection::CONNECTION_NAME);
        $totalRam = $connection->getHostTotalMemoryMiB();
        $freeRam = $connection->getHostFreeMemoryMiB();
        $provisionedRam = $this->activeVirtRestoreService->getAllActiveRestoresProvisionedRam($connection->getName());
        $vmInfo = $this->agentVmManager->getCompleteVmInfo($agentKey, $point);

        return $this->render(
            'Restore/Virtualize/configure.html.twig',
            [
                'agent' => $agentKey,
                'hostname' => $hostname,
                'point' => $point,
                'hypervisor' => $hypervisor,
                'type' => $type,
                'isEncrypted' => $encrypted,
                'isAgentPaused' => $isAgentPaused,
                'isRescueAgent' => $isRescueAgent,
                'isReplicated' => $isReplicated,
                'isMounted' => $vmInfo['isVmMounted'],
                'hasVmxFile' => $hasVmxFile,
                'isLocal' => $connection->isLocal(),
                'cpuCount' => $libvirt->hostGetNodeCpuCount(),
                'os' => $agentSnapshot->getOperatingSystem()->getName() . ' ' . $agentSnapshot->getOperatingSystem()->getVersion(),
                'arch' => $agentSnapshot->getOperatingSystem()->getBits(),
                'fqdn' => $agent->getFullyQualifiedDomainName(),
                'numVolumes' => $vmVirtualDisks,
                'consoleType' => $consoleType,
                'consoleHost' => $consoleHost,
                'vmNetworkMode' => $vmSettings->getNetworkMode(),
                'vmCpuCount' => $vmSettings->getCpuCount(),
                'vmMemory' => $vmSettings->getRam(),
                'freeRam' => $freeRam,
                'totalRam' => $totalRam,
                'provisionedRam' => $provisionedRam,
                'reservedRam' => self::RESERVED_RAM_MB,
                'storageControllers' => $vmSettings->getSupportedStorageControllers(),
                'storageController' => $vmSettings->getStorageController(),
                'networkControllers' => $vmSettings->getSupportedNetworkControllers(),
                'networkController' => $vmSettings->getNetworkController(),
                'networkModes' => $nicModes,
                'networkMode' => $vmSettings->getNetworkModeRaw(),
                'canChangeVideoController' => $canChangeVideoController,
                'videoControllers' => $vmSettings->getSupportedVideoControllers(),
                'videoController' => $vmSettings->getVideoController(),
                'supportsNativeConfiguration' => $hasVmxFile && $type === 'ESX',
                'isRlyClient' => RemoteWebService::isRlyRequest()
            ]
        );
    }

    /**
     * Controller action to start a noVNC or WMKS session into the VM's console.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_VIRTUALIZATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_READ")
     *
     * FIXME This should check in the controller if hypervisor / local virt is allowed!
     *
     * @param string $agent The agent identifier
     * @return Response
     */
    public function consoleAction(string $agent): Response
    {
        $agentObj = $this->agentService->get($agent);
        $agentDisplayName = $agentObj->getDisplayName();

        $consoleInfo = $this->agentVmManager->createRemoteConsoleTarget($agent);

        $arr = $consoleInfo->getValues();
        $arr['agentDisplayName'] = $agentDisplayName;

        return $this->render(
            "Restore/Virtualize/console.{$consoleInfo->getType()}.html.twig",
            $arr
        );
    }

    /**
     * Controller action to serve the original VM configuration file
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_VIRTUALIZATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_READ")
     *
     * @param string $agentKey The agent identifier
     * @param int $point The snapshot timestamp
     * @return Response
     */
    public function downloadVmConfiguration(string $agentKey, int $point)
    {
        $agent = $this->agentService->get($agentKey);

        if (!$this->vmConfigurationBackupService->hasVmConfiguration($agent, $point)) {
            throw new NotFoundHttpException("VM Configuration file not found");
        }

        return $this->file(
            $this->vmConfigurationBackupService->getVmConfigurationPath($agent, $point),
            $agent->getDisplayName() . '.vmx'
        );
    }
}
