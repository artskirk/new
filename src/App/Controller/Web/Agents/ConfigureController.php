<?php

namespace Datto\App\Controller\Web\Agents;

use Datto\Alert\AlertManager;
use Datto\App\Controller\Web\Assets\AbstractAssetConfigureController;
use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentConnectivityService;
use Datto\Asset\Agent\Agentless\AgentlessSystem;
use Datto\Asset\Agent\Agentless\Linux\LinuxAgent as AgentlessLinuxAgent;
use Datto\Asset\Agent\Agentless\Windows\WindowsAgent as AgentlessWindowsAgent;
use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\ArchiveService;
use Datto\Asset\Agent\DiffMergeService;
use Datto\Asset\Agent\Encryption\TempAccessService;
use Datto\Asset\Agent\EncryptionService;
use Datto\Asset\Agent\Linux\LinuxAgent;
use Datto\Asset\Agent\Mac\MacAgent;
use Datto\Asset\Agent\VmxBackupSettings;
use Datto\Asset\Agent\VolumesService;
use Datto\Asset\Agent\Windows\WindowsAgent;
use Datto\Asset\Asset;
use Datto\Asset\AssetType;
use Datto\Asset\VerificationSchedule;
use Datto\Billing\Service as BillingService;
use Datto\Cloud\SpeedSyncMaintenanceService;
use Datto\Common\Resource\Filesystem;
use Datto\Config\AgentConfigFactory;
use Datto\Config\DeviceConfig;
use Datto\Config\DeviceState;
use Datto\Connection\ConnectionType;
use Datto\Feature\FeatureService;
use Datto\License\AgentLimit;
use Datto\Malware\RansomwareService;
use Datto\Samba\UserService;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Datto\System\Hardware;
use Datto\Verification\Application\ApplicationScriptManager;
use Datto\Verification\VerificationService;
use Datto\Virtualization\Hypervisor\Config\VmSettingsFactory;
use Datto\Virtualization\HypervisorType;
use Datto\User\WebUser;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles requests to the agent config page
 */
class ConfigureController extends AbstractAssetConfigureController
{
    const SWAP_MOUNTPOINT = '<swap>';

    private AgentLimit $agentLimit;
    private RansomwareService $ransomwareService;
    private ArchiveService $archiveService;
    private FeatureService $featureService;
    private VerificationService $verificationService;
    private TempAccessService $tempAccessService;
    private AgentConfigFactory $agentConfigFactory;
    private Hardware $hardware;
    private AlertManager $alertManager;
    private AgentConnectivityService $agentConnectivityService;
    private VolumesService $volumesService;
    private DiffMergeService $diffMergeService;
    private EncryptionService $encryptionService;
    private WebUser $user;

    public function __construct(
        AgentService $service,
        TempAccessService $tempAccessService,
        DeviceConfig $deviceConfig,
        BillingService $billingService,
        AgentLimit $agentLimit,
        RansomwareService $ransomwareService,
        ArchiveService $archiveService,
        FeatureService $featureService,
        VerificationService $verificationService,
        AgentConfigFactory $agentConfigFactory,
        Hardware $hardware,
        AlertManager $alertManager,
        AgentConnectivityService $agentConnectivityService,
        DeviceState $deviceState,
        VolumesService $volumesService,
        UserService $userService,
        SpeedSyncMaintenanceService $speedSyncMaintenanceService,
        DiffMergeService $diffMergeService,
        EncryptionService $encryptionService,
        NetworkService $networkService,
        WebUser $webUser,
        Filesystem $filesystem,
        ClfService $clfService
    ) {
        parent::__construct(
            $networkService,
            $service,
            $deviceState,
            $deviceConfig,
            $userService,
            $billingService,
            $speedSyncMaintenanceService,
            $filesystem,
            $clfService
        );

        $this->user = $webUser;
        $this->tempAccessService = $tempAccessService;
        $this->agentLimit = $agentLimit;
        $this->ransomwareService = $ransomwareService;
        $this->archiveService = $archiveService;
        $this->featureService = $featureService;
        $this->verificationService = $verificationService;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->hardware = $hardware;
        $this->alertManager = $alertManager;
        $this->agentConnectivityService = $agentConnectivityService;
        $this->volumesService = $volumesService;
        $this->diffMergeService = $diffMergeService;
        $this->encryptionService = $encryptionService;
    }

