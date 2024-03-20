<?php

namespace Datto\App\Controller\Web\Configure;

use Datto\Common\Resource\Filesystem;
use Datto\Config\ContactInfoRecord;
use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Backup\SecondaryReplicationService;
use Datto\Cloud\SpeedSyncMaintenanceService;
use Datto\Config\DeviceConfig;
use Datto\Config\Login\LocalLoginService;
use Datto\Feature\FeatureService;
use Datto\License\AgentLimit;
use Datto\Log\RemoteLogSettings;
use Datto\Ipmi\IpmiService;
use Datto\Resource\DateTimeService;
use Datto\Samba\SambaManager;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Datto\System\MaintenanceModeService;
use Datto\System\PowerManager;
use Datto\RemoteWeb\RemoteWebService;
use Datto\System\Update\UpdateWindowService;
use Datto\Upgrade\ChannelService;
use Datto\User\Roles;
use Datto\User\UserService;
use Datto\User\WebUser;
use Datto\User\WebUserService;
use Datto\Util\DateTimeZoneService;
use Exception;

/**
 * Controller for global device settings page.
 *
 * @author Bartosz Neuman <bneuman@datto.com>
 */
class DeviceController extends AbstractBaseController
{
    private DeviceConfig $deviceConfig;
    private PowerManager $powerManager;
    private WebUser $webUser;
    private WebUserService $webUserService;
    private FeatureService $featureService;
    private SecondaryReplicationService $replicationService;
    private MaintenanceModeService $maintenanceModeService;
    private RemoteWebService $remoteWebService;
    private SpeedSyncMaintenanceService $speedSyncMaintenanceService;
    private IpmiService $ipmiService;
    private ?UpdateWindowService $updateWindowService;
    private ChannelService $channelService;
    private UserService $userService;
    private DateTimeZoneService $dateTimeZoneService;
    private RemoteLogSettings $remoteLogSettings;
    private LocalLoginService $localLoginService;
    private AgentLimit $agentLimit;
    private SambaManager $sambaManager;

    public function __construct(
        NetworkService $networkService,
        WebUser $webUser,
        WebUserService $webUserService,
        DeviceConfig $deviceConfig,
        PowerManager $powerManager,
        FeatureService $featureService,
        SecondaryReplicationService $replicationService,
        MaintenanceModeService $maintenanceModeService,
        RemoteWebService $remoteWebService,
        SpeedSyncMaintenanceService $speedSyncMaintenanceService,
        IpmiService $ipmiService,
        UpdateWindowService $updateWindowService,
        ChannelService $channelService,
        UserService $userService,
        DateTimeZoneService $dateTimeZoneService,
        RemoteLogSettings $remoteLogSettings,
        LocalLoginService $localLoginService,
        AgentLimit $agentLimit,
        SambaManager $sambaManager,
        Filesystem $filesystem,
        ClfService $clfService
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->webUser = $webUser;
        $this->webUserService = $webUserService;
        $this->deviceConfig = $deviceConfig;
        $this->powerManager = $powerManager;
        $this->featureService = $featureService;
        $this->replicationService = $replicationService;
        $this->maintenanceModeService = $maintenanceModeService;
        $this->remoteWebService = $remoteWebService;
        $this->speedSyncMaintenanceService = $speedSyncMaintenanceService;
        $this->ipmiService = $ipmiService;
        $this->updateWindowService = $updateWindowService;
        $this->channelService = $channelService;
        $this->userService = $userService;
        $this->dateTimeZoneService = $dateTimeZoneService;
        $this->remoteLogSettings = $remoteLogSettings;
        $this->localLoginService = $localLoginService;
        $this->agentLimit = $agentLimit;
        $this->sambaManager = $sambaManager;
    }

