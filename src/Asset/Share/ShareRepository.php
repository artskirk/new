<?php

namespace Datto\Asset\Share;

use Datto\Asset\AssetRepository;
use Datto\Asset\AssetType;
use Datto\Asset\Share\Serializer\LegacyShareSerializer;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\AgentConfigFactory;
use Datto\Log\DeviceLoggerInterface;
use Datto\Common\Utility\Filesystem;

/**
 * Repository to read/write share configuration from the existing
 * keyfile-based config backend (.agentInfo, .interval, ...)
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class ShareRepository extends AssetRepository
{
    /** @const string */
    const FILE_EXTENSION = 'agentInfo';

    /** @var Filesystem */
    private $filesystem;

    public function __construct(
        Filesystem $filesystem = null,
        AgentConfigFactory $agentConfigFactory = null,
        LegacyShareSerializer $serializer = null,
        DeviceLoggerInterface $logger = null
    ) {
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $agentConfigFactory = $agentConfigFactory ?: new AgentConfigFactory();
        $serializer = $serializer ?: new LegacyShareSerializer();

        parent::__construct(
            $agentConfigFactory,
            $serializer,
            Share::BASE_CONFIG_PATH,
            self::FILE_EXTENSION,
            [
                // LegacyNasShareSerializer and LegacyIscsiShareSerializer
                'agentInfo',// serialized array
                'emails',// serialized array of comma delimetered strings
                'shareAuth',// flat file with username (string)
                'growthReport',// Flat file delimited by ":"
                'lastError', // Serialized array
                'originDevice', //json encoded
                // LegacyLocalSettingsSerializer
                'backupPause',// flag file (exists - true, not exists - false)
                'backupPauseUntil',
                'integrityCheckEnabled',
                'interval',// integer
                'recoveryPoints',// flat file (new line separated)
                'recoveryPointsMeta',// serialized array
                'retention',// colon separated file
                'schedule',// INI file
                'snapTimeout', // snapshot timeout
                'ransomwareCheckEnabled',// bool
                'ransomwareSuspensionEndTime',// int
                // LegacyOffsiteSettingSerializer
                'offsiteControl',// JSON encoding
                'offsiteMetrics',
                'offSitePoints',// flat file (new line separated)
                'offSitePointsCache',// flat file (new line separated)
                'offsiteRetention',// colon separated file
                'offsiteRetentionLimits',// colon separated file
                'offsiteSchedule',// serialized array
                'offsiteTarget', // json encoded
                // (indirectly via SambaShare via SambaManager)
                'samba',// INI file
                'sambaMount', // serialized array
                'sambaMountKey', // flat file with sambaMount encryption key
                // Not yet controlled by a serializer
                'dateAdded',
                'emailSupression',
                'log', // todo some of these files aren't used by the serializers, but because they're here, we read them from disk each time. Remove them from here and handle asset deletion by removing all keys in the destroy service
                'scr.log',
                'shareMounted',
                'snapLock',
                'snp.log',
                'storageUsage',
                'transfers',
                'usage.daily',
                'usage.hourly',
                'format',
                'backupAcls',
                'alertConfig',
                'lastSentAssetInfo',
                'recoveryPointHistory',
                'speedSyncPaused'
            ],
            [],
            null,
            $logger
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function preSave($keyName, $fileArray)
    {
        parent::preSave($keyName, $fileArray);

        // file containing encryption key should be restricted to root
        if (isset($fileArray['sambaMountKey'])) {
            $agentConfig = $this->agentConfigFactory->create($keyName);
            $configFile = $agentConfig->getConfigFilePath('sambaMountKey');
            $this->filesystem->touch($configFile);
            $this->filesystem->chmod($configFile, 0600);
        }
    }

    /**
     * @inheritDoc
     */
    public function exists($keyName, $type = AssetType::SHARE): bool
    {
        if (!AssetType::isShare($type)) {
            return false;
        }
        return parent::exists($keyName, $type);
    }

    /**
     * @inheritDoc
     */
    public function getAll(bool $getReplicated = true, bool $getArchived = true, ?string $type = AssetType::SHARE)
    {
        if (!AssetType::isShare($type)) {
            return [];
        }
        return parent::getAll($getReplicated, $getArchived, $type);
    }
}
