<?php

namespace Datto\Feature;

use Datto\AppKernel;
use Datto\Asset\Asset;
use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Log\DeviceLoggerInterface;
use Datto\Log\LoggerFactory;
use Exception;
use Psr\Container\ContainerInterface;
use ReflectionClass;

/**
 * This service checks the availability of concrete feature classes.
 * Can and should be extended to support switching features on and off.
 *
 * See public functions for usage.
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class FeatureService
{
    const LEGACY_FEATURE_BMR = 'bmr';
    const DIR_SUPPORTED = '/var/lib/datto/device/features';
    const DIR_OVERRIDE_ENABLED = '/var/lib/datto/device/features/override/enabled';
    const DIR_OVERRIDE_DISABLED = '/var/lib/datto/device/features/override/disabled';

    // Feature constants must start with FEATURE_ since we're using
    // reflection to discover features.

    const FEATURE_AGENTLESS = 'Agentless';
    const FEATURE_AGENTS = 'Agents';
    const FEATURE_AGENT_BACKUPS = 'AgentBackups';
    const FEATURE_AGENT_BACKUP_CONSTRAINTS = 'AgentBackupConstraints';
    const FEATURE_AGENT_CREATE = 'AgentCreate';
    const FEATURE_AGENT_REPAIR = 'AgentRepair';
    const FEATURE_AGENT_TEMPLATES = 'AgentTemplates';
    const FEATURE_AGENT_REPORTS = 'AgentReports';
    const FEATURE_ALERTING = 'Alerting';
    const FEATURE_ALERTING_ADVANCED = 'AlertingAdvanced';
    const FEATURE_ALERTING_CUSTOM_EMAILS = 'AlertingCustomEmails';
    const FEATURE_ALERTING_MOUNTED_RESTORE = 'AlertingMountedRestore';
    const FEATURE_ALERT_LOGIC = 'AlertLogic';
    const FEATURE_ALERTVIAJSONRPC = 'AlertViaJsonRpc';
    const FEATURE_APACHE_CLOUD_CONFIG = 'ApacheCloudConfig';
    const FEATURE_APPLICATION_VERIFICATIONS = 'ApplicationVerifications';
    const FEATURE_ASSETS = 'Assets';
    const FEATURE_ASSET_ARCHIVAL = 'AssetArchival';
    const FEATURE_ASSET_ARCHIVAL_RETENTION_LOCAL = 'AssetArchivalRetentionLocal';
    const FEATURE_ASSET_ARCHIVAL_RETENTION_LOCAL_DELETE_CRITICAL = 'AssetArchivalRetentionLocalDeleteCritical';
    const FEATURE_ASSET_BACKUPS = 'AssetBackups';
    const FEATURE_AUDITS = 'Audits';
    const FEATURE_AUTH_ANONYMOUS = 'AuthAnonymous';
    const FEATURE_AUTH_BASIC = 'AuthBasic';
    const FEATURE_AUTH_COOKIE = 'AuthCookie';
    const FEATURE_AUTH_LOCALHOST_API_KEY = 'AuthLocalhostApiKey';
    const FEATURE_AUTH_SECRET_KEY = 'AuthSecretKey';
    const FEATURE_AUTO_ACTIVATE = 'AutoActivate';
    const FEATURE_BACKUP_OFFSET = 'BackupOffset';
    const FEATURE_BACKUP_REPORTS = 'BackupReports';
    const FEATURE_BACKUP_SCHEDULING = 'BackupScheduling';
    const FEATURE_BILLING_CHECK = 'BillingCheck';
    const FEATURE_BURNIN = 'Burnin';
    const FEATURE_COUNT_AGENT_CRASH_DUMPS = 'CountAgentCrashDumps';
    const FEATURE_CERT_EXPIRATION_WARNING = 'CertExpirationWarning';
    const FEATURE_CHECKIN = 'Checkin';
    const FEATURE_CLOUD_ASSISTED_VERIFICATION_OFFLOADS = 'CloudAssistedVerificationOffloads';
    const FEATURE_CLOUD_MANAGED_MAINTENANCE = 'CloudManagedMaintenance';
    const FEATURE_CLOUD_MANAGED_CONFIGS = 'CloudManagedConfigs';
    const FEATURE_CLOUD_ALLOWS_BACKUP_CHECK = 'CloudAllowsBackupCheck';
    const FEATURE_CLOUD_FEATURES = 'CloudFeatures';
    const FEATURE_CONCURRENT_BACKUPS = 'ConcurrentBackups';
    const FEATURE_CONFIGURABLE_LOCAL_RETENTION = "ConfigurableLocalRetention";
    const FEATURE_CONFIG_BACKUPS = 'ConfigBackups';
    const FEATURE_CONFIG_BACKUPS_PARTIAL = 'ConfigBackupsPartial';
    const FEATURE_CONFIG_BACKUP_OFFSITE = 'ConfigBackupOffsite';
    const FEATURE_CONTINUITY_AUDIT = "ContinuityAudit";
    const FEATURE_CUSTOM_BACKGROUND = "CustomBackground";
    const FEATURE_DEVICE_INFO = 'DeviceInfo';
    const FEATURE_DEVICE_MIGRATION = 'DeviceMigration';
    const FEATURE_DIRECT_TO_CLOUD_AGENTS = 'DirectToCloudAgents';
    const FEATURE_DIRECT_TO_CLOUD_AGENTS_MULTI_VOLUME = 'DirectToCloudAgentsMultiVolume';
    const FEATURE_DIRECT_TO_CLOUD_AGENTS_RETENTION_DURING_BACKUP = "DirectToCloudAgentsRetentionDuringBackup";
    const FEATURE_ENCRYPTED_BACKUPS = 'EncryptedBackups';
    const FEATURE_FEATURES = 'Features';
    const FEATURE_FILESYSTEM_INTEGRITY_CHECK = 'FilesystemIntegrityCheck';
    const FEATURE_HYPER_SHUTTLE = 'HyperShuttle';
    const FEATURE_HYPERVISOR_CONNECTIONS = 'HypervisorConnections';
    const FEATURE_INTRUSION_DETECTION = 'IntrusionDetection';
    const FEATURE_INBOUND_REPLICATION = 'InboundReplication';
    const FEATURE_IPMI = 'Ipmi';
    const FEATURE_IPMI_ROTATE_ADMIN_PASSWORD = 'IpmiRotateAdminPassword';
    const FEATURE_IPMI_UPDATE = 'IpmiUpdate';
    const FEATURE_ISCSI_HEALTH_CHECK = 'IscsiHealthCheck';
    const FEATURE_LOCAL_BANDWIDTH_SCHEDULING = 'LocalBandwidthScheduling';
    const FEATURE_LOCAL_RETENTION = 'LocalRetention';
    const FEATURE_LOW_RESOURCE_VERIFICATIONS = 'LowResourceVerifications';
    const FEATURE_MAINTENANCE_MODE = 'MaintenanceMode';
    const FEATURE_MERCURY_SHARES_APACHE_CERT = 'MercurySharesApacheCert';
    const FEATURE_METRICS = 'Metrics';
    const FEATURE_METRICS_REMAINING_RETENTION_COUNT = 'MetricsRemainingRetentionCount';
    const FEATURE_METRICS_ZFS = 'MetricsZfs';
    const FEATURE_MIGRATIONS = 'Migrations';
    const FEATURE_NETWORK = 'Network';
    const FEATURE_NETWORK_MONITORING = 'NetworkMonitoring';
    const FEATURE_NEW_PAIR = 'NewPair';
    const FEATURE_NEW_REPAIR = 'NewRepair';
    const FEATURE_OFFSITE = "Offsite";
    const FEATURE_OFFSITE_RETENTION = 'OffsiteRetention';
    const FEATURE_OFFSITE_SECONDARY = "OffsiteSecondary";
    const FEATURE_PEER_REPLICATION = "PeerReplication";
    const FEATURE_POWER_MANAGEMENT = 'PowerManagement';
    const FEATURE_PROTECTED_SYSTEM_CONFIGURABLE = 'ProtectedSystemConfigurable';
    const FEATURE_PUBLIC_CLOUD_METADATA_RETRIEVAL = "PublicCloudMetaDataRetrieval";
    const FEATURE_PUBLIC_CLOUD_POOL_EXPANSION = 'PublicCloudPoolExpansion';
    const FEATURE_PREVENT_NETWORK_HOPPING = 'PreventNetworkHopping';
    const FEATURE_RANSOMWARE_DETECTION = "RansomwareDetection";
    const FEATURE_REGISTRATION = 'Registration';
    const FEATURE_REMOTE_CEF_LOGGING = 'RemoteCefLogging';
    const FEATURE_REMOTE_LOGGING = 'RemoteLogging';
    const FEATURE_REMOTE_MANAGEMENT = 'RemoteManagement';
    const FEATURE_REMOTE_WEB = 'RemoteWeb';
    const FEATURE_REPLICATION_PROMOTE = 'ReplicationPromote';
    const FEATURE_REPLICATION_RECOVERY_POINT_SYNC = 'ReplicationRecoveryPointSync';
    const FEATURE_REPLICATION_TARGET = 'ReplicationTarget';
    const FEATURE_REPLICATION_VOLUMES_SYNC = 'ReplicationVolumesSync';
    const FEATURE_RESCUE_AGENTS = 'RescueAgents';
    const FEATURE_RESTORE = 'Restore';
    const FEATURE_RESTORE_BACKUP_INSIGHTS = "RestoreBackupInsights";
    const FEATURE_RESTORE_BMR = 'RestoreBmr';
    const FEATURE_RESTORE_DIFFERENTIAL_ROLLBACK = 'RestoreDifferentialRollback';
    const FEATURE_RESTORE_FILE = 'RestoreFile';
    const FEATURE_RESTORE_FILE_ACLS = 'RestoreFileAcls';
    const FEATURE_RESTORE_FILE_PUSH = 'RestoreFilePush';
    const FEATURE_RESTORE_FILE_SFTP = 'RestoreFileSftp';
    const FEATURE_RESTORE_FILE_TOKEN = 'RestoreFileToken';
    const FEATURE_RESTORE_GRANULAR = 'RestoreGranular';
    const FEATURE_RESTORE_HYPERVISOR_UPLOAD = 'RestoreHypervisorUpload';
    const FEATURE_RESTORE_IMAGE_EXPORT = 'RestoreImageExport';
    const FEATURE_RESTORE_ISCSI = 'RestoreIscsi';
    const FEATURE_RESTORE_ISCSI_ROLLBACK = 'RestoreIscsiRollback';
    const FEATURE_RESTORE_VIRTUALIZATION = 'RestoreVirtualization';
    const FEATURE_RESTORE_VIRTUALIZATION_HYBRID = 'RestoreVirtualizationHybrid';
    const FEATURE_RESTORE_VIRTUALIZATION_HYPERVISOR = 'RestoreVirtualizationHypervisor';
    const FEATURE_RESTORE_VIRTUALIZATION_LOCAL = 'RestoreVirtualizationLocal';
    const FEATURE_RESTORE_VIRTUALIZATION_PUBLIC_CLOUD = 'RestoreVirtualizationPublicCloud';
    const FEATURE_RESTORE_VOLUME = 'RestoreVolume';
    const FEATURE_RESTRICTIVE_FIREWALL = 'RestrictiveFirewall';
    const FEATURE_ROUNDTRIP = 'Roundtrip';
    const FEATURE_ROUNDTRIP_NAS = 'RoundtripNas';
    const FEATURE_ROUNDTRIP_NAS_SSH = 'RoundTripNasSsh';
    const FEATURE_SCRUB = 'Scrub';
    const FEATURE_SERVICE_AFP = 'ServiceAfp';
    const FEATURE_SERVICE_ISCSI = 'ServiceIscsi';
    const FEATURE_SERVICE_NFS = 'ServiceNfs';
    const FEATURE_SERVICE_SAMBA = 'ServiceSamba';
    const FEATURE_SERVICE_VERIFICATIONS = 'ServiceVerifications';
    const FEATURE_SET_POOL_QUOTA = 'SetPoolQuota';
    const FEATURE_SHADOWSNAP = 'Shadowsnap';
    const FEATURE_SHARES = 'Shares';
    const FEATURE_SHARES_EXTERNAL = 'SharesExternal';
    const FEATURE_SHARES_ISCSI = 'SharesIscsi';
    const FEATURE_SHARES_NAS = 'SharesNas';
    const FEATURE_SHARE_AUTO_MOUNTING = 'ShareAutoMounting';
    const FEATURE_SHARE_BACKUPS = 'ShareBackups';
    const FEATURE_SHARE_GROWTH_REPORT = 'ShareGrowthReport';
    const FEATURE_SKIP_VERIFICATION = 'SkipVerification';
    const FEATURE_SSH_AUTO_GENERATE_KEYS = 'SshAutoGenerateKeys';
    const FEATURE_SSH_RESTRICTED_ACCESS = 'SshRestrictedAccess';
    const FEATURE_STORAGE_DIAGNOSE = 'StorageDiagnose';
    const FEATURE_STORAGE_ENCRYPTION = 'StorageEncryption';
    const FEATURE_STORAGE_UPGRADE = 'StorageUpgrade';
    const FEATURE_SURICATA = 'Suricata';
    const FEATURE_TCP_CONNECTION_LIMITING = 'TCPConnectionLimiting';
    const FEATURE_TIME_ZONE = 'TimeZone';
    const FEATURE_UPGRADES = 'Upgrades';
    const FEATURE_USER_INTERFACE = 'UserInterface';
    const FEATURE_USER_MANAGEMENT = 'UserManagement';
    const FEATURE_VERIFICATIONS = 'Verifications';
    const FEATURE_WATCHDOG = 'Watchdog';

    private ?Feature $feature;
    private ContainerInterface $container;
    private Filesystem $filesystem;
    private DeviceLoggerInterface $logger;

    public function __construct(
        ?Feature $feature = null,
        ?ContainerInterface $container = null,
        ?Filesystem $filesystem = null,
        ?DeviceLoggerInterface $logger = null
    ) {
        $this->feature = $feature;
        $this->container = $container ?? AppKernel::getBootedInstance()->getContainer(); // Required for legacy code
        $this->filesystem = $filesystem ?? new Filesystem(new ProcessFactory());
        $this->logger = $logger ?? LoggerFactory::getDeviceLogger();
    }

    /**
     * Checks whether or not specified feature is supported for device|asset.
     *
     * Usage for device only features:
     *    $featureService->isSupported(FeatureService::FEATURE_ZFS_ENCRYPTION);
     *
     * Usage for asset specific features:
     *    $featureService->isSupported(FeatureService::FEATURE_ZFS_ENCRYPTION, concreteAssetObject|null, concreteAsset::Class);
     *
     * @param string $feature The feature name or class name (e.g. FEATURE_USER_INTERFACE or UserInterface)
     * @param string|null $version Optional, requester passes in own version to check for compatibility
     * @param Asset|null $assetObject Concrete instance of an asset class
     * @param string|null $assetClass The asset class type (use for assets that do not yet exist)
     * @return bool
     */
    public function isSupported(
        string $feature,
        ?string $version = null,
        ?Asset $assetObject = null,
        ?string $assetClass = null
    ): bool {
        // For dependency injection (this is for testing only)
        if ($this->feature !== null) {
            return $this->feature->isSupported($version);
        }

        // This is a hack to support old BMR sticks
        if (strtolower($feature) === FeatureService::LEGACY_FEATURE_BMR) {
            $feature = FeatureService::FEATURE_RESTORE_BMR;
        }

        // Translate class name to feature name (e.g UserInterface -> FEATURE_USER_INTERFACE)
        list($featureName, $className) = $this->lookup($feature);

        // Feature override
        $overrideEnabledFile = FeatureService::DIR_OVERRIDE_ENABLED . '/' . $featureName;
        $overrideDisabledFile = FeatureService::DIR_OVERRIDE_DISABLED . '/' . $featureName;

        if ($this->filesystem->exists($overrideEnabledFile)) {
            return true;
        } elseif ($this->filesystem->exists($overrideDisabledFile)) {
            return false;
        }

        return $this->getInstance($className, $assetObject, $assetClass)->isSupported($version);
    }

    /**
     * Assert that a feature is supported. If the desired feature is not supported, a FeatureNotSupportedException
     * will be thrown.
     *
     * @see FeatureService::isSupported() for more details.
     * @param string $feature The feature name or class name (e.g. FEATURE_USER_INTERFACE or UserInterface)
     * @param string|null $version
     * @param mixed|null $assetObject
     * @param string|null $assetClass
     */
    public function assertSupported(
        string $feature,
        ?string $version = null,
        ?Asset $assetObject = null,
        ?string $assetClass = null
    ): void {
        $isSupported = $this->isSupported($feature, $version, $assetObject, $assetClass);

        if (!$isSupported) {
            throw new FeatureNotSupportedException("Feature is not supported: " . $feature);
        }
    }

    /**
     * In some systemd services, we use ConditionPathExists= to determine whether
     * or not to start a service. This method updates the flat files needed for this
     * purpose.
     *
     * @return int Number of updated features
     */
    public function updateFlags(): int
    {
        $updated = 0;
        $this->filesystem->mkdirIfNotExists(FeatureService::DIR_SUPPORTED, true, 0755);

        $knownFeatures = $this->listAll();
        $this->removeObsoleteFeatureFiles($knownFeatures);

        foreach ($knownFeatures as $featureName) {
            try {
                $featureFile = FeatureService::DIR_SUPPORTED . '/' . $featureName;

                $supported = $this->isSupported($featureName);
                $exists = $this->filesystem->exists($featureFile);

                if ($supported && !$exists) {
                    $this->filesystem->touch($featureFile);
                    $updated++;
                } elseif (!$supported && $exists) {
                    $this->filesystem->unlink($featureFile);
                    $updated++;
                }
            } catch (Exception $e) {
                $this->logger->error('FEA0001 Cannot check feature', [
                    'featureName' => $featureName,
                    'exception' => $e
                ]);
            }
        }

        return $updated;
    }

    /**
     * Lists all available features
     *
     * @return array List of feature constants
     */
    public function listAll(): array
    {
        return array_keys($this->getFeatureConstants());
    }

    /**
     * @param bool $updateIfNeeded
     * @return array
     */
    public function getCachedSupported(bool $updateIfNeeded = true): array
    {
        $supported = [];

        // Update flags if needed
        $featureFlagFiles = $this->getFeatureFlagFiles();
        if ($updateIfNeeded && empty($featureFlagFiles)) {
            $this->updateFlags();
            $featureFlagFiles = $this->getFeatureFlagFiles();
        }

        // Create list of supported features
        foreach ($featureFlagFiles as $featureFlagFile) {
            try {
                $potentialFeature = basename($featureFlagFile);

                list($constant, $className) = $this->lookup($potentialFeature);
                $supported[] = $className;
            } catch (FeatureNotSupportedException $e) {
                // Hmm..
            }
        }

        return $supported;
    }

    /**
     * Creates the context for the feature to be checked against.
     *
     * @param Asset $assetObject The asset you wish to check feature support for
     * @param string $assetClass The class of the asset you wish to check support for
     * @return Context $context
     */
    private function createContext(?Asset $assetObject = null, ?string $assetClass = null): Context
    {
        if ($assetObject && $assetClass) {
            $matchingClassTypes = $assetObject instanceof $assetClass;
            if (!$matchingClassTypes) {
                throw new Exception('Asset class and class name must be of the same type');
            }
        }

        /** @var Context $context */
        $context = $this->container->get(Context::class);
        $context->setAsset($assetObject);
        $context->setAssetClass($assetClass);

        return $context;
    }

    /**
     * Creates the context, then creates feature with the context.
     *
     * @param String $className Feature class name
     * @param Asset $assetObject The asset you wish to check feature support for
     * @param String $assetClass The class of the asset you wish to check support for
     * @return Feature
     */
    private function getInstance(?string $className, ?Asset $assetObject, ?string $assetClass): Feature
    {
        $context = $this->createContext($assetObject, $assetClass);
        return $this->createFeature($className, $context);
    }

    /**
     * Creates a feature based off of feature name.
     *
     * @param string $className Feature class name
     * @param Context $context The context wished to compare against
     * @return Feature
     */
    private function createFeature(?string $className, Context $context): Feature
    {
        $featureClass = sprintf('Datto\\Feature\\Features\\%s', $className);

        /** @var Feature $feature */
        $feature = $this->container->get($featureClass);
        $feature->setName($className);
        $feature->setContext($context);

        return $feature;
    }

    /**
     * Looks up feature by the constant name or class name and returns both.
     *
     * @param string $feature
     * @return array Feature name (constant) and class name
     */
    private function lookup(string $feature): array
    {
        $constantMap = $this->getFeatureConstants();

        if (isset($constantMap[$feature])) {
            return [$feature, $constantMap[$feature]];
        } else {
            foreach ($constantMap as $constant => $className) {
                if ($feature === $className) {
                    return [$constant, $className];
                }
            }
        }

        throw new FeatureNotSupportedException(sprintf('Unrecognized feature name: %s', $feature));
    }

    /**
     * Returns a list of constants starting with the FEATURE_ prefix.
     */
    private function getFeatureConstants(): array
    {
        $reflectionClass = new ReflectionClass($this);
        $constants = $reflectionClass->getConstants();

        return array_filter($constants, function ($constant) {
            return preg_match('/^FEATURE_/', $constant);
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * Get all feature flag files.
     *
     * @return string[]
     */
    private function getFeatureFlagFiles(): array
    {
        $featureFilesInDir = $this->filesystem->glob(FeatureService::DIR_SUPPORTED . '/*') ?: [];
        return array_diff($featureFilesInDir, [
            $this->filesystem->dirname(FeatureService::DIR_OVERRIDE_DISABLED),
            $this->filesystem->dirname(FeatureService::DIR_OVERRIDE_ENABLED)
        ]);
    }

    /**
     * Find feature flags that no longer have an existing feature class, and remove them
     *
     * @param array $knownFeatures The list of features that we know have backing feature class files
     */
    private function removeObsoleteFeatureFiles(array $knownFeatures): void
    {
        foreach ($this->getFeatureFlagFiles() as $featureFile) {
            $featureName = $this->filesystem->basename($featureFile);
            $notAvailable = !in_array($featureName, $knownFeatures);
            if ($notAvailable) {
                $this->logger->info('FEA0002 Feature is not available, removing feature flag files', [
                    'featureName' => $featureName
                ]);
                $this->filesystem->unlink($featureFile);
                $this->filesystem->unlinkIfExists(FeatureService::DIR_OVERRIDE_ENABLED . "/$featureName");
                $this->filesystem->unlinkIfExists(FeatureService::DIR_OVERRIDE_DISABLED . "/$featureName");
            }
        }
    }
}
