<?php

namespace Datto\Asset\Agent;

use Datto\Asset\Agent\Serializer\LegacyAgentSerializer;
use Datto\Asset\AssetRepository;
use Datto\Asset\AssetRepositoryFileCache;
use Datto\Asset\AssetType;
use Datto\Config\AgentConfigFactory;
use Datto\Log\LoggerFactory;
use Datto\Log\DeviceLoggerInterface;

/**
 * Repository to read/write agent configuration from the existing
 * keyfile-based config backend (.agentInfo, .interval, ...)
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class AgentRepository extends AssetRepository
{
    /** Main extension used for listing existing agents */
    const FILE_EXTENSION = 'agentInfo';

    /** The default directory for agent keys */
    const BASE_CONFIG_PATH = '/datto/config/keys';

    public function __construct(
        AgentConfigFactory $agentConfigFactory = null,
        LegacyAgentSerializer $serializer = null,
        AssetRepositoryFileCache $fileCache = null,
        DeviceLoggerInterface $logger = null
    ) {
        $agentConfigFactory = $agentConfigFactory ?: new AgentConfigFactory();
        $serializer = $serializer ?: new LegacyAgentSerializer();
        $fileCache = $fileCache ?: new AssetRepositoryFileCache();
        $logger = $logger ?: LoggerFactory::getDeviceLogger();

        parent::__construct(
            $agentConfigFactory,
            $serializer,
            self::BASE_CONFIG_PATH,
            self::FILE_EXTENSION,
            [
                'agentInfo',
                'alertConfig',
                'archived',
                'backupConstraints',
                'backupEngine',
                'backupPause',
                'backupPauseUntil',
                'backupPauseWhileMetered',
                'backupMaximumBandwidthInBits',
                'backupMaximumThrottledBandwidthInBits',
                'backupVMX',
                'dateAdded',
                'doDiffMerge',
                'directToCloudAgentSettings',
                'emails',
                'encryption',
                'encryptionKeyStash',
                'encryptionTempAccess',
                'esxInfo',
                'expediteMigration',
                'include',
                'integrityCheckEnabled',
                'interval',
                'lastError',
                'lastCheckin',
                'lastSentAssetInfo',
                'legacyVM',
                'offsiteControl',
                'offsiteMetrics',
                'offSitePoints',
                'offSitePointsCache',
                'offsiteRetention',
                'offsiteRetentionLimits',
                'offsiteSchedule',
                'offsiteTarget',
                'originDevice',
                'pps',
                'diskDrives',
                'ransomwareCheckEnabled',
                'ransomwareSuspensionEndTime',
                'recoveryPoints',
                'recoveryPointHistory',
                'recoveryPointsMeta',
                'rescueAgentSettings',
                'retention',
                'schedule',
                'screenshotNotification',
                'screenshotVerification',
                'shareAuth',
                'scriptSettings',
                'snapTimeout',
                'speedSyncPaused',
                'vssExclude',
                'vssWriters',
                'migrationInProgress'
            ],
            [
                'shadowSnap',
                'fullDiskBackup',
                'forcePartitionRewrite'
            ],
            $fileCache,
            $logger
        );
    }

    /**
     * @inheritDoc
     */
    public function exists($keyName, $type = AssetType::AGENT): bool
    {
        if (AssetType::isShare($type)) {
            return false;
        }
        return parent::exists($keyName, $type);
    }

    /**
     * @inheritDoc
     */
    public function getAll(bool $getReplicated = true, bool $getArchived = true, ?string $type = AssetType::AGENT)
    {
        if (AssetType::isShare($type)) {
            return [];
        }
        return parent::getAll($getReplicated, $getArchived, $type);
    }
}
