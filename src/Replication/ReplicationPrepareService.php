<?php

namespace Datto\Replication;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\DirectToCloudAgentSettings;
use Datto\Asset\Agent\Encryption\EncryptionKeyStashRecord;
use Datto\Asset\Agent\EncryptionSettings;
use Datto\Asset\Agent\Serializer\DirectToCloudAgentSettingsSerializer;
use Datto\Asset\Agent\VolumesService;
use Datto\Asset\Asset;
use Datto\Asset\AssetRemovalService;
use Datto\Asset\AssetService;
use Datto\Asset\AssetType;
use Datto\Asset\OriginDevice;
use Datto\Asset\Retention;
use Datto\Asset\Serializer\LegacyOffsiteSettingsSerializer;
use Datto\Asset\Serializer\LegacyRecoveryPointsMetaSerializer;
use Datto\Asset\Serializer\LegacyRetentionSerializer;
use Datto\Asset\Serializer\OffsiteTargetSerializer;
use Datto\Asset\Serializer\OriginDeviceSerializer;
use Datto\Asset\Agent\Serializer\EncryptionSettingsSerializer;
use Datto\Asset\BackupConstraints;
use Datto\Asset\Serializer\BackupConstraintsSerializer;
use Datto\Asset\Serializer\VerificationScheduleSerializer;
use Datto\Asset\Share\ShareService;
use Datto\Asset\VerificationSchedule;
use Datto\Cloud\JsonRpcClient;
use Datto\Cloud\SpeedSync;
use Datto\Config\AgentConfig;
use Datto\Config\AgentConfigFactory;
use Datto\Config\DeviceState;
use Datto\DirectToCloud;
use Datto\System\Ssh\SshLockService;

/**
 * Prepare this Siris to receive assets and backups from another Siris.
 *
 * @author John Roland <jroland@datto.com>
 */
class ReplicationPrepareService
{
    private OriginDeviceSerializer $originDeviceSerializer;
    private SpeedSyncAuthorizedKeysService $speedSyncAuthorizedKeysService;
    private AgentConfigFactory $agentConfigFactory;
    private LegacyRetentionSerializer $retentionSerializer;
    private EncryptionSettingsSerializer $encryptionSettingsSerializer;
    private DirectToCloudAgentSettingsSerializer $directToCloudAgentSettingsSerializer;
    private OffsiteTargetSerializer $offsiteTargetSerializer;
    private BackupConstraintsSerializer $backupConstraintsSerializer;
    private LegacyRecoveryPointsMetaSerializer $recoveryPointsMetaSerializer;
    private VerificationScheduleSerializer $verificationScheduleSerializer;
    private AgentService $agentService;
    private AssetService $assetService;
    private ShareService $shareService;
    private DeviceState $deviceState;
    private SshLockService $sshLockService;
    private JsonRpcClient $client;
    private VolumesService $volumesService;

    public function __construct(
        OriginDeviceSerializer $originDeviceSerializer,
        SpeedSyncAuthorizedKeysService $speedSyncAuthorizedKeysService,
        AgentConfigFactory $agentConfigFactory,
        LegacyRetentionSerializer $retentionSerializer,
        EncryptionSettingsSerializer $encryptionSettingsSerializer,
        DirectToCloudAgentSettingsSerializer $directToCloudAgentSettingsSerializer,
        OffsiteTargetSerializer $offsiteTargetSerializer,
        BackupConstraintsSerializer $backupConstraintsSerializer,
        LegacyRecoveryPointsMetaSerializer $recoveryPointsMetaSerializer,
        VerificationScheduleSerializer $verificationScheduleSerializer,
        AgentService $agentService,
        AssetService $assetService,
        ShareService $shareService,
        DeviceState $deviceState,
        SshLockService $sshLockService,
        JsonRpcClient $client,
        VolumesService $volumesService
    ) {
        $this->originDeviceSerializer = $originDeviceSerializer;
        $this->speedSyncAuthorizedKeysService = $speedSyncAuthorizedKeysService;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->retentionSerializer = $retentionSerializer;
        $this->encryptionSettingsSerializer = $encryptionSettingsSerializer;
        $this->directToCloudAgentSettingsSerializer = $directToCloudAgentSettingsSerializer;
        $this->offsiteTargetSerializer = $offsiteTargetSerializer;
        $this->backupConstraintsSerializer = $backupConstraintsSerializer;
        $this->recoveryPointsMetaSerializer = $recoveryPointsMetaSerializer;
        $this->verificationScheduleSerializer = $verificationScheduleSerializer;
        $this->agentService = $agentService;
        $this->assetService = $assetService;
        $this->shareService = $shareService;
        $this->deviceState = $deviceState;
        $this->sshLockService = $sshLockService;
        $this->client = $client;
        $this->volumesService = $volumesService;
    }

