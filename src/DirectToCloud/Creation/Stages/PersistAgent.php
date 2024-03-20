<?php

namespace Datto\DirectToCloud\Creation\Stages;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\DirectToCloudAgentSettings;
use Datto\Asset\Agent\Serializer\DirectToCloudAgentSettingsSerializer;
use Datto\Asset\AssetRemovalService;
use Datto\Asset\AssetType;
use Datto\Asset\BackupConstraints;
use Datto\Asset\LocalSettings;
use Datto\Asset\OffsiteSettings;
use Datto\Asset\OriginDevice;
use Datto\Asset\Serializer\BackupConstraintsSerializer;
use Datto\Asset\Serializer\LegacyLocalSettingsSerializer;
use Datto\Asset\Serializer\LegacyOffsiteSettingsSerializer;
use Datto\Asset\Serializer\OriginDeviceSerializer;
use Datto\Asset\Serializer\VerificationScheduleSerializer;
use Datto\Config\AgentConfigFactory;
use Datto\Config\DeviceConfig;
use Datto\Core\Configuration\ConfigRecordInterface;
use Datto\DirectToCloud\Creation\Context;
use Datto\Core\Asset\Configuration\WeeklySchedule;
use Datto\Feature\FeatureService;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Datto\Log\DeviceLoggerInterface;

