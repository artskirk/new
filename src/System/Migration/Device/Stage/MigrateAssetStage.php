<?php

namespace Datto\System\Migration\Device\Stage;

use Datto\Asset\Agent\Agent;
use Datto\Asset\AssetType;
use Datto\Config\AgentConfigFactory;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\RepairService;
use Datto\Asset\Share\Iscsi\IscsiShare;
use Datto\Asset\Share\Nas\NasShare;
use Datto\Asset\Share\Share;
use Datto\Asset\Share\ShareService;
use Datto\Cloud\SpeedSync;
use Datto\Cloud\SpeedSyncMaintenanceService;
use Datto\Config\DeviceConfig;
use Datto\Dataset\DatasetFactory;
use Datto\Dataset\ZVolDataset;
use Datto\Feature\FeatureService;
use Datto\Iscsi\IscsiTarget;
use Datto\Iscsi\UserType;
use Datto\Service\Registration\SshKeyService;
use Datto\Screenshot\ScreenshotFileRepository;
use Datto\System\Api\DeviceApiClientService;
use Datto\System\Migration\Context;
use Datto\System\Migration\Device\DeviceMigrationService;
use Datto\System\Migration\Stage\AbstractMigrationStage;
use Datto\System\Rsync\RsyncProcess;
use Datto\System\Ssh\SshClient;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Datto\Common\Resource\Sleep;
use Exception;
use Datto\Log\DeviceLoggerInterface;