    /**
     * Prepare the current device to receive backups from the specified device and asset.
     *
     * @param int $primaryDeviceId
     * @param string $publicKey
     * @param AssetMetadata $assetMetadata
     * @return bool
     */
    public function provisionAsset(
        int $primaryDeviceId,
        string $publicKey,
        AssetMetadata $assetMetadata
    ) {
        // Add public key and asset
        $this->speedSyncAuthorizedKeysService->add($publicKey, $primaryDeviceId, $assetMetadata->getAssetKey());

        // Create Asset key files
        $this->setUpAssetFiles($assetMetadata);

        // Update SSH service status
        $this->updateSpeedSyncActiveState();
        $this->sshLockService->updateSshdStatus();

        return true;
    }

    /**
     * the asset key from the list of allowed assets for a particular public key, and if no assets
     * remain, the public key will be removed from the authorized keys file.
     * Update and save existing replicated asset with new asset properties
     *
     * @param $primaryDeviceId
     * @param $publicKey
     * @param Asset $existingAsset
     * @param AssetMetadata $assetMetadata
     * @param AssetSettings|null $assetSettings
     */
    public function updateAsset(
        $primaryDeviceId,
        $publicKey,
        Asset $existingAsset,
        AssetMetadata $assetMetadata,
        AssetSettings $assetSettings = null
    ) {
        $assetKey = $existingAsset->getKeyName();
        $agentConfig = $this->agentConfigFactory->create($assetKey);

        $this->speedSyncAuthorizedKeysService->add($publicKey, $primaryDeviceId, $assetKey);

        if ($existingAsset->isType(AssetType::AGENT)) {
            /** @var Agent $agent */
            $agent = $existingAsset;
            $agent->setHostname($assetMetadata->getHostname());
            $agent->setFullyQualifiedDomainName($assetMetadata->getFqdn());
            $agent->getDriver()->setAgentVersion($assetMetadata->getAgentVersion());
            // rawAgentInfo['os'] is a concatenation of name and version.
            // since we only get a single value, we will store it in name for now
            $agent->getOperatingSystem()->setName($assetMetadata->getOperatingSystem());

            if ($assetSettings) {
                $agent->getLocal()->setPaused($assetSettings->isPaused());
                $agent->getLocal()->setPauseUntil($assetSettings->getPauseUntil());
                $agent->getLocal()->setPauseWhileMetered($assetSettings->isPauseWhileMetered());
                $agent->getLocal()->setMaximumBandwidth($assetSettings->getMaxBandwidthInBits());
                $agent->getLocal()->setMaximumThrottledBandwidth($assetSettings->getMaxThrottledBandwidthInBits());
            }

            $agent->getLocal()->setMigrationInProgress($assetMetadata->getIsMigrationInProgress());

            // Must save before setting values via agentConfig, otherwise the changes will be overwritten
            $this->agentService->save($agent);

            $keyStashArray = $assetMetadata->getEncryptionKeyStash();
            if ($keyStashArray) {
                $encryptionKeyStash = EncryptionKeyStashRecord::createFromArray($keyStashArray, $includeKeys = true);
                $agentConfig->saveRecord($encryptionKeyStash);
                $agentConfig->set('encryption', 1);
            } else {
                $agentConfig->clear('encryption');
                $agentConfig->clear('encryptionKeyStash');
            }
        } elseif ($existingAsset->isType(AssetType::SHARE)) {
            $share = $existingAsset;
            $share->setName($assetMetadata->getDisplayName());

            $this->shareService->save($share);
        }

        $this->setOriginDevice($agentConfig, $assetMetadata);
    }