/**
 * Persist any applicable configuration and metadata for an Agent asset.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class PersistAgent extends AbstractStage
{
    private AgentService $agentService;
    private AgentConfigFactory $agentConfigFactory;
    private DateTimeService $dateTimeService;
    private Filesystem $filesystem;
    private DeviceConfig $deviceConfig;
    private LegacyLocalSettingsSerializer $legacyLocalSettingsSerializer;
    private LegacyOffsiteSettingsSerializer $legacyOffsiteSettingsSerializer;
    private VerificationScheduleSerializer $verificationScheduleSerializer;
    private DirectToCloudAgentSettingsSerializer $directToCloudAgentSettingsSerializer;
    private OriginDeviceSerializer $originDeviceSerializer;
    private BackupConstraintsSerializer $backupConstraintsSerializer;
    private FeatureService $featureService;

    public function __construct(
        DeviceLoggerInterface $logger,
        AgentService $agentService,
        AgentConfigFactory $agentConfigFactory,
        DateTimeService $dateTimeService,
        Filesystem $filesystem,
        DeviceConfig $deviceConfig,
        LegacyLocalSettingsSerializer $legacyLocalSettingsSerializer,
        LegacyOffsiteSettingsSerializer $legacyOffsiteSettingsSerializer,
        VerificationScheduleSerializer $verificationScheduleSerializer,
        DirectToCloudAgentSettingsSerializer $directToCloudAgentSettingsSerializer,
        OriginDeviceSerializer $originDeviceSerializer,
        BackupConstraintsSerializer $backupConstraintsSerializer,
        FeatureService $featureService
    ) {
        parent::__construct($logger);
        $this->agentService = $agentService;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->dateTimeService = $dateTimeService;
        $this->filesystem = $filesystem;
        $this->deviceConfig = $deviceConfig;
        $this->legacyLocalSettingsSerializer = $legacyLocalSettingsSerializer;
        $this->legacyOffsiteSettingsSerializer = $legacyOffsiteSettingsSerializer;
        $this->verificationScheduleSerializer = $verificationScheduleSerializer;
        $this->directToCloudAgentSettingsSerializer = $directToCloudAgentSettingsSerializer;
        $this->originDeviceSerializer = $originDeviceSerializer;
        $this->backupConstraintsSerializer = $backupConstraintsSerializer;
        $this->featureService = $featureService;
    }

    /**
     * {@inheritdoc}
     */
    public function commit()
    {
        $this->logger->info("DCS0005 Persisting configuration and metadata ...");

        $this->cleanStaleKeys();
        $this->persistConfiguration();
        $this->persistMetadata();

        $agent = $this->agentService->get($this->context->getAssetKey());
        $agent->initialConfiguration(); // Primarily called to exclude the DFS VSS writer.
        $this->agentService->save($agent);

        $this->context->setAgent($this->agentService->get($this->context->getAssetKey()));
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup()
    {
        // Nothing.
    }

    /**
     * {@inheritdoc}
     */
    public function rollback()
    {
        $pattern = sprintf(Agent::KEY_FILE_FORMAT, $this->context->getAssetKey(), '*');
        $keys = $this->filesystem->glob($pattern);

        foreach ($keys as $key) {
            $this->filesystem->unlink($key);
        }
    }

    /**
     * Clean any stale keys from existing agents.
     */
    private function cleanStaleKeys()
    {
        $agentConfig = $this->agentConfigFactory->create($this->context->getAssetKey());
        $agentConfig->clear(AssetRemovalService::REMOVING_KEY);
    }

    /**
     * Persist any configuration.
     */
    private function persistConfiguration()
    {
        $local = new LocalSettings(
            $this->context->getAssetKey(),
            LocalSettings::DEFAULT_PAUSE,
            LocalSettings::DEFAULT_INTERVAL,
            LocalSettings::DEFAULT_TIMEOUT,
            true, // FIXME: Even if this is configured false, it will get set to true. So if we want to turn this off, fix the bug.
            LocalSettings::DEFAULT_RANSOMWARE_SUSPENSION_END_TIME,
            WeeklySchedule::createEmpty(),
            $this->context->getRetention(),
            null,
            $this->context->isArchived(),
            LocalSettings::DEFAULT_INTEGRITY_CHECK_ENABLED
        );

        $offsite = new OffsiteSettings(
            OffsiteSettings::DEFAULT_PRIORITY,
            OffsiteSettings::DEFAULT_ON_DEMAND_RETENTION_LIMIT,
            OffsiteSettings::DEFAULT_NIGHTLY_RETENTION_LIMIT,
            OffsiteSettings::DEFAULT_REPLICATION,
            $this->context->getOffsiteRetention(),
            null,
            null,
            null,
            null
        );

        $interval = $this->deviceConfig->isAzureDevice()
                    ? LegacyOffsiteSettingsSerializer::REPLICATION_ALWAYS
                    : LegacyOffsiteSettingsSerializer::REPLICATION_NEVER;

        $offsiteControl = [
            'offsiteControl' => json_encode([
                'interval' => $interval,
                'priority' => LegacyOffsiteSettingsSerializer::LEGACY_PRIORITY_NORMAL
            ])
        ];

        $verification = $this->context->getVerificationSchedule();

        /* 2/1/2022 all new agents will get an agent-specific backupconstraint flag that will
           determine whether or not all volumes are added to this agent on checkin */
        $constraints = new BackupConstraints(
            BackupConstraints::DEFAULT_MAX_TOTAL_VOLUME_SIZE_IN_BYTES,
            $this->featureService->isSupported(FeatureService::FEATURE_DIRECT_TO_CLOUD_AGENTS_MULTI_VOLUME)
        );

        $backupConstraints = $this->deviceConfig->isAzureDevice() ? [] : [
            BackupConstraintsSerializer::KEY => $this->backupConstraintsSerializer->serialize(
                $constraints
            )
        ];

        $encryption = [];
        $records = [];
        $assetMetadata = $this->context->getAssetMetadata();
        $encryptionKeyStashRecord = $assetMetadata->getEncryptionKeyStashRecord();
        if ($encryptionKeyStashRecord !== null) {
            $encryption = [
                'encryption' => 1
            ];
            $records[] = $encryptionKeyStashRecord;
        }

        $this->save(array_merge(
            $this->legacyLocalSettingsSerializer->serialize($local),
            $this->legacyOffsiteSettingsSerializer->serialize($offsite),
            $this->verificationScheduleSerializer->serialize($verification),
            $offsiteControl,
            $backupConstraints,
            $encryption
        ), $records);
    }

    /**
     * Persist any information necessary for the system to recognize this as an asset.
     */
    private function persistMetadata()
    {
        $assetMetadata = $this->context->getAssetMetadata();
        $assetType = AssetType::determineAssetType(
            $assetMetadata->getDatasetPurpose(),
            $assetMetadata->getAgentPlatform(),
            $assetMetadata->getOperatingSystem()
        );

        $agentInfo = [
            'type' => $assetType,
            'uuid' => $assetMetadata->getAssetUuid(),
            'fqdn' => $assetMetadata->getFqdn(),
            'hostname' => $assetMetadata->getHostname(),
            'name' => $this->context->getAssetKey(),
            'os' => $assetMetadata->getOperatingSystem(),
            'os_name' => $assetMetadata->getOperatingSystem()
        ];

        $keys = [
            'agentInfo' => serialize($agentInfo),
            'dateAdded' => $this->dateTimeService->getTime()
        ];

        if ($assetMetadata->getAgentPlatform() === AgentPlatform::DIRECT_TO_CLOUD()) {
            $directToCloudSettings = new DirectToCloudAgentSettings();
        } else {
            $directToCloudSettings = null;
        }

        if ($assetMetadata->getAgentPlatform() === AgentPlatform::SHADOWSNAP()) {
            $keys['shadowSnap'] = 1;
        }

        $originDevice = new OriginDevice(
            $this->deviceConfig->getDeviceId(),
            $this->context->getResellerId()
        );

        $this->save(array_merge(
            $keys,
            $this->directToCloudAgentSettingsSerializer->serialize($directToCloudSettings),
            $this->originDeviceSerializer->serialize($originDevice)
        ));
    }

    /**
     * @param array $serializedKeyFiles
     * @param ConfigRecordInterface[] $records
     */
    private function save(array $serializedKeyFiles, array $records = [])
    {
        $agentConfig = $this->agentConfigFactory->create($this->context->getAssetKey());
        foreach ($serializedKeyFiles as $key => $contents) {
            $agentConfig->set($key, $contents);
        }

        foreach ($records as $record) {
            $agentConfig->saveRecord($record);
        }
    }
}