/**
 * Migrate the configuration and dataset for a single asset.
 *
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class MigrateAssetStage extends AbstractMigrationStage
{
    const ASSET_KEY_GLOB_FORMAT = Agent::KEYBASE . '%s.*';
    const ASSET_LAST_SENT_KEY_REMOVE_FORMAT = Agent::KEYBASE . '%s.lastSentAssetInfo';
    const ISCSI_SOURCE_COPY = IscsiTarget::LIO_CONFIG_FILE . '.source';

    private string $assetKeyName;
    private DeviceLoggerInterface $logger;
    private SshClient $sshClient;
    private RsyncProcess $rsyncProcess;
    private DeviceApiClientService $deviceClient;
    private int $sourceDeviceId;
    private Sleep $sleep;
    private SpeedSync $speedSync;
    private AgentConfigFactory $agentConfigFactory;
    private DatasetFactory $datasetFactory;
    private RepairService $repairService;
    private AgentService $agentService;
    private SpeedSyncMaintenanceService $speedSyncMaintenanceService;
    private Filesystem $filesystem;
    private IscsiTarget $iscsiTarget;
    private ShareService $shareService;
    private DeviceConfig $deviceConfig;
    private FeatureService $featureService;

    public function __construct(
        Context $context,
        string $assetKeyName,
        DeviceLoggerInterface $logger,
        SshClient $sshClient,
        RsyncProcess $rsyncProcess,
        DeviceApiClientService $deviceClient,
        Sleep $sleep,
        SpeedSync $speedSync,
        AgentConfigFactory $agentConfigFactory,
        DatasetFactory $datasetFactory,
        RepairService $repairService,
        AgentService $agentService,
        SpeedSyncMaintenanceService $speedSyncMaintenanceService,
        Filesystem $filesystem,
        IscsiTarget $iscsiTarget,
        ShareService $shareService,
        DeviceConfig $deviceConfig,
        FeatureService $featureService
    ) {
        parent::__construct($context);
        $this->logger = $logger;
        $this->sshClient = $sshClient;
        $this->rsyncProcess = $rsyncProcess;
        $this->deviceClient = $deviceClient;
        $this->sleep = $sleep;
        $this->speedSync = $speedSync;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->datasetFactory = $datasetFactory;
        $this->repairService = $repairService;
        $this->agentService = $agentService;
        $this->speedSyncMaintenanceService = $speedSyncMaintenanceService;
        $this->filesystem = $filesystem;
        $this->iscsiTarget = $iscsiTarget;
        $this->shareService = $shareService;
        $this->deviceConfig = $deviceConfig;
        $this->featureService = $featureService;

        $this->assetKeyName = $assetKeyName;
    }

    /**
     * @inheritdoc
     */
    public function commit()
    {
        $this->checkForShareMountConflict();
        $this->takeShareSnapshot();
        $this->migrateDataset();
        $this->blockUntilMigrated();
        $this->migrateAssetKeyFiles();
        $this->migrateScreenshots();
        $this->setDatasetUuid();
        $this->setOriginDevice();
        $this->restoreLastSnapshot();
        $this->repairAgent();
        $this->createIscsiTarget();
        $this->enableIscsiChapAuthentication();
        $this->mountShare();
        $this->enableNfsSharing();
        $this->enableAfpSharing();
        $this->assetOffsitePause();
        $this->sourceAssetArchive();
    }

    public function cleanup()
    {
        $this->filesystem->unlinkIfExists(static::ISCSI_SOURCE_COPY);
    }

    public function rollback()
    {
        $this->cleanup();
    }

    /**
     * Prevent migrating a share when there is already a share with the same mountpoint (name)
     */
    private function checkForShareMountConflict()
    {
        $params = ['shareName' => $this->assetKeyName, 'fields' => ['name']];
        try {
            $typeInfo = $this->deviceClient->call('v1/device/asset/getType', ['assetKey' => $this->assetKeyName]);
            if (($typeInfo['type'] ?? '') !== AssetType::SHARE) {
                return; // not a share
            }

            $sourceShare = $this->deviceClient->call('v1/device/asset/share/get', $params, DeviceMigrationService::REMOTE_CALL_TIMEOUT);
        } catch (Exception $e) {
            return; // not a share
        }

        $shares = $this->shareService->getAll();
        foreach ($shares as $share) {
            if ($share->getName() === $sourceShare['name']) {
                throw new Exception("Existing share has the same name: \"{$sourceShare['name']}\"");
            }
        }
    }

    /**
     * Take a final snapshot of the share.
     */
    private function takeShareSnapshot()
    {
        $assetInfo = $this->deviceClient->call(
            'v1/device/asset/getType',
            ['assetKey' => $this->assetKeyName]
        );

        if ($assetInfo['type'] === AssetType::SHARE && $assetInfo['subType'] !== AssetType::EXTERNAL_NAS_SHARE) {
            $this->deviceClient->call(
                'v1/device/asset/share/start',
                ['shareName' => $this->assetKeyName]
            );
        }
    }

    /**
     * Call SpeedSync to move zfs data
     */
    private function migrateDataset()
    {
        $this->sourceDeviceId = $this->deviceClient->call('v1/device/settings/getDeviceId');
        $zfsPath = $this->deviceClient->call(
            'v1/device/asset/dataset/getPath',
            ['name' => $this->assetKeyName]
        );

        $host = $this->sshClient->getHostname();
        $sshPort = $this->sshClient->getPort();
        $sshUser = $this->sshClient->getAuthorizedUser();

        $this->speedSync->migrateDataset(
            $host,
            $sshPort,
            $sshUser,
            SshKeyService::SSH_PRIVATE_KEY_FILE,
            $this->sourceDeviceId,
            $zfsPath
        );
    }

    /**
     *  This will loop and block while any speedsync migration is active
     */
    private function blockUntilMigrated()
    {
        while ($this->speedSync->isMigrationActive()) {
            $this->speedSync->runCron();
            $this->sleep->sleep(DateTimeService::SECONDS_PER_MINUTE);
        }
    }

    /**
     * Migrate an asset's key files
     */
    private function migrateAssetKeyFiles()
    {
        $host = $this->sshClient->getHostname();
        $sshPort = $this->sshClient->getPort();
        $sshUser = $this->sshClient->getAuthorizedUser();
        $assetKeyFileGlob = sprintf(static::ASSET_KEY_GLOB_FORMAT, $this->assetKeyName);
        $source = $sshUser . '@' . $host . ':' . $assetKeyFileGlob;
        $target = Agent::KEYBASE;
        $lastSentAssetInfo = sprintf(static::ASSET_LAST_SENT_KEY_REMOVE_FORMAT, $this->assetKeyName);

        $this->rsyncProcess->runOverSsh($source, $target, RsyncProcess::DEFAULT_TIMEOUT_IN_SECONDS, $sshPort);

        if ($this->filesystem->exists($lastSentAssetInfo)) {
            $this->filesystem->unlink($lastSentAssetInfo);
        }
    }

    /**
     * Migrate an asset's screenshot images
     */
    private function migrateScreenshots()
    {
        $agentConfig = $this->agentConfigFactory->create($this->assetKeyName);
        if (!$agentConfig->isShare()) {
            $host = $this->sshClient->getHostname();
            $sshPort = $this->sshClient->getPort();
            $sshUser = $this->sshClient->getAuthorizedUser();
            $assetScreenshotFileGlob = sprintf(ScreenshotFileRepository::SCREENSHOT_GLOB, $this->assetKeyName, '*');
            $source = $sshUser . '@' . $host . ':' . $assetScreenshotFileGlob;
            $target = "/datto/config/screenshots/";

            $this->rsyncProcess->runOverSsh($source, $target, RsyncProcess::DEFAULT_TIMEOUT_IN_SECONDS, $sshPort, true);
        }
    }

    /**
     * Set the appropriate ZFS properties.
     */
    private function setDatasetUuid()
    {
        $config = $this->agentConfigFactory->create($this->assetKeyName);
        $zfsPath = $config->getZfsBase() . '/' . $this->assetKeyName;
        $dataset = $this->datasetFactory->createZfsDataset($zfsPath);

        if (!$config->isShare()) {
            $dataset->create();
        }

        $uuid = $config->getUuid();
        if ($uuid) {
            try {
                $dataset->setAttribute("datto:uuid", $uuid);
            } catch (Exception $e) {
                $this->logger->warning('MIG1000 Error setting UUID on asset', ['assetKeyName' => $this->assetKeyName, 'uuid' => $uuid, 'exception' => $e]);
            }
        }

        $result = $this->speedSync->add($zfsPath, SpeedSync::TARGET_CLOUD);
        if ($result !== 0) {
            throw new Exception("SpeedSync add failed for $zfsPath");
        }
    }

    /*
     * If appropriate, set the origin device info in the .originDevice key file.
     * Some of this migration code may be used for cloud to cloud migrations so we don't want to change the
     * origin device info in those cases. This info indicates where the dataset originated.
     * FIXME (fix this when we do cloud to cloud asset migration with Everything's a Siris)
     *     This belongs in a section that is specifically for transferring "ownership" of the asset.
     *     Similar to repairAgent(). Some refactoring to migrations will need to happen.
     */
    private function setOriginDevice()
    {
        try {
            // Get the device origin info for this asset from .originDevice key file
            $agentConfig = $this->agentConfigFactory->create($this->assetKeyName);
            if ($agentConfig->isShare()) {
                $asset = $this->shareService->get($this->assetKeyName);
            } else {
                $asset = $this->agentService->get($this->assetKeyName);
            }

            $originDeviceId = $asset->getOriginDevice()->getDeviceId();
        } catch (Exception $e) {
            $this->logger->warning('MIG1001 Error retrieving the origin device info from asset', ['assetKeyName' => $this->assetKeyName, 'exception' => $e]);
            return;
        }

        try {
            if ($originDeviceId !== null && $originDeviceId === $this->sourceDeviceId) {
                // The source device was responsible for backups, now this one will be.
                $deviceId = $this->deviceConfig->getDeviceId();
                $resellerId = $this->deviceConfig->getResellerId();

                // Set the key file
                $asset->getOriginDevice()->setDeviceId($deviceId);
                $asset->getOriginDevice()->setResellerId($resellerId);

                if ($agentConfig->isShare()) {
                    $this->shareService->save($asset);
                } else {
                    $this->agentService->save($asset);
                }
            } else {
                /*
                 * The source device either was not responsible for the backups or we can't tell due to lack
                 * of information (older assets won't have this info). Therefore, do not change anything.
                 */
                $this->logger->info("MIG1002 Origin device info doesn't match, preserving original info for asset", ['assetKeyName' => $this->assetKeyName]);
            }
        } catch (Exception $e) {
            $this->logger->warning('MIG1003 Error setting the origin device info on asset', ['assetKeyName' => $this->assetKeyName, 'exception' => $e]);
        }
    }

    /**
     * Restore the working dataset to the last snapshot.
     */
    private function restoreLastSnapshot()
    {
        $config = $this->agentConfigFactory->create($this->assetKeyName);
        $zfsPath = $config->getZfsBase() . '/' . $this->assetKeyName;
        $dataset = $this->datasetFactory->createZfsDataset($zfsPath);

        $snapshots = $dataset->listSnapshots();
        if ($snapshots) {
            rsort($snapshots);
            $dataset->rollbackTo($snapshots[0]);
        }
    }

    /**
     * Repair communications for the given agent
     */
    private function repairAgent()
    {
        $agentConfig = $this->agentConfigFactory->create($this->assetKeyName);
        if ($agentConfig->isShare()) {
            return;
        }

        $agent = $this->agentService->get($this->assetKeyName);
        $fqdn = $agent->getFullyQualifiedDomainName();
        $isAgentless = $agent->getPlatform()->isAgentless();
        if (!$fqdn || $isAgentless || $agent->isRescueAgent() || $agent->getLocal()->isArchived()) {
            $this->logger->info('AGT3013 Skipped repairing communications (not agent based)', ['assetKeyName' => $this->assetKeyName]);
            return;
        }

        $this->logger->info('AGT3008 Attempting to repair communications', ['assetKeyName' => $this->assetKeyName]);
        $agentIsReachable = $this->agentService->pingAgent($fqdn);
        if ($agentIsReachable) {
            try {
                $this->repairService->repair($this->assetKeyName);
                $this->logger->info('AGT3009 Communications has been repaired successfully');
            } catch (Exception $e) {
                $this->logger->warning('AGT3011 Error repairing communications', ['exception' => $e->getMessage()]);
            }
        } else {
            $this->logger->warning('AGT3012 Agent cannot be reached (pinging fqdn failed)', ['assetKeyName' => $this->assetKeyName, 'fqdn' => $fqdn]);
        }
    }

    /**
     * Create the iSCSI target for any iSCSI share.
     *
     * Note: need to do this before we use the asset system for anything because asset instantiation
     * validates the targets and will fail if they don't exist.
     */
    private function createIscsiTarget()
    {
        if ($this->isIsciShare()) {
            $shareInfo = $this->agentConfigFactory->create($this->assetKeyName);
            $shareInfoData = unserialize($shareInfo->get('agentInfo'), ['allowed_classes' => false]);
            $shareName = $shareInfo->getName();

            $blockSize = (int)($shareInfoData['blockSize'] ?? IscsiShare::BLOCK_SIZE_LARGE);
            $zfsPath = Share::BASE_ZFS_PATH . '/' . $this->assetKeyName;
            $blockLink = ZVolDataset::BLK_BASE_DIR . '/' . $zfsPath;

            $targetName = $this->iscsiTarget->makeTargetName($shareName, '');

            $this->iscsiTarget->createTarget($targetName);
            $this->iscsiTarget->addLun($targetName, $blockLink, false, false, null, ["block_size=$blockSize"]);
            $this->iscsiTarget->writeChanges();
        }
    }

    /**
     * Set up CHAP authentication on any iSCSI shares that use it.
     */
    private function enableIscsiChapAuthentication()
    {
        if ($this->isIsciShare()) {
            $chapUsers = $this->getSourceDeviceChapUsers();
            $user = $chapUsers[UserType::INCOMING][0] ?? '';
            $password = $chapUsers[UserType::INCOMING][1] ?? '';
            $mutualUser = $chapUsers[UserType::OUTGOING][0] ?? '';
            $mutualPassword = $chapUsers[UserType::OUTGOING][1] ?? '';
            $enableChap = $user !== '' && $password !== '';
            $enableMutualChap = $mutualPassword !== '' && $mutualUser !== '';
            if ($enableChap) {
                /** @var IscsiShare $asset */
                $asset = $this->shareService->get($this->assetKeyName);
                $asset->getChap()->enable($user, $password, $enableMutualChap, $mutualUser, $mutualPassword);
                $this->shareService->save($asset);
            }
        }
    }

    /**
     * Mount share if necessary. Enabling nfs will fail if the share is unmounted.
     */
    private function mountShare()
    {
        $config = $this->agentConfigFactory->create($this->assetKeyName);
        if ($config->isShare()) {
            $share = $this->shareService->get($this->assetKeyName);
            if ($this->featureService->isSupported(FeatureService::FEATURE_SHARE_AUTO_MOUNTING, null, $share)) {
                $share->mount();
            }
        }
    }

    /**
     * Enable NFS sharing on any NAS shares that use it.
     */
    private function enableNfsSharing()
    {
        $config = $this->agentConfigFactory->create($this->assetKeyName);
        if ($config->isShare()) {
            $shareData = unserialize($config->get('agentInfo'), ['allowed_classes' => false]);
            $enableNfs = $shareData['nfsEnabled'] ?? false;
            if ($enableNfs) {
                /** @var NasShare $share */
                $share = $this->shareService->get($this->assetKeyName);
                $share->getNfs()->enable();
            }
        }
    }

    /**
     * Enable AFP sharing on any NAS shares that use it.
     */
    private function enableAfpSharing()
    {
        $config = $this->agentConfigFactory->create($this->assetKeyName);
        if ($config->isShare()) {
            $shareData = unserialize($config->get('agentInfo'), ['allowed_classes' => false]);
            $enableAfp = $shareData['afpEnabled'] ?? false;
            if ($enableAfp) {
                /** @var NasShare $share */
                $share = $this->shareService->get($this->assetKeyName);
                $share->getAfp()->enable();
            }
        }
    }

    /**
     * Pause offsite speedsync for migrated asset
     */
    private function assetOffsitePause()
    {
        $agentConfig = $this->agentConfigFactory->create($this->assetKeyName);
        if ($agentConfig->has(SpeedSyncMaintenanceService::ASSET_PAUSED_KEY)) {
            $this->speedSyncMaintenanceService->pauseAsset($this->assetKeyName);
        }
    }

    /**
     * Archive asset on source device
     */
    private function sourceAssetArchive()
    {
        $config = $this->agentConfigFactory->create($this->assetKeyName);
        if (!$config->isShare()) {
            $this->deviceClient->call('v1/device/asset/local/archive', ['assetKeyName' => $this->assetKeyName]);
        }
    }

    /**
     * Determine if the asset is an iSCSI share.
     * @return bool
     */
    private function isIsciShare(): bool
    {
        $config = $this->agentConfigFactory->create($this->assetKeyName);
        if ($config->isShare()) {
            $shareInfoData = unserialize($config->get('agentInfo'), ['allowed_classes' => false]);
            return $shareInfoData['isIscsi'] ?? false;
        }
        return false;
    }

    /**
     * Get the current share CHAP users defined in the iSCSI configuration from the source device.
     * @see IscsiTarget::getTargetChapUsers()
     * @return array matches the structure of IscsiTarget's getTargetChapUsers method
     *   array(
     *       UserType::INCOMING => array('username', 'password'),
     *       UserType::OUTGOING => array('username', 'password'),
     *   )
     * Note, keys are only present if there is a user associated with that UserType.
     */
    private function getSourceDeviceChapUsers(): array
    {
        if (!$this->filesystem->exists(static::ISCSI_SOURCE_COPY)) {
            // This file does not exist on older systems that use IET, so ignore failures
            $this->sshClient->copyFromRemote(IscsiTarget::LIO_CONFIG_FILE, static::ISCSI_SOURCE_COPY, false);
        }

        $sourceConfigContents = $this->filesystem->fileGetContents(static::ISCSI_SOURCE_COPY);
        if ($sourceConfigContents === false) {
            $this->logger->warning("MIG0001 Chap users for iSCSI shares will be migrated after reboot");
        } else {
            $sourceConfiguration = json_decode($sourceConfigContents, true);
            if ($sourceConfiguration === null) {
                throw new Exception('Failed to parse source iSCSI configuration JSON');
            }

            $destinationTargets = $this->iscsiTarget->listTargets();
            $assetName = strtolower($this->shareService->get($this->assetKeyName)->getName());
            $matches = array_filter(
                $sourceConfiguration['targets'],
                function ($sourceTarget) use ($destinationTargets, $assetName) {
                    // Find targets with this asset's key name that don't already exist on the destination.
                    $sourceAssetName = explode(':', $sourceTarget['wwn'])[1];
                    return $assetName === $sourceAssetName && !in_array($sourceTarget['wwn'], $destinationTargets);
                }
            );
            if (count($matches) !== 1) {
                throw new Exception('Failed to parse source iSCSI configuration JSON');
            }

            $sourceTpg = reset($matches)['tpgs'][0]; // assume that there's only one TPG per target
        }
        $chapUsers = [];

        $sourceHasIncomingUser = isset($sourceTpg['chap_userid'], $sourceTpg['chap_password'])
            && !empty($sourceTpg['chap_userid'])
            && !empty($sourceTpg['chap_password']);
        if ($sourceHasIncomingUser) {
            $chapUsers[UserType::INCOMING] = [$sourceTpg['chap_userid'], $sourceTpg['chap_password']];
        }

        $sourceHasOutgoingUser = isset($sourceTpg['chap_mutual_userid'], $sourceTpg['chap_mutual_password'])
            && !empty($sourceTpg['chap_mutual_userid'])
            && !empty($sourceTpg['chap_mutual_password']);
        if ($sourceHasOutgoingUser) {
            $chapUsers[UserType::OUTGOING] = [$sourceTpg['chap_mutual_userid'], $sourceTpg['chap_mutual_password']];
        }

        return $chapUsers;
    }
}