    /**
     * Renders the agent configuration/settings page
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     *
     * @param string $agentName Name of the agent
     * @return Response
     */
    public function indexAction(string $agentName): Response
    {
        /** @var Agent $agent */
        $agent = $this->service->get($agentName);

        if ($this->encryptionService->isAgentSealed($agentName)) {
            return $this->redirect($this->generateUrl('access_denied_encryption'));
        }

        return $this->render(
            'Agents/Configure/index.html.twig',
            array_merge_recursive(
                $this->getCommonParameters($agent),
                $this->getAgentReportingParameters($agent),
                $this->getVolumeParameters($agent),
                $this->getPrePostScriptParameters($agent),
                $this->getVssExclusionParameters($agent),
                $this->getVirtualizationParameters($agent),
                $this->getAgentAndDeviceSettings($agent),
                $this->getAlertNotificationState($agentName),
                $this->getTempAccessParameters($agent),
                $this->getScreenshotScheduleParameters($agent),
                $this->getVmConfigBackupParameters($agent),
                $this->getSnapshotTimeoutParameters($agent),
                $this->getSambaParameters($agent),
                $this->getDoDiffMergeParameters($agent),
                $this->getRansomwareParameters($agent),
                $this->getIntegrityCheckParameters($agent),
                $this->getApplicationParameters($agent),
                $this->getServiceParameters($agent),
                $this->getUserParameters()
            )
        );
    }

    /**
     * @param Agent $agent
     * @return array
     */
    protected function getApplicationParameters(Agent $agent): array
    {
        $verificationSettings = $agent->getScreenshotVerification();

        return [
            'verification' => [
                'applications' => [
                    ApplicationScriptManager::APPLICATION_MSSQL =>
                        $verificationSettings->isApplicationExpected(ApplicationScriptManager::APPLICATION_MSSQL),
                    ApplicationScriptManager::APPLICATION_DHCP =>
                        $verificationSettings->isApplicationExpected(ApplicationScriptManager::APPLICATION_DHCP),
                    ApplicationScriptManager::APPLICATION_AD_DOMAIN =>
                        $verificationSettings->isApplicationExpected(ApplicationScriptManager::APPLICATION_AD_DOMAIN),
                    ApplicationScriptManager::APPLICATION_DNS =>
                        $verificationSettings->isApplicationExpected(ApplicationScriptManager::APPLICATION_DNS),
                ]
            ]
        ];
    }

    /**
     * @param Agent $agent
     * @return array
     */
    protected function getServiceParameters(Agent $agent): array
    {
        $availableServices = [];
        $expectedServices = [];
        $backupRequired = false;

        $supported = $this->featureService->isSupported(FeatureService::FEATURE_SERVICE_VERIFICATIONS, null, $agent);
        if ($supported) {
            $expectedServices = $this->verificationService->getExpectedServices($agent->getKeyName());
            $availableServices = $this->verificationService->getNotExpectedServices($agent->getKeyName());
            $backupRequired = $this->verificationService->isBackupRequired($agent->getKeyName());
        }

        return [
            'verification' => [
                'service' => [
                    'supported' => $supported,
                    'expectedServices' => $expectedServices,
                    'availableServices' => $availableServices,
                    'backupRequired' => $backupRequired
                ]
            ]
        ];
    }

    /**
     * @param Agent $agent
     * @return array
     */
    protected function getVerificationScripts(Agent $agent): array
    {
        return array(
            'verificationScripts' => $agent->getScriptSettings()->getScripts()
        );
    }

    /**
     * @param Asset $asset
     * @return array
     */
    protected function getCommonParameters(Asset $asset): array
    {
        $parameters = parent::getCommonParameters($asset);
        $parameters['agent'] = $parameters['asset'];
        unset($parameters['asset']);
        return $parameters;
    }

    protected function getUserParameters(): array
    {
        return [
            'user' => ['isRemoteUser' => $this->user->isRemoteWebUser()]
        ];
    }

    /**
     * @param Asset $asset
     * @return array
     */
    protected function getNameAndTypeParameters(Asset $asset): array
    {
        /** @var Agent $asset */
        return array(
            'applyAllType' => AssetType::AGENT,
            'asset' => array(
                'name' => $asset->getName(),
                'hostName' => $asset->getHostname(),
                'keyName' => $asset->getKeyName(),
                'displayName' => $asset->getDisplayName()
            )
        );
    }