    /**
     * @param Asset $asset
     * @param int $snapshot
     */
    public function updateRecoveryPointInfo(Asset $asset, int $snapshot)
    {
        $assetKey = $asset->getKeyName();

        $recoveryPointArray = $this->client->queryWithId('v1/device/asset/recoveryPoints/getRecoveryPointInfo', [
            'assetKey' => $assetKey,
            'snapshot' => $snapshot
        ]);

        $recoveryPoint = $this->recoveryPointsMetaSerializer->singleFromArray(
            $assetKey,
            $snapshot,
            $recoveryPointArray
        );
        $asset->getLocal()->getRecoveryPoints()->set($recoveryPoint);

        $this->assetService->save($asset);
    }

    /**
     * Updates include keyfiles for the agent
     * when reconciling a replicated point.
     *
     * @param Asset $agent Agent to update volume info
     * @param AssetSettings|null $assetSettings Configured settings for this replicated point
     */
    public function updateVolumeInfo(Asset $agent, AssetSettings $assetSettings = null)
    {
        $volumes = [];
        if ($assetSettings) {
            $volumes = $assetSettings->getIncludedVolumes();
        }
        if (count($volumes > 0)) {
            foreach ($volumes as $volume) {
                if ($volume['isIncluded'] && !empty($volume['guid'])) {
                    $this->volumesService->includeByGuid($agent->getKeyName(), $volume['guid']);
                }
            }
        }
    }

    /**
     * @param Asset $asset
     */
    public function updateAllRecoveryPointInfo(Asset $asset)
    {
        $points = $this->client->queryWithId('v1/device/asset/recoveryPoints/getAllRecoveryPointInfo', [
            'assetKey' => $asset->getKeyName()
        ]);

        $currentPoints = $asset->getLocal()->getRecoveryPoints();
        $newPoints = $this->recoveryPointsMetaSerializer->fromArray($asset->getKeyName(), $points);

        // Remove points that don't exist locally
        foreach ($newPoints->getAllRecoveryPointTimes() as $epoch) {
            if (!$currentPoints->exists($epoch)) {
                $newPoints->remove($epoch);
            }
        }

        $asset->getLocal()->setRecoveryPoints($newPoints);
        $this->assetService->save($asset);
    }

    /**
     * Disable replication for existing replicated asset.
     * Does not touch dataset or key metadata.
     *
     * @param string $uuid
     */
    public function disableAsset(string $uuid)
    {
        $this->speedSyncAuthorizedKeysService->remove($uuid);
    }

    /**
     * Updates value of 'SpeedSyncActive' device state with boolean of whether any incoming P2P devices are active.
     */
    public function updateSpeedSyncActiveState()
    {
        // Determine if any P2P assets are paired, which needs SSHD running
        $p2pAssets = $this->assetService->getAllActiveReplicated();

        $this->deviceState->set(DeviceState::SPEED_SYNC_ACTIVE, !empty($p2pAssets));
    }