    /**
     * Returns global device settings page.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_DEVICE_SETTINGS_WRITE")
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function indexAction()
    {
        $schedule = $this->powerManager->getRebootSchedule();

        if ($this->deviceConfig->has('maxBackups')) {
            $concurrentBackups = intval($this->deviceConfig->get('maxBackups'));
        } else {
            $concurrentBackups = 2;
        }

        try {
            $isSecondaryReplicationAvailable = $this->featureService->isSupported(FeatureService::FEATURE_OFFSITE_SECONDARY);
            $isSecondaryReplicationEnabled = $isSecondaryReplicationAvailable && $this->replicationService->isEnabled();
        } catch (Exception $e) {
            $isSecondaryReplicationAvailable = false;
            $isSecondaryReplicationEnabled = false;
        }

        try {
            $offsiteSyncEnabled = $this->speedSyncMaintenanceService->isEnabled();
            $offsiteSyncPaused = $this->speedSyncMaintenanceService->isDevicePaused();
            $offsiteSyncPausedUntil = $this->speedSyncMaintenanceService->getResumeTime();
            $offsitePausedIndefinitely = $this->speedSyncMaintenanceService->isDelayIndefinite();
        } catch (Exception $e) {
            // An exception is thrown if speedsync is disabled. Hide the offsite sync settings if that's the case.
            $offsiteSyncEnabled = false;
            $offsiteSyncPaused = true;
            $offsiteSyncPausedUntil = 0;
            $offsitePausedIndefinitely = true;
        }

        $updateWindow = $this->updateWindowService->getWindow();

        $contactInfoRecord = new ContactInfoRecord();
        $this->deviceConfig->loadRecord($contactInfoRecord);
        $deviceAlertsEmail = $contactInfoRecord->getEmail();

        $settings = [
            'rebootSchedule' => $schedule ? $schedule->getRebootAt() : 0,
            'rebootTimezone' => date('T'),
            'isScreenshotEnabled' => !$this->deviceConfig->has('disableScreenshots'),
            'isRemoteLoggingEnabled' => $this->deviceConfig->has('cefActive'),
            'remoteLoggingServers' => $this->remoteLogSettings->getServers(),
            'isAdvancedAlertingEnabled' => $this->deviceConfig->has('useAdvancedAlerting'),
            'isSecondaryReplicationAvailable' => $isSecondaryReplicationAvailable,
            'isSecondaryReplicationEnabled' => $isSecondaryReplicationEnabled,
            'concurrentBackups' => $concurrentBackups,
            'maxConcurrentBackups' => $this->agentLimit->getConcurrentBackupLimit(),
            'backupOffset' => intval($this->deviceConfig->get('backupOffset')),
            'mountedRestoreAlertPeriod' => intval($this->deviceConfig->get('defaultTP')),
            'maintenanceModeEnabled' => $this->maintenanceModeService->isEnabled(),
            'maintenanceModeEndTime' => $this->maintenanceModeService->getEndTime(),
            'remoteWebForceLogin' => $this->remoteWebService->getForceLogin(),
            'isOffsiteSyncEnabled' => $offsiteSyncEnabled,
            'isOffsiteSyncPaused' => $offsiteSyncPaused,
            'offsiteSyncPausedUntil' => $offsiteSyncPausedUntil,
            'isOffsiteSyncPausedIndefinitely' => $offsitePausedIndefinitely,
            'offsiteSyncPausedMaxHours' => SpeedSyncMaintenanceService::MAX_DELAY_IN_SECONDS / DateTimeService::SECONDS_PER_HOUR,
            'updateWindowStartHour' => $updateWindow->getStartHour(),
            'updateWindowEndHour' => $updateWindow->getEndHour(),
            'deviceAlertsEmail' => $deviceAlertsEmail,
            'isLocalLoginEnabled' => $this->localLoginService->isEnabled(),
            'smbProtocolMinimumVersion' => $this->sambaManager->getServerProtocolMinimumVersion(),
            'smbSigningRequired' => $this->sambaManager->isSigningRequired(),
            'isClfEnabled' => $this->clfService->isClfEnabled(),
        ];

        $settings = array_merge(
            $settings,
            $this->getDisplayBlockSettings(),
            $this->getWatchdogSettings(),
            $this->getUpgradeChannelSettings(),
            $this->getUserSettings(),
            $this->getTimezoneSettings()
        );

        return $this->render('Configure/Device/index.html.twig', $settings);
    }
    /**
     * @return array<string,bool>
     */
    private function getDisplayBlockSettings(): array
    {
        $displayBlockSettings = [
            'displayAdvancedAlerting' => $this->featureService->isSupported(FeatureService::FEATURE_ALERTING_ADVANCED),
            'displayScreenshots' => $this->featureService->isSupported(FeatureService::FEATURE_VERIFICATIONS),
            'displayWatchdog' => $this->featureService->isSupported(FeatureService::FEATURE_WATCHDOG),
            'displayMountedRestoreAlert' => $this->featureService->isSupported(FeatureService::FEATURE_ALERTING_MOUNTED_RESTORE),
            'displayConcurrentBackups' => $this->featureService->isSupported(FeatureService::FEATURE_CONCURRENT_BACKUPS),
            'displayBackupOffset' => $this->featureService->isSupported(FeatureService::FEATURE_BACKUP_OFFSET),
            'displayUpdateWindow' => $this->featureService->isSupported(FeatureService::FEATURE_UPGRADES),
            'displayUpgradeChannel' => $this->featureService->isSupported(FeatureService::FEATURE_UPGRADES),
            'displayRebootScheduled' => $this->featureService->isSupported(FeatureService::FEATURE_POWER_MANAGEMENT),
            'displayRebootNow' => $this->featureService->isSupported(FeatureService::FEATURE_POWER_MANAGEMENT),
            'displaySMBSettings' => $this->featureService->isSupported(FeatureService::FEATURE_SERVICE_SAMBA)
        ];
        return $displayBlockSettings;
    }
    /**
     * @return array|array<string,bool>
     */
    private function getWatchdogSettings(): array
    {
        $watchdogSettings = [];
        if ($this->featureService->isSupported(FeatureService::FEATURE_WATCHDOG)) {
            $watchdogSettings['isWatchdogEnabled'] = $this->ipmiService->isWatchdogEnabled();
        }
        return $watchdogSettings;
    }
    /**
     * @return array<string,mixed>
     */
    private function getUpgradeChannelSettings(): array
    {
        $channels = $this->channelService->getChannels();
        return [
            'upgradeChannelSelected' => $channels->getSelected(),
            'upgradeChannelsAvailable' => $channels->getAll(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function getUserSettings(): array
    {
        $users = $this->userService->getAll();
        foreach ($users as &$user) {
            $userName = $user['username'];
            $roles = $this->getAvailableUserRoles($userName);
            $user['roles'] = $roles;
        }

        return [
            'loggedInUser' => $this->webUser->getUserName(),
            'userList' => $users
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function getAvailableUserRoles(string $userName): array
    {
        $userRoles = array_flip($this->webUserService->getRoles($userName));
        $isSoleAdministrator = $this->webUserService->isSoleAdministrator($userName);
        $roles = [];
        foreach (Roles::SUPPORTED_USER_ROLES as $role) {
            $isBasicAccessRole = $role === Roles::ROLE_BASIC_ACCESS;
            $isSoleAdministratorRole =
                $isSoleAdministrator &&
                $role === Roles::ROLE_ADMIN;

            $roles[] = [
                'name' => $role,
                'enabled' => isset($userRoles[$role]),
                'unchangeable' => $isBasicAccessRole || $isSoleAdministratorRole
            ];
        }

        return $roles;
    }

    /**
     * @return array<string,mixed>
     */
    private function getTimezoneSettings(): array
    {
        return [
            'timeZoneCurrent' => $this->dateTimeZoneService->getTimeZone(),
            'timeZonesAvailable' => $this->dateTimeZoneService->readTimeZones(),
        ];
    }
}