    /**
     * @param Agent $agent
     * @return array
     */
    protected function getVirtualizationParameters(Agent $agent): array
    {
        $agentConfig = $this->agentConfigFactory->create($agent->getKeyName());

        $virtualizationSettings = array(
            'kvmStorageController' => '',
            'esxStorageController' => '',
            'kvmNetworkController' => '',
            'esxNetworkController' => '',
            'kvmVideoController' => '',
            'kvmShowVideoController' => '',
            'environment' => ''
        );
        $environment = '';

        $isWindowsOrLinux = ($agent->isType(AssetType::WINDOWS_AGENT) ||
            $agent->isType(AssetType::AGENTLESS_WINDOWS) ||
            $agent->isType(AssetType::LINUX_AGENT) ||
            $agent->isType(AssetType::AGENTLESS_LINUX));

        if ($isWindowsOrLinux) {
            $useKvm = !$this->deviceConfig->has('isVirtual');
            if ($useKvm) {
                $settings = VmSettingsFactory::create(ConnectionType::LIBVIRT_KVM());
                $agentConfig->loadRecord($settings);
                $virtualizationSettings['kvmStorageController'] = $settings->getStorageController();
                $virtualizationSettings['kvmNetworkController'] = $settings->getNetworkController();
                $virtualizationSettings['kvmVideoController'] = $settings->getVideoController();

                /** @var AgentService $agentService */
                $agentService = $this->service;
                $virtualizationSettings['kvmShowVideoController'] =
                    $agentService->canChangeVideoController($agent->getKeyName());
            }

            $useEsx = (!$this->deviceConfig->has("isAlto")) && !$this->deviceConfig->has("isAltoXL");
            if ($useEsx) {
                $settings = VmSettingsFactory::create(ConnectionType::LIBVIRT_ESX());
                $agentConfig->loadRecord($settings);
                $virtualizationSettings['esxStorageController'] = $settings->getStorageController();
                $virtualizationSettings['esxNetworkController'] = $settings->getNetworkController();
            }

            /** @var WindowsAgent|LinuxAgent|AgentlessWindowsAgent|AgentlessLinuxAgent $agent */
            $environment = $agent->getVirtualizationSettings()->getEnvironment();
        }

        return array(
            'agent' => array(
                'virtualization' => array(
                    'kvmStorageController' => $virtualizationSettings['kvmStorageController'],
                    'esxStorageController' => $virtualizationSettings['esxStorageController'],
                    'kvmNetworkController' => $virtualizationSettings['kvmNetworkController'],
                    'esxNetworkController' => $virtualizationSettings['esxNetworkController'],
                    'kvmShowVideoController' => $virtualizationSettings['kvmShowVideoController'],
                    'kvmVideoController' => $virtualizationSettings['kvmVideoController'],
                    'environment' => $environment
                )
            )
        );
    }

    /**
     * @param Agent $agent
     * @return array
     */
    protected function getSambaParameters(Agent $agent): array
    {
        $authorizedUser = $agent->getShareAuth()->getUser();
        return array(
            'agent' => array(
                'authorizedUser' => $authorizedUser
            )
        );
    }

    /**
     * @param Agent $agent
     * @return array
     */
    protected function getDoDiffMergeParameters(Agent $agent): array
    {
        /** @var Agent $agent */
        return array(
            'agent' => array(
                'supportsVolumeDiffMerge' => $agent->isVolumeDiffMergeSupported(),
                'hasForcedDiffMerge' =>
                    $this->diffMergeService->getDiffMergeSettings($agent->getKeyName())->isAnyVolume(),
                'maxBadScreenshotCount' =>
                    $this->diffMergeService->getMaxBadScreenshotCount($agent->getKeyName())
            )
        );
    }

    /**
     * @param Agent $agent
     * @return array
     */
    protected function getRansomwareParameters(Agent $agent): array
    {
        return array(
            'agent' => array(
                'ransomware' => array(
                    'supported' => $this->ransomwareService->isTestable($agent),
                    'enabled' => $agent->getLocal()->isRansomwareCheckEnabled(),
                )
            )
        );
    }

