<?php

namespace Datto\System\Migration\Device;

use Datto\Config\AgentConfigFactory;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Encryption\CloudEncryptionService;
use Datto\Asset\Agent\RepairService;
use Datto\Asset\Share\ShareService;
use Datto\Cloud\JsonRpcClient;
use Datto\Cloud\OffsiteSyncScheduleService;
use Datto\Cloud\SpeedSync;
use Datto\Cloud\SpeedSyncMaintenanceService;
use Datto\Config\DeviceConfig;
use Datto\Config\LocalConfig;
use Datto\Configuration\RemoteSettings;
use Datto\Connection\Service\ConnectionService;
use Datto\Connection\Service\EsxConnectionService;
use Datto\Connection\Service\HvConnectionService;
use Datto\Dataset\DatasetFactory;
use Datto\Feature\FeatureService;
use Datto\Iscsi\IscsiTarget;
use Datto\Log\RemoteLogSettings;
use Datto\System\Api\DeviceApiClientService;
use Datto\System\MaintenanceModeService;
use Datto\System\Migration\Context;
use Datto\System\Migration\Device\Stage\AddRemoteConfigBackupStage;
use Datto\System\Migration\Device\Stage\ChangeStorageNodeStage;
use Datto\System\Migration\Device\Stage\CompatibilityCheckStage;
use Datto\System\Migration\Device\Stage\ConnectStage;
use Datto\System\Migration\Device\Stage\DeleteConfigBackupStage;
use Datto\System\Migration\Device\Stage\DeviceConfigStage;
use Datto\System\Migration\Device\Stage\MaintenanceModeStage;
use Datto\System\Migration\Device\Stage\MigrateAssetStage;
use Datto\System\Migration\Device\Stage\MigrateHypervisorsStage;
use Datto\System\Migration\Device\Stage\PauseOffsiteSyncStage;
use Datto\System\Migration\Device\Stage\ShareConfigStage;
use Datto\System\Migration\Device\Stage\StartSshServerStage;
use Datto\System\Migration\Device\Stage\UploadEncryptionKeysStage;
use Datto\System\Migration\Device\Stage\UsersStage;
use Datto\System\Migration\Device\Stage\VerifyRequiredDatasetsStage;
use Datto\System\Migration\Stage\AbstractMigrationStage;
use Datto\System\Rsync\RsyncProcess;
use Datto\System\Ssh\SshClient;
use Datto\System\Update\UpdateWindowService;
use Datto\Upgrade\ChannelService;
use Datto\User\UnixUserService;
use Datto\Util\DateTimeZoneService;
use Datto\Common\Utility\Filesystem;
use Datto\Common\Resource\Sleep;
use Datto\ZFS\ZfsDatasetFactory;
use Datto\ZFS\ZfsDatasetService;
use Datto\Samba\UserService as SambaUserService;
use Datto\User\WebUserService;
use Datto\Log\DeviceLoggerInterface;

