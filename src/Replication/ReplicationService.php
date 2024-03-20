<?php

namespace Datto\Replication;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\DiffMergeService;
use Datto\Asset\Asset;
use Datto\Asset\AssetCollection;
use Datto\Asset\AssetInfoSyncService;
use Datto\Asset\AssetRemovalService;
use Datto\Asset\AssetService;
use Datto\Asset\AssetType;
use Datto\Asset\DatasetPurpose;
use Datto\Asset\RecoveryPoint\RecoveryPointInfoService;
use Datto\Asset\Share\ExternalNas\ExternalNasShare;
use Datto\Cloud\SpeedSync;
use Datto\Cloud\JsonRpcClient;
use Datto\Config\AgentConfigFactory;
use Datto\Config\DeviceConfig;
use Datto\Config\DeviceState;
use Datto\Dataset\ZVolDataset;
use Datto\Feature\FeatureService;
use Datto\Log\LoggerAwareTrait;
use Datto\Https\HttpsService;
use Datto\Common\Utility\Filesystem;
use Datto\Service\Registration\SshKeyService;
use Datto\System\Ssh\SshLockService;
use Datto\Util\RetryHandler;
use Datto\Utility\ByteUnit;
use Datto\ZFS\ZfsDatasetService;
use Exception;
use Psr\Log\LoggerAwareInterface;
use RuntimeException;
use Throwable;

/**
 * Service to manage information related to peer replication feature.
 *
 * @author Jack Corrigan <jcorrigan@datto.com>
 * @author Dawid Zamirski <dzamirski@datto.com>
 */