    /**
     * @param Agent $agent
     * @return array
     */
    protected function getIntegrityCheckParameters(Agent $agent): array
    {
        return [
            'agent' => [
                'integrity' => [
                    'enabled' => $agent->getLocal()->isIntegrityCheckEnabled(),
                ]
            ]
        ];
    }

    /**
     * @param Agent $agent
     * @return array
     */
    private function getAgentReportingParameters(Agent $agent): array
    {
        return array(
            'agent' => array(
                'reporting' => array(
                    'weekly' => array(
                        'emails' => $agent->getEmailAddresses()->getWeekly(),
                    ),
                    'screenshotFailed' => array(
                        'emails' => $agent->getEmailAddresses()->getScreenshotFailed()
                    ),
                    'screenshotSuccess' => array(
                        'emails' => $agent->getEmailAddresses()->getScreenshotSuccess()
                    )
                )
            )
        );
    }

    /**
     * @param Agent $agent
     * @return array
     */
    private function getVmConfigBackupParameters(Agent $agent): array
    {
        if (!$agent instanceof WindowsAgent) {
            return array();
        }
        /** @var VmxBackupSettings $vmxSettings */
        $vmxSettings = $agent->getVmxBackupSettings();

        return array(
            'agent' => array(
                'vmConfig' => array(
                    'enableBackup' => $vmxSettings->isEnabled(),
                )
            )
        );
    }

    /**
     * @param Agent $agent
     * @return array
     */
    private function getVolumeParameters(Agent $agent): array
    {
        $volumeParameters = $this->volumesService->getVolumeParameters($agent);

        return array(
            'agent' => array(
                'suppressVolumeControl' => !$agent->isSupportedOperatingSystem(),
                'volumes' => $volumeParameters,
                'isAgentless' => $agent->getPlatform()->isAgentless(),
            )
        );
    }

    /**
     * @param Agent $agent
     * @return array
     */
    private function getVssExclusionParameters(Agent $agent): array
    {
        if (!($agent instanceof WindowsAgent)) {
            return [];
        }

        $transformedWriters = [];
        foreach ($agent->getVssWriterSettings()->getAll() as $writer) {
            $transformedWriters[] = [
                'id' => $writer->getId(),
                'name' => $writer->getDisplayName(),
                'excluded' => $writer->isExcluded()
            ];
        }

        return [
            'agent' => [
                'vssWriters' => $transformedWriters
            ]
        ];
    }

    /**
     * @param Agent $agent
     * @return array
     */
    private function getPrePostScriptParameters(Agent $agent): array
    {
        $prePostScriptsSupported = $agent instanceof LinuxAgent || $agent instanceof MacAgent;
        if (!$prePostScriptsSupported) {
            return array();
        }

        /** @var LinuxAgent|MacAgent $agent */
        $volumes = $agent->getPrePostScripts()->getVolumes();
        $volumesParameters = array();

        foreach ($volumes as $mountPoint => $volume) {
            if ($mountPoint === self::SWAP_MOUNTPOINT) {
                // swap volume is hidden
                continue;
            }
            $scripts = $volume->getScripts();
            $scriptsParameters = array();
            foreach ($scripts as $script) {
                $scriptsParameters[] = array(
                    'scriptName' => $script->getName(),
                    'displayName' => $script->getDisplayName(),
                    'enabled' => $script->isEnabled(),
                );
            }
            $volumesParameters[] = array(
                'volumeName' => $volume->getVolumeName(),
                'blockDevice' => $volume->getBlockDevice(),
                'scripts' => $scriptsParameters
            );
        }

        return array(
            'agent' => array(
                'quiescingScriptVolumes' => $volumesParameters
            )
        );
    }

    /**
     * @param Agent $agent
     * @return array
     */
    private function getSnapshotTimeoutParameters(Agent $agent): array
    {
        return array(
            'agent' => array(
                'snapshotTimeout' => $agent->getLocal()->getTimeout()
            )
        );
    }