    /**
     * Create basic set of asset config files
     *
     * @param AssetMetadata $assetMetadata
     */
    private function setUpAssetFiles(AssetMetadata $assetMetadata)
    {
        /*
         * FIXME: I really wish there was a better way to do this but initial key file creation
         *     is still done via AgentConfig and direct writes to files, not the Asset model.
         *     The Asset model and service were not designed to handle creation.
         *     This should get addressed when pairing is refactored.
         */
        $agentConfig = $this->agentConfigFactory->create($assetMetadata->getAssetKey());

        $assetType = AssetType::determineAssetType(
            $assetMetadata->getDatasetPurpose(),
            $assetMetadata->getAgentPlatform(),
            $assetMetadata->getOperatingSystem()
        );

        // .agentInfo
        $agentInfo = [];
        $agentInfo['hostname'] = $assetMetadata->getHostname();
        $agentInfo['name'] = $assetMetadata->getAssetKey();
        $agentInfo['uuid'] = $assetMetadata->getAssetKey();
        $agentInfo['localUsed'] = 0;
        $agentInfo['usedBySnaps'] = 0;

        if (AssetType::isShare($assetType)) {
            $agentInfo['name'] = $assetMetadata->getDisplayName();
            $agentInfo['type'] = 'snapnas';
            $agentInfo['shareType'] = $assetType;
            $agentInfo['isIscsi'] = $assetType === AssetType::ISCSI_SHARE ? 1 : 0;
        } else {
            $agentInfo['fqdn'] = $assetMetadata->getFqdn();
            $agentInfo['type'] = $assetType;
            $agentInfo['os'] = $assetMetadata->getOperatingSystem();
            $agentInfo['os_name'] = $assetMetadata->getOperatingSystem();

            if ($assetMetadata->getAgentPlatform() === AgentPlatform::SHADOWSNAP()) {
                $agentConfig->setRaw('shadowSnap', null);
            } elseif ($assetMetadata->getAgentPlatform() === AgentPlatform::DIRECT_TO_CLOUD()) {
                // TODO: revisit when we have DTC specific settings, for now at least make sure we can identify it
                $directToCloudSettings = new DirectToCloudAgentSettings();
                $serialized = $this->directToCloudAgentSettingsSerializer->serialize($directToCloudSettings);
                $fileKey = DirectToCloudAgentSettingsSerializer::FILE_KEY;
                $agentConfig->set($fileKey, $serialized[$fileKey]);
            }
        }

        // Clear any stale removing keys
        $agentConfig->clear(AssetRemovalService::REMOVING_KEY);

        $serializedAgentInfo = serialize($agentInfo);
        $agentConfig->set('agentInfo', $serializedAgentInfo);

        // .originDevice
        $this->setOriginDevice($agentConfig, $assetMetadata);

        // .offsiteControl
        $agentConfig->set(
            'offsiteControl',
            json_encode([
                'interval' => LegacyOffsiteSettingsSerializer::REPLICATION_ALWAYS,
                'priority' => LegacyOffsiteSettingsSerializer::LEGACY_PRIORITY_NORMAL
            ])
        );

        if ($assetMetadata->getAgentPlatform() === AgentPlatform::DIRECT_TO_CLOUD()) {
            // DTC agents have hardcoded retention schedules. For DTC migrations, replicated assets are promoted to "real"
            // assets and thus need a valid retention schedule. We opted to not migrate the settings from the source as
            // they are not configurable.
            $retention = Retention::createTimeBased(DirectToCloud\Creation\Service::TIME_BASED_RETENTION_YEARS);

            // .retention
            $agentConfig->set(
                'retention',
                $this->retentionSerializer->serialize($retention)
            );

            // DTC agents only verify the first point of the day. For DTC migrations, replicated assets are promoted to 'real'
            // assets and need this valid verification schedule as well. We opted to not migrate the settings from the source
            // as they are not configurable.
            $verificationSchedule = new VerificationSchedule(VerificationSchedule::FIRST_POINT);
            $agentConfig->set(
                VerificationScheduleSerializer::FILE_KEY,
                $this->verificationScheduleSerializer->serialize($verificationSchedule)[VerificationScheduleSerializer::FILE_KEY]
            );

            // .offsiteRetention
            $agentConfig->set(
                'offsiteRetention',
                $this->retentionSerializer->serialize($retention)
            );
        }

        $encryptionSettings = new EncryptionSettings(
            $assetMetadata->getEncryption(),
            null,
            null
        );
        $serializedEncryptionSettings = $this->encryptionSettingsSerializer->serialize($encryptionSettings);

        // .encryption
        $agentConfig->set(
            'encryption',
            $serializedEncryptionSettings['encryption']
        );

        // .encryptionKeyStash
        $agentConfig->set(
            'encryptionKeyStash',
            $serializedEncryptionSettings['encryptionKeyStash']
        );

        // .offsiteTarget
        $agentConfig->set(
            'offsiteTarget',
            $this->offsiteTargetSerializer->serialize(SpeedSync::TARGET_NO_OFFSITE)
        );

        // .backupConstraints
        $serializedBackupConstraints = $this->backupConstraintsSerializer->serialize(
            new BackupConstraints(BackupConstraints::DEFAULT_MAX_TOTAL_VOLUME_SIZE_IN_BYTES)
        );
        $agentConfig->set(BackupConstraintsSerializer::KEY, $serializedBackupConstraints);
        $agentConfig->set('migrationInProgress', $assetMetadata->getIsMigrationInProgress());
    }

    /**
     * @param AgentConfig $agentConfig
     * @param AssetMetadata $assetMetadata
     */
    private function setOriginDevice(AgentConfig $agentConfig, AssetMetadata $assetMetadata)
    {
        $originDevice = new OriginDevice(
            $assetMetadata->getOriginDeviceId(),
            $assetMetadata->getOriginResellerId(),
            true
        );
        $serializedOriginDevice = $this->originDeviceSerializer->serialize($originDevice);
        $agentConfig->set(
            OriginDeviceSerializer::FILE_KEY,
            $serializedOriginDevice[OriginDeviceSerializer::FILE_KEY]
        );
    }
}
