<?php

namespace Datto\System\Migration\Device\Stage;

use Datto\Cloud\OffsiteSyncScheduleService;
use Datto\Cloud\SpeedSyncMaintenanceService;
use Datto\Config\DeviceConfig;
use Datto\Config\LocalConfig;
use Datto\Configuration\RemoteSettings;
use Datto\Connection\ConnectionType;
use Datto\Connection\Service\ConnectionService;
use Datto\Connection\Service\EsxConnectionService;
use Datto\Connection\Service\HvConnectionService;
use Datto\Feature\FeatureService;
use Datto\Log\RemoteLogSettings;
use Datto\System\Api\DeviceApiClientService;
use Datto\System\Migration\Context;
use Datto\System\Migration\Stage\AbstractMigrationStage;
use Datto\System\Update\UpdateWindowService;
use Datto\Upgrade\ChannelService;
use Datto\Resource\DateTimeService;
use Datto\Util\DateTimeZoneService;
use Datto\Log\DeviceLoggerInterface;

/**
 * Migrate device configurations from source device to target
 *
 * @author Peter Salu <psalu@datto.com>
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class DeviceConfigStage extends AbstractMigrationStage
{
    const DEVICE_TARGET = 'device';

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var DeviceApiClientService */
    private $deviceClient;

    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var UpdateWindowService */
    private $updateWindowService;

    /** @var ChannelService */
    private $channelService;

    /** @var DateTimeZoneService */
    private $dateTimeZoneService;

    /** @var LocalConfig */
    private $localConfig;

    /** @var RemoteSettings */
    private $remoteSettings;

    /** @var SpeedSyncMaintenanceService */
    private $speedSyncMaintenanceService;

    /** @var OffsiteSyncScheduleService */
    private $offsiteSyncScheduleService;

    /** @var RemoteLogSettings */
    private $remoteLogSettings;

    /** @var string */
    private $channel;

    /** @var string */
    private $timeZone;

    /** @var int */
    private $concurrentBackups;

    /** @var int */
    private $backupOffset;

    /** @var int */
    private $disableScreenshots;

    /** @var int */
    private $remoteLogging;

    /** @var array */
    private $remoteLoggingServers;

    /** @var bool */
    private $advancedAlertingEnabled;

    /** @var array */
    private $updateWindow;

    /** @var int */
    private $mountedRestoreAlert;

    /** @var int */
    private $offsiteSyncSpeed;

    /** @var int */
    private $maxConcurrentSyncs;

    /** @var array */
    private $sourceSchedule;

    /** @var array */
    private $originalSchedule;

    /** @var array */
    private $hypervisorConnections;

    /** @var ConnectionService */
    private $connectionService;

    /** @var EsxConnectionService */
    private $esxConnectionService;

    /** @var HvConnectionService */
    private $hvConnectionService;

    /** @var FeatureService */
    private $featureService;

    /**
     * @param Context $context
     * @param DeviceLoggerInterface $logger
     * @param DeviceApiClientService $deviceClient
     * @param DeviceConfig $deviceConfig
     * @param DateTimeZoneService $dateTimeZoneService
     * @param UpdateWindowService $updateWindowService
     * @param ChannelService $channelService
     * @param LocalConfig $localConfig
     * @param RemoteSettings $remoteSettings
     * @param SpeedSyncMaintenanceService $speedSyncMaintenanceService
     * @param OffsiteSyncScheduleService $offsiteSyncScheduleService
     * @param RemoteLogSettings $remoteLogSettings
     * @param ConnectionService $connectionService
     * @param EsxConnectionService $esxConnectionService
     * @param HvConnectionService $hvConnectionService
     * @param FeatureService $featureService
     */
    public function __construct(
        Context $context,
        DeviceLoggerInterface $logger,
        DeviceApiClientService $deviceClient,
        DeviceConfig $deviceConfig,
        DateTimeZoneService $dateTimeZoneService,
        UpdateWindowService $updateWindowService,
        ChannelService $channelService,
        LocalConfig $localConfig,
        RemoteSettings $remoteSettings,
        SpeedSyncMaintenanceService $speedSyncMaintenanceService,
        OffsiteSyncScheduleService $offsiteSyncScheduleService,
        RemoteLogSettings $remoteLogSettings,
        ConnectionService $connectionService,
        EsxConnectionService $esxConnectionService,
        HvConnectionService $hvConnectionService,
        FeatureService $featureService
    ) {
        parent::__construct($context);

        $this->logger = $logger;
        $this->deviceClient = $deviceClient;
        $this->deviceConfig = $deviceConfig;
        $this->dateTimeZoneService = $dateTimeZoneService;
        $this->updateWindowService = $updateWindowService;
        $this->channelService = $channelService;
        $this->localConfig = $localConfig;
        $this->remoteSettings = $remoteSettings;
        $this->speedSyncMaintenanceService = $speedSyncMaintenanceService;
        $this->offsiteSyncScheduleService = $offsiteSyncScheduleService;
        $this->remoteLogSettings = $remoteLogSettings;
        $this->connectionService = $connectionService;
        $this->esxConnectionService = $esxConnectionService;
        $this->hvConnectionService = $hvConnectionService;
        $this->featureService = $featureService;
    }

    /**
     * @inheritdoc
     */
    public function commit()
    {
        $targets = $this->context->getTargets();
        $migrateDeviceConfig = in_array(static::DEVICE_TARGET, $targets);
        if ($migrateDeviceConfig) {
            $this->migrate();
        }
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
    }

    /**
     * @inheritdoc
     */
    public function rollback()
    {
    }

    /**
     * Fetch all the settings from the source device and migrate them to the destination device.
     */
    private function migrate()
    {
        $this->fetchSettings();

        $this->migrateTimeZone();
        $this->migrateConcurrentBackups();
        $this->migrateBackupOffset();
        $this->migrateDisableScreenshots();
        $this->migrateRemoteLoggingServers();
        $this->migrateAdvancedAlerting();
        $this->migrateUpdateWindow();
        $this->migrateUpdateChannel();
        $this->migrateMountedRestoreAlert();
        $this->migrateOffsiteSyncSpeed();
        $this->migrateMaxConcurrentSyncs();
        $this->migrateOffsiteSyncSchedule();
        $this->migrateHypervisors();
    }

    /**
     * Fetch all settings from the source device. We do this so we're more resilient to failures in case we lose the
     * connection. We won't have half migrated settings.
     */
    private function fetchSettings()
    {
        $getTimeZoneResult = $this->deviceClient->call('v1/device/system/getTimeZone', []);
        $this->timeZone = $getTimeZoneResult['timeZone'];

        $getScreenshotsResult = $this->deviceClient->call('v1/device/settings/getScreenshots', []);
        $this->disableScreenshots = $getScreenshotsResult ? 0 : 1;

        $upgradeChannelResult = $this->deviceClient->call('v1/device/settings/UpgradeChannel/getChannel', []);
        $this->channel = $upgradeChannelResult['channel'];

        $getMountedRestoreAlertResult = $this->deviceClient->call('v1/device/settings/getMountedRestoreAlert', []);
        $this->mountedRestoreAlert = $getMountedRestoreAlertResult * DateTimeService::SECONDS_PER_DAY;

        $this->sourceSchedule = $this->deviceClient->call('v1/device/offsite/syncSchedule/getAll', []);
        $this->originalSchedule = $this->offsiteSyncScheduleService->getAll();

        $this->concurrentBackups = $this->deviceClient->call('v1/device/settings/getConcurrentBackup', []);
        $this->backupOffset = $this->deviceClient->call('v1/device/settings/getBackupOffset', []);
        $this->remoteLogging = $this->deviceClient->call('v1/device/settings/getRemoteLogging', []);
        $this->remoteLoggingServers = $this->deviceClient->call('v1/device/settings/getRemoteLoggingServers', []);
        $this->advancedAlertingEnabled = $this->deviceClient->call('v1/device/settings/getAdvancedAlerting', []);
        $this->updateWindow = $this->deviceClient->call('v1/device/settings/UpdateWindow/getWindow', []);
        $this->offsiteSyncSpeed = $this->deviceClient->call('v1/device/settings/getOffsiteSyncSpeed', []);
        $this->maxConcurrentSyncs = $this->deviceClient->call('v1/device/offsite/maintenance/getMaxConcurrentSyncs', []);

        $this->hypervisorConnections = [];

        // API endpoint returns an array, not bool...
        $remoteConnectionsSupportInfo = $this->deviceClient->call(
            'v1/device/feature/isSupported',
            ['feature' => FeatureService::FEATURE_HYPERVISOR_CONNECTIONS]
        );

        // do not migrate connections, if neither source or target support it
        $migrateConnections =
            $this->featureService->isSupported(FeatureService::FEATURE_HYPERVISOR_CONNECTIONS) &&
            $remoteConnectionsSupportInfo['supported'];

        if ($migrateConnections) {
            $this->hypervisorConnections = $this->deviceClient->call(
                'v1/device/connections/getAll',
                []
            );
        }
    }

    /**
     * Update the device's time zone
     */
    private function migrateTimeZone()
    {
        $this->dateTimeZoneService->setTimeZone($this->timeZone);
    }

    /**
     * Update the device's number of concurrent backups
     */
    private function migrateConcurrentBackups()
    {
        $this->deviceConfig->setAllowingEmptyData('maxBackups', $this->concurrentBackups);
    }

    /**
     * Update the device's backup offset
     */
    private function migrateBackupOffset()
    {
        $this->deviceConfig->set('backupOffset', $this->backupOffset);
    }

    /**
     * Update the device's disable screenshots setting
     */
    private function migrateDisableScreenshots()
    {
        $this->deviceConfig->set('disableScreenshots', $this->disableScreenshots);
    }

    /**
     * Update the device's remote logging servers configuration
     */
    private function migrateRemoteLoggingServers()
    {
        $this->deviceConfig->set('cefActive', $this->remoteLogging);
        $this->remoteLogSettings->updateServers($this->remoteLoggingServers);
    }

    /**
     * Update the device's advanced alerting configuration
     */
    private function migrateAdvancedAlerting()
    {
        $this->deviceConfig->set('useAdvancedAlerting', $this->advancedAlertingEnabled);
    }

    /**
     * Update the device's update window
     */
    private function migrateUpdateWindow()
    {
        $this->updateWindowService->setWindow($this->updateWindow['startHour'], $this->updateWindow['endHour']);
    }

    /**
     * Update the device's update channel
     */
    private function migrateUpdateChannel()
    {
        $this->channelService->setChannel($this->channel);
    }

    /**
     * Update the device's mounted resore alert configuration
     */
    private function migrateMountedRestoreAlert()
    {
        $this->deviceConfig->set('defaultTP', $this->mountedRestoreAlert);
    }

    /**
     * Update the device's offsite sync speed configuration
     */
    private function migrateOffsiteSyncSpeed()
    {
        $this->localConfig->set('txSpeed', $this->offsiteSyncSpeed);
        $this->remoteSettings->setOffsiteSyncSpeed($this->offsiteSyncSpeed);
    }

    /**
     * Update the device's maximum concurrent offsite synchronization configuration
     */
    private function migrateMaxConcurrentSyncs()
    {
        $this->speedSyncMaintenanceService->setMaxConcurrentSyncs($this->maxConcurrentSyncs);
    }

    /**
     * Update the device's offsite synchronization schedule
     */
    private function migrateOffsiteSyncSchedule()
    {
        foreach ($this->originalSchedule as $item) {
            $this->offsiteSyncScheduleService->delete($item['schedID']);
        }

        foreach ($this->sourceSchedule as $item) {
            $this->offsiteSyncScheduleService->add($item['start'], $item['end'], $item['speed']);
        }
    }

    private function migrateHypervisors()
    {
        foreach ($this->hypervisorConnections as $hypervisor) {
            $existingHypervisor = $this->connectionService->get($hypervisor['name']);
            if ($existingHypervisor) {
                if ($existingHypervisor->getType()->value() != $hypervisor['type']) {
                    $this->logger->warning(
                        'DCF0001 Attempted to migrate hypervisor with name that matched existing hypervisor with different type.  Skipping migration for this hypervisor.',
                        ['hypervisorName' => $hypervisor['name']]
                    );
                }
            } elseif ($hypervisor['type'] === ConnectionType::LIBVIRT_ESX) {
                $this->esxConnectionService->copy($hypervisor);
            } elseif ($hypervisor['type'] === ConnectionType::LIBVIRT_HV) {
                $this->hvConnectionService->copy($hypervisor);
            }
        }
    }
}