    /**
     * @param Agent $agent
     * @return array
     */
    private function getScreenshotScheduleParameters(Agent $agent): array
    {
        $schedule = $agent->getVerificationSchedule();
        $verification = $agent->getScreenshotVerification();
        $offsiteSupported = $this->featureService->isSupported(FeatureService::FEATURE_OFFSITE);
        $supported = $this->featureService->isSupported(FeatureService::FEATURE_VERIFICATIONS, null, $agent);

        return [
            'agent' => [
                'screenshot' => [
                    'supported' => $supported,
                    'scheduleType' => $schedule->getScheduleOption(),
                    'schedule' => $schedule->getCustomSchedule()->getSchedule(),
                    'threshold' => $verification->getErrorTime(),
                    'waitTime' => $verification->getWaitTime(),
                    'offsiteSupported' => $offsiteSupported,
                    'options' => [
                        'never' => VerificationSchedule::NEVER,
                        'first' => VerificationSchedule::FIRST_POINT,
                        'last' => VerificationSchedule::LAST_POINT,
                        'custom' => VerificationSchedule::CUSTOM_SCHEDULE,
                        'offsite' => VerificationSchedule::OFFSITE
                    ]
                ]
            ]
        ];
    }

    /**
     * @param Agent $agent
     * @return array
     */
    private function getTempAccessParameters(Agent $agent): array
    {
        $enabled = $this->tempAccessService->isCryptTempAccessEnabled($agent->getKeyName());
        $disableTime = $this->tempAccessService->getCryptTempAccessTime($agent->getKeyName());
        return array(
            'agent' => array(
                'tempAccess' => array(
                    'enabled' => $enabled,
                    'disableTime' => $disableTime
                )
            )
        );
    }

    /**
     * @param Agent $agent
     * @return array
     */
    private function getAgentAndDeviceSettings(Agent $agent): array
    {
        $platform = $agent->getPlatform();
        $isAgentBased = !$agent instanceof AgentlessSystem;
        $isEncrypted = $agent->getEncryption()->isEnabled();
        $isVMwarePlatform = $this->hardware->detectHypervisor() === HypervisorType::VMWARE();

        // VMX backup is only possible on agents that are running on VMware
        $GLOBALS['keyBase'] = Agent::KEYBASE;
        $isReachable = true;
        if ($isAgentBased) {
            // TODO: Move this expensive call out of the page load path. Instead, show the config section all the
            //   time and retrieve the data asynchronously to reduce page load time.
            $state = $this->agentConnectivityService->checkExistingAgentConnectivity($agent);
            $isReachable = $state === AgentConnectivityService::STATE_AGENT_ACTIVE;
        }
        $isRescueAgent = $agent->isRescueAgent();
        $isWindowsMachine = $agent->getType() === AssetType::WINDOWS_AGENT;
        $runningOnVMWare = false;
        if ($isWindowsMachine && !$isRescueAgent) {
            /** @var WindowsAgent $windowsAgent */
            $windowsAgent = $agent;
            $runningOnVMWare = $windowsAgent->isVirtualMachine();
        }
        // end section

        return [
            'agent' => [
                'type' => $agent->getType(),
                'isAgentBased' => $isAgentBased,
                'isSupportedOperatingSystem' => $agent->isSupportedOperatingSystem(),
                'isRescueAgent' => $isRescueAgent,
                'isArchived' => $this->archiveService->isArchived($agent->getKeyName()),
                'isEncrypted' => $isEncrypted,
                'runningOnVMWare' => $runningOnVMWare,
                'isReachable' => $isReachable,
                'isShadowSnap' =>  $platform === AgentPlatform::SHADOWSNAP(),
                'agentDriverType' => $platform->value()
            ],
            'device' => [
                'isSirisLite' => $this->deviceConfig->isSirisLite(),
                'isVMwarePlatform' => $isVMwarePlatform,
            ]
        ];
    }

    /**
     * @param $agentName
     * @return array
     */
    protected function getAlertNotificationState($agentName): array
    {
        // todo: refactor away from using shared multi-agent key /datto/config/agentAlertStatus
        $alertsEnabled = !$this->alertManager->isAssetSuppressed($agentName);

        return array(
            'agent' => array(
                'isAlertNotificationEnabled' => $alertsEnabled
            )
        );
    }

    /**
     * @return array
     */
    protected function getLicenseParameters(): array
    {
        return array(
            'device' => array(
                'license' => array(
                    'canUnpause' => $this->agentLimit->canUnpauseAgent(),
                    'canUnpauseAll' => $this->agentLimit->canUnpauseAllAgents()
                )
            )
        );
    }
}