/**
 * Factory to create device migration stages
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class DeviceMigrationStagesFactory
{
    /** @var DateTimeZoneService */
    private $dateTimeZoneService;

    /** @var DeviceApiClientService */
    private $deviceClientService;

    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var UpdateWindowService */
    private $updateWindowService;

    /** @var ChannelService */
    private $channelService;

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

    /** @var ConnectionService */
    private $connectionService;

    /** @var EsxConnectionService */
    private $esxConnectionService;

    /** @var HvConnectionService */
    private $hvConnectionService;

    /** @var FeatureService */
    private $featureService;

    /** @var RsyncProcess */
    private $rsyncProcess;

    /** @var SshClient */
    private $sshClient;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var DatasetFactory */
    private $datasetFactory;

    /** @var AgentConfigFactory */
    private $agentConfigFactory;

    /** @var RepairService */
    private $repairService;

    /** @var SpeedSync */
    private $speedSync;

    /** @var MaintenanceModeService */
    private $maintenanceModeService;

    /** @var AgentService */
    private $agentService;

    /** @var Sleep */
    private $sleep;

    /** @var JsonRpcClient */
    private $portalClient;

    /** @var ZfsDatasetService */
    private $zfsDatasetService;

    /** @var UnixUserService */
    private $unixUserService;

    /** @var Filesystem */
    private $filesystem;

    /** @var CloudEncryptionService */
    private $cloudEncryptionService;

    /** @var IscsiTarget */
    private $iscsiTarget;

    /** @var ShareService */
    private $shareService;

    /** @var SambaUserService */
    private $sambaUserService;

    /** @var WebUserService */
    private $webUserService;

    /** @var ZfsDatasetFactory */
    private $zfsDatasetFactory;

    public function __construct(
        DateTimeZoneService $dateTimeZoneService,
        DeviceApiClientService $deviceClientService,
        DeviceConfig $deviceConfig,
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
        RsyncProcess $rsyncProcess,
        SshClient $sshClient,
        DeviceLoggerInterface $logger,
        DatasetFactory $datasetFactory,
        AgentConfigFactory $agentConfigFactory,
        RepairService $repairService,
        SpeedSync $speedSync,
        MaintenanceModeService $maintenanceModeService,
        AgentService $agentService,
        Sleep $sleep,
        JsonRpcClient $portalClient,
        ZfsDatasetService $zfsDatasetService,
        UnixUserService $unixUserService,
        Filesystem $filesystem,
        CloudEncryptionService $cloudEncryptionService,
        IscsiTarget $iscsiTarget,
        ShareService $shareService,
        FeatureService $featureService,
        SambaUserService $sambaUserService,
        WebUserService $webUserService,
        ZfsDatasetFactory $zfsDatasetFactory
    ) {
        $this->dateTimeZoneService = $dateTimeZoneService;
        $this->deviceClientService = $deviceClientService;
        $this->deviceConfig = $deviceConfig;
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
        $this->rsyncProcess = $rsyncProcess;
        $this->sshClient = $sshClient;
        $this->logger = $logger;
        $this->datasetFactory = $datasetFactory;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->repairService = $repairService;
        $this->speedSync = $speedSync;
        $this->maintenanceModeService = $maintenanceModeService;
        $this->agentService = $agentService;
        $this->sleep = $sleep;
        $this->portalClient = $portalClient;
        $this->zfsDatasetService = $zfsDatasetService;
        $this->unixUserService = $unixUserService;
        $this->filesystem = $filesystem;
        $this->cloudEncryptionService = $cloudEncryptionService;
        $this->iscsiTarget = $iscsiTarget;
        $this->shareService = $shareService;
        $this->featureService = $featureService;
        $this->sambaUserService = $sambaUserService;
        $this->webUserService = $webUserService;
        $this->zfsDatasetFactory = $zfsDatasetFactory;
    }

    /**
     * Create the stages
     *
     * @param Context $context
     * @return AbstractMigrationStage[]
     */
    public function createStages(Context $context) : array
    {
        $targets = $context->getTargets();
        // A full migration moves all configuration to a new empty device.
        $fullMigration = in_array(DeviceConfigStage::DEVICE_TARGET, $targets);

        $stages = [];

        $stages[] = new VerifyRequiredDatasetsStage(
            $context,
            $this->logger,
            $this->zfsDatasetService,
            $this->deviceConfig,
            $this->zfsDatasetFactory
        );

        $stages[] = new ConnectStage(
            $context,
            $this->logger,
            $this->deviceClientService
        );

        $stages[] = new CompatibilityCheckStage(
            $context,
            $this->logger,
            $this->deviceClientService,
            $this->deviceConfig
        );

        $stages[] = new MaintenanceModeStage(
            $context,
            $this->logger,
            $this->maintenanceModeService,
            $this->deviceClientService
        );

        $stages[] = new PauseOffsiteSyncStage(
            $context,
            $this->logger,
            $this->speedSyncMaintenanceService,
            $this->deviceClientService
        );

        $stages[] = new StartSshServerStage(
            $context,
            $this->logger,
            $this->sshClient
        );

        if ($fullMigration) {
            $stages[] = new DeviceConfigStage(
                $context,
                $this->logger,
                $this->deviceClientService,
                $this->deviceConfig,
                $this->dateTimeZoneService,
                $this->updateWindowService,
                $this->channelService,
                $this->localConfig,
                $this->remoteSettings,
                $this->speedSyncMaintenanceService,
                $this->offsiteSyncScheduleService,
                $this->remoteLogSettings,
                $this->connectionService,
                $this->esxConnectionService,
                $this->hvConnectionService,
                $this->featureService
            );

            $stages[] = new DeleteConfigBackupStage(
                $context,
                $this->speedSync
            );

            $stages[] = new ChangeStorageNodeStage(
                $context,
                $this->portalClient,
                $this->deviceClientService
            );
        }

        foreach ($context->getTargets() as $target) {
            if ($target !== DeviceConfigStage::DEVICE_TARGET) {
                $stages[] = new MigrateAssetStage(
                    $context,
                    $target,
                    $this->logger,
                    $this->sshClient,
                    $this->rsyncProcess,
                    $this->deviceClientService,
                    $this->sleep,
                    $this->speedSync,
                    $this->agentConfigFactory,
                    $this->datasetFactory,
                    $this->repairService,
                    $this->agentService,
                    $this->speedSyncMaintenanceService,
                    $this->filesystem,
                    $this->iscsiTarget,
                    $this->shareService,
                    $this->deviceConfig,
                    $this->featureService
                );
            }
        }

        $stages[] = new ShareConfigStage(
            $context,
            $this->sshClient,
            $this->filesystem
        );

        if ($fullMigration) {
            $stages[] = new AddRemoteConfigBackupStage(
                $context,
                $this->speedSync,
                $this->zfsDatasetService
            );
        } else {
            // If it was a full migration, the hypervisor connections were migrated as part of DeviceConfigStage
            // The MigrateHypervisorsStage should only be run if the DeviceConfigStage is not part of the migration
            $stages[] = new MigrateHypervisorsStage(
                $context,
                $this->logger,
                $this->deviceClientService,
                $this->agentConfigFactory,
                $this->connectionService,
                $this->esxConnectionService,
                $this->hvConnectionService,
                $this->featureService
            );
        }

        $stages[] = new UploadEncryptionKeysStage(
            $context,
            $this->cloudEncryptionService
        );

        $stages[] = new UsersStage(
            $context,
            $this->logger,
            $this->deviceClientService,
            $this->unixUserService,
            $this->sshClient,
            $this->filesystem,
            $this->agentConfigFactory,
            $this->shareService,
            $this->sambaUserService,
            $this->webUserService
        );

        return $stages;
    }
}