class ReplicationService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private JsonRpcClient $client;

    private AssetService $assetService;

    private ReplicationPrepareService $replicationPrepareService;

    private DeviceState $deviceState;

    private HttpsService $httpsService;

    private RecoveryPointInfoService $recoveryPointService;

    private FeatureService $featureService;

    private ZfsDatasetService $zfsDatasetService;

    private AgentConfigFactory $agentConfigFactory;

    private Filesystem $filesystem;

    private SshLockService $sshLockService;

    private RetryHandler $retryHandler;

    private AssetInfoSyncService $assetInfoSyncService;

    private DeviceConfig $deviceConfig;

    private AssetRemovalService $assetRemovalService;

    private SpeedSync $speedsync;

    private DiffMergeService $diffMergeService;

    public function __construct(
        JsonRpcClient $client,
        AssetService $assetService,
        ReplicationPrepareService $replicationPrepareService,
        DeviceState $deviceState,
        HttpsService $httpsService,
        RecoveryPointInfoService $recoveryPointService,
        FeatureService $featureService,
        ZfsDatasetService $zfsDatasetService,
        AgentConfigFactory $agentConfigFactory,
        Filesystem $filesystem,
        SshLockService $sshLockService,
        RetryHandler $retryHandler,
        AssetInfoSyncService $assetInfoSyncService,
        DeviceConfig $deviceConfig,
        AssetRemovalService $assetRemovalService,
        SpeedSync $speedsync,
        DiffMergeService $diffMergeService
    ) {
        $this->client = $client;
        $this->assetService = $assetService;
        $this->replicationPrepareService = $replicationPrepareService;
        $this->deviceState = $deviceState;
        $this->httpsService = $httpsService;
        $this->recoveryPointService = $recoveryPointService;
        $this->featureService = $featureService;
        $this->zfsDatasetService = $zfsDatasetService;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->filesystem = $filesystem;
        $this->sshLockService = $sshLockService;
        $this->retryHandler = $retryHandler;
        $this->assetInfoSyncService = $assetInfoSyncService;
        $this->deviceConfig = $deviceConfig;
        $this->assetRemovalService = $assetRemovalService;
        $this->speedsync = $speedsync;
        $this->diffMergeService = $diffMergeService;
    }

    /**
     * Returns a list of speedsync, outbound paired targets
     *
     * Example response:
     * array (
     *   216582 => array (
     *     'deviceID' => '216582',
     *     'resellerID' => '0',
     *     'hostname' => 'BIT-SHIFT',
     *     'publicRSA' => 'ssh-rsa A... root@BIT-SHIFT',
     *     'ddnsDomain' => 'k7y8pmhck6.dattolocal.net'
     *   )
     * )
     *
     * @return array
     */
    public function getAuthorizedTargets(): array
    {
        $authorized = $this->client->queryWithId("v1/device/asset/replication/getOutboundPaired");

        // Store the outbound devices locally for display later
        $replicationDevices = ReplicationDevices::createOutboundReplicationDevices();
        $replicationDevices->setDevices($authorized);
        $this->deviceState->saveRecord($replicationDevices);

        return $authorized;
    }

    /**
     * Fetch an updated list of inbound devices from device-web and save it
     */
    public function refreshInboundDevices()
    {
        $results = $this->client->queryWithId('v1/device/asset/replication/getInbound');
        $devices = $results['devices'];

        $replicationDevices = ReplicationDevices::createInboundReplicationDevices();
        $replicationDevices->setDevices($devices);
        $this->deviceState->saveRecord($replicationDevices);
    }

    /**
     * Throw an exception if the api can't connect to the device specified by the ddns domain name of the target.
     *
     * @param string $targetDeviceId
     */
    public function assertDeviceReachable(string $targetDeviceId)
    {
        $context = ['targetDeviceId' => $targetDeviceId];
        $this->logger->info("REP0013 Checking if device is authorized and reachable via https.", $context);

        if (empty($targetDeviceId)) {
            throw new Exception('Empty target device id.');
        }

        $devices = ReplicationDevices::createOutboundReplicationDevices();
        if (!$this->deviceState->loadRecord($devices) || $devices->getDevice($targetDeviceId) === null) {
            // File is empty or we can't find the target device, refresh the list from device-web and try again
            $this->getAuthorizedTargets();
            $this->deviceState->loadRecord($devices);
        }

        $targetDevice = $devices->getDevice($targetDeviceId);

        if ($targetDevice === null) {
            throw new Exception("Could not find an authorized outbound device that matches ($targetDeviceId)");
        }

        try {
            $url = "https://{$targetDevice->getDdnsDomain()}/";
            $context['url'] = $url;
            $this->logger->info("REP0012 Checking connectivity for target device", $context);
            $this->httpsService->checkConnectivity($url);
        } catch (Throwable $e) {
            $this->logger->warning(
                'REP0023 Unable to connect to target siris over the network.  If using an override for target sync IP, this may be ok.',
                ['targetDeviceId' => $targetDeviceId, 'hostname' => $targetDevice->getHostname(), 'ddnsDomain' => $targetDevice->getDdnsDomain(), 'connectionError' => $e->getMessage()]
            );
        }
    }

    /**
     *  Get list of configured incoming replicated assets and ensure they are reconciled on the device.
     *
     * @param bool $refreshSpeedsyncMetadata Refresh speedsync metadata so that it picks up on any newly added or
     *      removed vectors.
     * @param string[]|null $reconcileAssetKeys If supplied, only reconcile these assets.
     */
    public function reconcileAssets(bool $refreshSpeedsyncMetadata = false, array $reconcileAssetKeys = null)
    {
        $this->logger->debug('REP0003 Beginning replicated asset reconciliation');
        try {
            $existingAssets = new AssetCollection($this->assetService->getAll());

            $results = $this->client->queryWithId('v1/device/asset/replication/getInbound');
            $inboundAssets = $results['assets'];
            $devices = $results['devices'];

            $replicationDevices = ReplicationDevices::createInboundReplicationDevices();
            $replicationDevices->setDevices($devices);
            $this->deviceState->saveRecord($replicationDevices);

            foreach ($inboundAssets as $inboundAsset) {
                if ($reconcileAssetKeys === null || in_array($inboundAsset['uuid'], $reconcileAssetKeys)) {
                    $existingAsset = $existingAssets->selectByUuid($inboundAsset['uuid']);
                    $this->reconcileReplicated($devices, $inboundAsset, $existingAsset);
                }
            }

            $this->disableOrphans($existingAssets, $inboundAssets);

            // Make sure SSH is on and running if any assets being replicated
            $this->replicationPrepareService->updateSpeedSyncActiveState();
            $this->sshLockService->updateSshdStatus();

            if ($refreshSpeedsyncMetadata) {
                $this->speedsync->refreshInboundAndOutboundMetadata();
            }

            $this->logger->debug("REP0004 Completed replicated asset reconciliation.");
        } catch (Throwable $e) {
            $this->logger->error("REP0005 Error occurred during asset reconciliation", ['exception' => $e]);

            throw $e;
        }
    }

    /**
     * Update asset statistical information after speedsync receives replicated point.
     *
     * @param Asset $asset
     * @param int|null $snapshot
     */
    public function reconcileReplicatedAssetInfo(Asset $asset, int $snapshot = null)
    {
        if ($asset->getOriginDevice()->isReplicated() === false) {
            throw new RuntimeException('Provided asset is not a replicated asset');
        }

        $this->recoveryPointService->refreshKeys($asset);

        if ($asset->isType(AssetType::AGENT)) {
            // replicated assets arrive unmounted, however we require agents to be mounted for other code to work.
            $this->zfsDatasetService->mountDatasets();

            // update 'Local Used' stats in agentInfo
            $datasetPath = sprintf(
                '%s/%s',
                ZfsDatasetService::HOMEPOOL_HOME_AGENTS_DATASET_PATH,
                $asset->getKeyName()
            );
            $dataset = $this->zfsDatasetService->getDataset($datasetPath);

            $localUsed = $dataset->getUsedSpace();
            $usedBySnaps = $dataset->getUsedSpaceBySnapshots();
            $localUsed = round(ByteUnit::BYTE()->toGiB($localUsed), 2);
            $usedBySnaps = round(ByteUnit::BYTE()->toGiB($usedBySnaps), 2);

            $agentConfig = $this->agentConfigFactory->create($asset->getKeyName());

            // ugly way to update, but I don't see anything better available.
            $info = @unserialize($agentConfig->get('agentInfo'), ['allowed_classes' => false]);

            if ($info) {
                $info['localUsed'] = $localUsed;
                $info['usedBySnaps'] = $usedBySnaps;

                $agentConfig->set('agentInfo', serialize($info));
            }
        } elseif ($asset->isType(AssetType::EXTERNAL_NAS_SHARE)) {
            // set filesystem and backupAcls since external nas shares aren't
            // provisioned with this info
            /** @var ZVolDataset $dataset */
            $dataset = $asset->getDataset();
            if ($this->filesystem->exists($dataset->getPartitionBlockLink())) {
                /** @var ExternalNasShare $asset */
                $filesystem = $asset->getDataset()->getPartitionFormat();
                if ($filesystem !== null) {
                    $asset->setFormat($filesystem);
                    if ($filesystem === 'ntfs') {
                        $asset->setBackupAcls(true);
                    }
                    $this->assetService->save($asset);
                }
            }
        }

        $snapshotProvided = $snapshot !== null;
        $syncSupported = $this->featureService->isSupported(FeatureService::FEATURE_REPLICATION_RECOVERY_POINT_SYNC);
        if ($snapshotProvided && $syncSupported) {
            $this->replicationPrepareService->updateRecoveryPointInfo($asset, $snapshot);
        }
    }

    /**
     * @param int $primaryDeviceId
     * @param string $publicKey
     * @param AssetMetadata $assetMetadata
     */
    public function provision(int $primaryDeviceId, string $publicKey, AssetMetadata $assetMetadata)
    {
        $this->replicationPrepareService->provisionAsset(
            $primaryDeviceId,
            $publicKey,
            $assetMetadata
        );

        $this->refreshInboundDevices();
    }

    /**
     * @param Asset $asset
     */
    public function deprovision(Asset $asset)
    {
        // Call removeAssetMetadata instead of removeAsset so that we do not unintentionally delete the dataset.
        // Replicated asset dataset deletion should always be initiated from the sender side via speedsync.
        $this->assetRemovalService->removeAssetMetadata($asset->getKeyName(), $force = true);
    }

    /**
     * @param Asset $asset
     */
    public function promote(Asset $asset)
    {
        $this->featureService->assertSupported(FeatureService::FEATURE_REPLICATION_PROMOTE, null, $asset);
        $context = ['assetKey' => $asset->getKeyName()];

        if (!$asset->getOriginDevice()->isReplicated()) {
            $this->logger->info("REP0014 Asset is already a non-replicated asset, nothing to do", $context);
            return;
        }

        $this->logger->info("REP0017 Updating replicated asset with latest information from source", $context);
        $this->reconcileAssets(true, [$asset->getKeyName()]);
        //fetch latest asset info from disk, handle to $asset object might be stale to info on disk
        $asset = $this->assetService->get($asset->getKeyName());

        $this->logger->info("REP0015 Preparing asset dataset for backups", $context);
        // Create dataset if it doesn't exist
        $dataset = $this->zfsDatasetService->findDataset($asset->getDataset()->getZfsPath());
        if (!$dataset) {
            $this->logger->info("REP0016 Asset dataset did not exist", $context);
            $this->zfsDatasetService->createDataset($asset->getDataset()->getZfsPath());
        }

        $this->logger->info("REP0018 Converting asset into a non-replicated asset", $context);
        $this->replicationPrepareService->disableAsset($asset->getKeyName());

        $originDevice = $asset->getOriginDevice();
        $originDevice->setDeviceId($this->deviceConfig->getDeviceId());
        $originDevice->setReplicated(false);
        if ($asset->supportsDiffMerge()) {
            $this->logger->info("REP0027 Forcing differential merge on asset", $context);
            $this->diffMergeService->setDiffMergeAllVolumes($asset->getKeyName());
        }
        $this->assetService->save($asset);

        $this->logger->info("REP0019 Syncing asset with device-web", $context);
        $this->assetInfoSyncService->sync($asset->getKeyName());
    }

    /**
     * @param int $primaryDeviceId
     * @param Asset $asset
     * @param string $publicKey
     * @param AssetMetadata $assetMetadata
     */
    public function demote(int $primaryDeviceId, Asset $asset, string $publicKey, AssetMetadata $assetMetadata)
    {
        $this->featureService->assertSupported(FeatureService::FEATURE_REPLICATION_PROMOTE, null, $asset);
        $context = ['assetKey' => $asset->getKeyName()];

        if ($asset->getOriginDevice()->isReplicated()) {
            $this->logger->info("REP0020 Asset is already a replicated asset, nothing to do", $context);
            return;
        }

        $this->logger->info("REP0024 Converting asset into a non-replicated asset", $context);
        $this->replicationPrepareService->updateAsset(
            $primaryDeviceId,
            $publicKey,
            $asset,
            $assetMetadata,
            null // don't care about updating asset settings when demoting
        );

        $this->logger->info("REP0021 Syncing asset with device-web", $context);
        $this->assetInfoSyncService->sync($asset->getKeyName());

        $this->logger->info("REP0022 Preparing asset dataset for receiving replicated data", $context);
        $this->prepareDatasetForReceiving($asset);
    }

    /**
     * Run speedsync adhoc for given asset
     *
     * @param Asset $asset
     * @param string $host
     */
    public function adhocReceive(Asset $asset, string $host)
    {
        $zfsPath = $asset->getDataset()->getZfsPath();
        $this->logger->info("REP0028 Initiating adhoc receive", ['assetKey' => $asset->getKeyName()]);

        $this->speedsync->adhocReceiveDataset(
            SshKeyService::SSH_PRIVATE_KEY_FILE,
            $zfsPath,
            $host,
            SpeedSync::SPEEDSYNC_USER,
            $zfsPath
        );
    }

    /**
     * Provision or update a replicated asset.
     * If the asset exists it is updated, if it does not exist it is provisioned.
     *
     * @param array $devices
     * @param array $inboundAsset the list of replicated assets from the cloud api
     * @param Asset|null $existingAsset
     */
    private function reconcileReplicated(array $devices, array $inboundAsset, Asset $existingAsset = null)
    {
        $uuid = $inboundAsset['uuid'];
        $this->logger->info(
            "REP0029 Attempting to reconcile replicated assets",
            ['rawDevices' => json_encode($devices), 'rawInboundAsset' => json_encode($inboundAsset)]
        );

        try {
            $sourceDeviceId = $inboundAsset['sourceDeviceId'];
            $originDeviceId = $inboundAsset['originDeviceId'];
            $sourceDevicePublicKey = $devices[$sourceDeviceId]['publicRSA'];
            $originDevice = $devices[$originDeviceId];
            $assetMetadata = $this->createAssetMetadata($inboundAsset, $originDevice);
            $assetSettings = isset($inboundAsset['settings']) ?
                $this->createAssetSettings($inboundAsset['settings']) : null;

            if (is_null($existingAsset)) {
                $this->logger->info("REP0006 Provisioning replicated asset", ['assetKey' => $uuid]);
                $this->replicationPrepareService->provisionAsset(
                    $sourceDeviceId,
                    $sourceDevicePublicKey,
                    $assetMetadata
                );
            } elseif ($existingAsset->getOriginDevice()->isReplicated()) {
                $this->logger->info(
                    "REP0007 Updating replicated asset",
                    ['assetKey' => $uuid]
                );
                $this->replicationPrepareService->updateAsset(
                    $sourceDeviceId,
                    $sourceDevicePublicKey,
                    $existingAsset,
                    $assetMetadata,
                    $assetSettings
                );

                if ($this->featureService->isSupported(FeatureService::FEATURE_REPLICATION_RECOVERY_POINT_SYNC)) {
                    $this->replicationPrepareService->updateAllRecoveryPointInfo($existingAsset);
                }

                if ($this->featureService->isSupported(
                    FeatureService::FEATURE_REPLICATION_VOLUMES_SYNC,
                    null,
                    $existingAsset
                )) {
                    $this->replicationPrepareService->updateVolumeInfo($existingAsset, $assetSettings);
                }
            } else {
                // error, the asset already exists as local, this should not happen
                $this->logger->error(
                    "REP0008 Error reconciling replicated asset, it already exists as a local asset",
                    ['assetKey' => $uuid]
                );
            }
        } catch (Throwable $e) {
            $this->logger->error("REP0009 Error reconciling replicated asset", ['assetKey' => $uuid, 'exception' => $e]);
        }
    }

    /**
     * Find replicated assets on the device which are not included in the cloud api response, and disable replication.
     *
     * @param AssetCollection $existingAssets
     * @param array $inboundAssets
     */
    private function disableOrphans(AssetCollection $existingAssets, array $inboundAssets)
    {
        $orphanAssets = $existingAssets
            ->whereIsReplicated()
            ->exceptUuid(array_column($inboundAssets, 'uuid'));

        /** @var Asset $orphan */
        foreach ($orphanAssets as $orphan) {
            $uuid = $orphan->getUuid();
            try {
                $this->logger->warning("REP0010 Disabling replication for orphan asset", ['assetKey' => $uuid]);
                $this->replicationPrepareService->disableAsset($uuid);

                // Reload the changes we saved to disk during the reconcile to prevent clobbering them when we save here
                $orphan = $this->assetService->get($uuid);
                $orphan->getOriginDevice()->setOrphaned(true);
                $this->assetService->save($orphan);
            } catch (Throwable $e) {
                $this->logger->error("REP0011 Error disabling orphan replicated asset", ['assetKey' => $uuid, 'exception' => $e]);
            }
        }
    }

    /**
     * Create AssetMetadata from DWI api response
     * @param array $asset
     * @param array $originDevice
     * @return AssetMetadata
     */
    private function createAssetMetadata(array $asset, array $originDevice): AssetMetadata
    {
        $this->logger->info(
            "REP0030 Creating asset metadata from inboundAsset",
            ['rawInboundAsset' => json_encode($asset), 'rawOriginDevice' => json_encode($originDevice)]
        );
        $assetMetadata = new AssetMetadata($asset['uuid']);

        if (!isset($asset['type'])) {
            throw new Exception('The following field is required for asset metadata: type');
        }

        $datasetPurpose = DatasetPurpose::memberOrNullByValue($asset['type']);
        $assetMetadata->setDatasetPurpose($datasetPurpose);

        $agentPlatform = AgentPlatform::memberOrNullByValue(empty($asset['agentType']) ? null : $asset['agentType']);
        $assetMetadata->setAgentPlatform($agentPlatform);

        $assetMetadata->setHostname($asset['hostname'] ?? null);
        $assetMetadata->setFqdn($asset['fqdn'] ?? null);
        $assetMetadata->setDisplayName($asset['displayName'] ?? null);
        $assetMetadata->setOperatingSystem($asset['os'] ?? null);
        $assetMetadata->setOriginDeviceId($asset['originDeviceId'] ?? null);
        $assetMetadata->setOriginResellerId($originDevice['resellerID'] ?? null);
        $assetMetadata->setEncryption($asset['isEncrypted'] ?? false);
        $assetMetadata->setEncryptionKeyStash($asset['encryptionKeyStash']);
        $assetMetadata->setAgentVersion($asset['agentVersion'] ?? null);
        $assetMetadata->setIsMigrationInProgress($asset['settings']['isMigrationInProgress'] ?? false);

        return $assetMetadata;
    }

    /**
     * Create AssetSettings from DWI api response
     * @param array $settings
     * @return AssetSettings
     */
    public function createAssetSettings(array $settings): AssetSettings
    {
        $assetSettings = new AssetSettings();
        $this->logger->info("REP0031 Creating asset settings from inboundAsset settings", ['inboundAssetSettings' => json_encode($settings)]);

        $assetSettings->setPaused($settings['paused'] ?? false);
        $assetSettings->setPauseUntil($settings['pauseUntil'] ?? null);
        $assetSettings->setPauseWhileMetered($settings['pauseWhileMetered'] ?? false);
        $assetSettings->setMaxBandwidthInBits($settings['maxBandwidth'] ?? null);
        $assetSettings->setMaxThrottledBandwidthInBits($settings['maxThrottledBandwidth'] ?? null);
        $assetSettings->setIncludedVolumes($settings['volumes']['included'] ?? []);

        return $assetSettings;
    }

    /**
     * @param Asset $asset
     */
    private function prepareDatasetForReceiving(Asset $asset)
    {
        // If there are NO snapshots, destroy the dataset so that speedsync can receive a zfs send stream.
        $recoveryPointCount = count($asset->getLocal()->getRecoveryPoints()->getAll());
        if ($recoveryPointCount === 0) {
            $this->logger->info(
                "REP0026 Asset dataset has no snapshots, destroying it",
                ['assetKey' => $asset->getKeyName()]
            );

            $dataset = null;

            $this->retryHandler->executeAllowRetry(function () use ($asset, $dataset) {
                if (!$dataset) {
                    $dataset = $this->zfsDatasetService->findDataset($asset->getDataset()->getZfsPath());
                }

                if ($dataset) {
                    $this->zfsDatasetService->destroyDataset($dataset);
                }
            });
        }
    }
}
