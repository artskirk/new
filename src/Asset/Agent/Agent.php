<?php

namespace Datto\Asset\Agent;

use Datto\Asset\Agent\Backup\AgentSnapshotRepository;
use Datto\Asset\Asset;
use Datto\Asset\AssetType;
use Datto\Asset\BackupConstraints;
use Datto\Asset\EmailAddressSettings;
use Datto\Asset\LastErrorAlert;
use Datto\Asset\LocalSettings;
use Datto\Asset\OffsiteSettings;
use Datto\Asset\OriginDevice;
use Datto\Asset\ScriptSettings;
use Datto\Asset\VerificationSchedule;
use Datto\Dataset\ZFS_Dataset;
use Datto\Screenshot\ScreenshotSettings;
use Datto\Common\Utility\Filesystem;
use Exception;

/**
 * Class to represent an agent.
 *
 * Developer note:
 *   Be sure to make all properties injectable through the constructor, so that the
 *   state of the object can be recreated from a config file. Do NOT provide public
 *   setters for properties that could set the object into an inconsistent state,
 *   e.g. don't provide a setEnabled() method.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
abstract class Agent extends Asset
{
    /** Path to agent keys directory */
    const KEYBASE = "/datto/config/keys/";

    /** Format for full key file path */
    const KEY_FILE_FORMAT = self::KEYBASE . '%s.%s';

    /** ZFS dataset path where agents live */
    const ZFS_PATH_TEMPLATE = 'homePool/home/agents/%s';

    /** @var ZFS_Dataset */
    private $dataset;

    /** @var OperatingSystem */
    private $operatingSystem;

    /** @var Volumes */
    private $volumes;

    /** @var IncludedVolumesSettings */
    private $includedVolumesSettings;

    /** @var IncludedVolumesMetaSettings */
    private $includedVolumesMetaSettings;

    /** @var DriverSettings */
    private $driver;

    /** @var string */
    private $hostname;

    /** @var float */
    private $usedBySnapshots;

    /** @var float */
    private $usedLocally;

    /** @var int */
    private $cpuCount;

    /** @var int */
    private $memory;

    /** @var ScreenshotSettings */
    private $screenshot;

    /** @var EncryptionSettings */
    private $encryption;

    /** @var ScreenshotVerificationSettings */
    private $screenshotVerification;

    /** @var SecuritySettings */
    private $shareAuth;

    /** @var RescueAgentSettings|null */
    private $rescueAgentSettings;

    /** @var string */
    private $fullyQualifiedDomainName;

    /** @var DirectToCloudAgentSettings|null */
    private $directToCloudAgentSettings;

    /** @var AgentPlatform **/
    private $platform;

    /** @var bool */
    private $fullDiskBackup;

    private bool $forcePartitionRewrite;

    public function __construct(
        $name,
        $keyName,
        $type,
        AgentPlatform $platform,
        $dateAdded,
        ZFS_Dataset $dataset,
        LocalSettings $local,
        OffsiteSettings $offsite,
        EmailAddressSettings $emailAddresses,
        OperatingSystem $operatingSystem,
        Volumes $volumes,
        IncludedVolumesSettings $includedVolumesSettings,
        IncludedVolumesMetaSettings $includedVolumesMetaSettings,
        DriverSettings $driver,
        EncryptionSettings $encryption,
        $hostname,
        $usedBySnapshots,
        $usedLocally,
        $cpuCount,
        $memory,
        $shareAuth,
        bool $fullDiskBackup,
        bool $forcePartitionRewrite,
        ScreenshotSettings $screenshot = null,
        ScreenshotVerificationSettings $screenshotVerification = null,
        ScriptSettings $scriptSettings = null,
        VerificationSchedule $verificationSchedule = null,
        $rescueAgentSettings = null,
        $fullyQualifiedDomainName = '',
        $uuid = '',
        LastErrorAlert $lastError = null,
        OriginDevice $originDevice = null,
        DirectToCloudAgentSettings $directToCloudAgentSettings = null,
        string $offsiteTarget = null,
        BackupConstraints $backupConstraints = null
    ) {
        parent::__construct(
            $name,
            $keyName,
            $type,
            $dateAdded,
            $local,
            $offsite,
            $emailAddresses,
            $lastError,
            $scriptSettings,
            $verificationSchedule,
            $uuid,
            $originDevice,
            $offsiteTarget,
            $backupConstraints
        );

        // objects
        $this->dataset = $dataset;
        $this->operatingSystem = $operatingSystem;
        $this->volumes = $volumes;
        $this->includedVolumesSettings = $includedVolumesSettings;
        $this->includedVolumesMetaSettings = $includedVolumesMetaSettings;
        $this->driver = $driver;
        $isRescueAgent = (bool)$rescueAgentSettings;
        $this->screenshot = $screenshot ?: new ScreenshotSettings($this->operatingSystem, $isRescueAgent);
        $this->encryption = $encryption;
        $this->screenshotVerification = $screenshotVerification ?: new ScreenshotVerificationSettings();
        $this->shareAuth = $shareAuth;
        $this->rescueAgentSettings = $rescueAgentSettings;
        $this->directToCloudAgentSettings = $directToCloudAgentSettings;

        // enums
        $this->platform = $platform;

        // strings
        $this->hostname = $hostname;
        $this->fullyQualifiedDomainName = $fullyQualifiedDomainName;

        // floats
        $this->usedBySnapshots = $usedBySnapshots;
        $this->usedLocally = $usedLocally;

        // integers
        $this->cpuCount = $cpuCount;
        $this->memory = $memory;

        //booleans
        $this->fullDiskBackup = $fullDiskBackup;
        $this->forcePartitionRewrite = $forcePartitionRewrite;
    }

    /**
     * Create a new rescue agent and set of keyfiles based on an existing agent.
     *
     * This functionality is here in order to avoid a bunch of setters on the Agent class.
     *
     * @param string $agentName
     * @param string $agentUuid
     * @param string $snapshotEpoch
     * @param Filesystem $fileSystem
     * @param AgentService $agentService
     * @return Agent
     */
    public function createRescueAgent(
        $agentName,
        $agentUuid,
        $snapshotEpoch,
        Filesystem $fileSystem,
        AgentService $agentService
    ) {
        $rescueAgent = clone $this;
        $rescueAgent->name = $agentName;
        $rescueAgent->hostname = $agentName;
        $rescueAgent->clearLastError();

        $rescueAgent->uuid = $agentUuid;
        $rescueAgent->keyName = $agentUuid;

        $localSettings = new LocalSettings($rescueAgent->getKeyName());
        $offsiteSettings = new OffsiteSettings();
        $rescueAgentSettings = new RescueAgentSettings($this->getKeyName(), $snapshotEpoch);

        $localSettings->copyFrom($this->getLocal());

        if ($this->getOriginDevice()->isReplicated()) {
            $offsiteSettings = new OffsiteSettings();
            $offsiteSettings->setReplication(OffsiteSettings::REPLICATION_NEVER);
        } else {
            $offsiteSettings->copyFrom($this->getOffsite());
        }

        $rescueAgent->local = $localSettings;
        $rescueAgent->offsite = $offsiteSettings;
        $rescueAgent->rescueAgentSettings = $rescueAgentSettings;

        // Attempt to copy the source agentInfo key file, using the copy from the zfs snapshot
        // to preserve the known state of the backup (e.g. multiple volumes)
        $snapshotPath = sprintf(
            AgentSnapshotRepository::BACKUP_DIRECTORY_TEMPLATE,
            $this->getKeyName(),
            $snapshotEpoch
        );
        $snapshotAgentInfoKeyFile = $snapshotPath . '/' . $this->getKeyName() . '.agentInfo';
        $rescueAgentInfoKeyFile = self::KEYBASE . $rescueAgent->getKeyName() . '.agentInfo';
        if ($fileSystem->exists($snapshotAgentInfoKeyFile)) {
            $fileSystem->copy($snapshotAgentInfoKeyFile, $rescueAgentInfoKeyFile);
        } else {
            throw new Exception("Cannot create rescue agent from " . $this->getName() . ": agentInfo file does not exist.");
        }

        // The serializers expect the .agentInfo file to already be in place prior to the initial serialization.
        // TODO: It feels like the serializers would be a good place to handle the copying of the kvmSettings file
        // eventually; currently the VirtualizationSettings are populated from the .agentInfo file and represent the
        // state of the agent as a default for virtualizations.
        $copiedKeyFiles = array('diskDrives', 'kvmSettings', 'esxSettings', 'hvSettings', 'include');
        foreach ($copiedKeyFiles as $keyFile) {
            $sourceKeyFile = self::KEYBASE . $this->getKeyName() . '.' . $keyFile;
            $rescueKeyFile = self::KEYBASE . $rescueAgent->getKeyName() . '.' . $keyFile;
            if ($fileSystem->exists($sourceKeyFile)) {
                $fileSystem->copy($sourceKeyFile, $rescueKeyFile);
            }
        }

        // We want to save and then re-serialize the newly created rescue agent to sever any object dependencies
        // between the rescue and source agents (i.e. dependencies that exist in a subclass that we don't have
        // access to here).
        $agentService->save($rescueAgent);
        $rescueAgent = $agentService->get($rescueAgent->getKeyName());
        return $rescueAgent;
    }

    /**
     * One-time configuration for an agent when first set up.
     */
    public function initialConfiguration()
    {
    }

    /**
     * @return ZFS_Dataset
     */
    public function getDataset()
    {
        return $this->dataset;
    }

    public function setDataset(ZFS_Dataset $dataset): void
    {
        $this->dataset = $dataset;
    }

    /**
     * @return OperatingSystem
     */
    public function getOperatingSystem()
    {
        return $this->operatingSystem;
    }

    public function getVolumes(): Volumes
    {
        return $this->volumes;
    }

    public function getVolume(string $guid): Volume
    {
        foreach ($this->volumes as $volume) {
            if ($volume->getGuid() === $guid) {
                return $volume;
            }
        }

        throw new Exception("Could not find volume with guid: $guid");
    }

    /**
     * @return IncludedVolumesSettings
     */
    public function getIncludedVolumesSettings()
    {
        return $this->includedVolumesSettings;
    }

    /**
     * @return IncludedVolumesMetaSettings
     */
    public function getIncludedVolumesMetaSettings()
    {
        return $this->includedVolumesMetaSettings;
    }

    /**
     * @return DriverSettings
     */
    public function getDriver()
    {
        return $this->driver;
    }

    /**
     * @return string
     */
    public function getHostname()
    {
        return $this->hostname;
    }

    /**
     * @param string $hostname
     */
    public function setHostname(string $hostname): void
    {
        $this->hostname = $hostname;
    }

    /**
     * @return float
     */
    public function getUsedBySnapshots()
    {
        return $this->usedBySnapshots;
    }

    /**
     * @return float
     */
    public function getUsedLocally()
    {
        return $this->usedLocally;
    }

    /**
     * @return int
     */
    public function getCpuCount()
    {
        return $this->cpuCount;
    }

    /**
     * Determine if the agent supports diff merges of individual volumes.
     *
     * @return bool
     */
    public function isVolumeDiffMergeSupported()
    {
        return false;
    }

    /**
     * @return boolean
     */
    public function isRescueAgent()
    {
        return $this->rescueAgentSettings !== null;
    }

    /**
     * @return RescueAgentSettings
     */
    public function getRescueAgentSettings()
    {
        return $this->rescueAgentSettings;
    }

    /**
     * @return SecuritySettings
     */
    public function getShareAuth()
    {
        return $this->shareAuth;
    }

    /**
     * @param SecuritySettings $shareAuth
     */
    public function setShareAuth($shareAuth): void
    {
        $this->shareAuth = $shareAuth;
    }

    /**
     * @return EncryptionSettings
     */
    public function getEncryption()
    {
        return $this->encryption;
    }

    /**
     * @return int
     */
    public function getMemory()
    {
        return $this->memory;
    }

    /**
     * @return ScreenshotSettings
     */
    public function getScreenshot()
    {
        return $this->screenshot;
    }

    /**
     * @return ScreenshotVerificationSettings
     */
    public function getScreenshotVerification()
    {
        return $this->screenshotVerification;
    }

    /**
     * Get the domain name to use for host communication.
     *
     * @return string
     */
    public function getFullyQualifiedDomainName()
    {
        return $this->fullyQualifiedDomainName;
    }

    /**
     * Set the domain name used for host communication.
     *
     * @param $newName string
     */
    public function setFullyQualifiedDomainName($newName): void
    {
        $this->fullyQualifiedDomainName = $newName;
    }

    /**
     * @return string
     */
    public function getDisplayName()
    {
        return $this->getHostname() ?: $this->getFullyQualifiedDomainName();
    }

    /**
     * @inheritdoc
     */
    public function getPairName(): string
    {
        if ($this->isRescueAgent()) {
            return $this->getDisplayName();
        }
        return $this->getFullyQualifiedDomainName() ?: $this->getKeyName();
    }

    /**
     * @return AgentPlatform
     */
    public function getPlatform(): AgentPlatform
    {
        return $this->platform;
    }

    /**
     * @return DirectToCloudAgentSettings|null
     */
    public function getDirectToCloudAgentSettings()
    {
        return $this->directToCloudAgentSettings;
    }

    /**
     * @return bool
     */
    public function isDirectToCloudAgent(): bool
    {
        return $this->directToCloudAgentSettings !== null;
    }

    /**
     * Determine if the agent is fully supported.
     *
     * @return bool
     */
    public function isSupportedOperatingSystem(): bool
    {
        return true;
    }

    /**
     * Returns whether or not this agent is using the full disk backup method
     *  originally developed to support generic agentless backups.
     *
     * @return bool
     */
    public function isFullDiskBackup(): bool
    {
        return $this->fullDiskBackup;
    }

    /**
     * Sets whether or not this agent is using the full disk backup method
     *  originally developed to support generic agentless backups.
     *
     * @param bool $fullDiskBackup
     */
    public function setFullDiskBackup(bool $fullDiskBackup): void
    {
        $this->fullDiskBackup = $fullDiskBackup;
    }

    /**
     * @inheritdoc
     */
    public function copyFrom(Asset $asset)
    {
        parent::copyFrom($asset);

        if ($asset instanceof Agent) {
            $this->verificationSchedule->copyFrom($asset->getVerificationSchedule());
            $this->screenshotVerification->copyFrom($asset->getScreenshotVerification());
            $this->shareAuth->copyFrom($asset->getShareAuth());
        }
    }

    /**
     * Most agents have no disk drives, only volumes.
     *
     * @return array
     */
    public function getDiskDrives(): array
    {
        return [];
    }

    /**
     * Some agent types communicate over SSL, which uses a certificate.  Other agent types can go directly to the
     * filesystem to take a backup, and do not need an SSL certificate for agent communication.
     * @return bool True if the device does not need to use SSL certs to communicate with the agent.
     */
    public function communicatesWithoutCerts(): bool
    {
        return $this->getOriginDevice()->isReplicated() ||
            $this->getLocal()->isArchived() ||
            $this->isRescueAgent() ||
            $this->isDirectToCloudAgent() ||
            $this->isType(AssetType::AGENTLESS);
    }

    public function supportsDiffMerge(): bool
    {
        return true;
    }

    /**
     * @param AgentPlatform $platform
     */
    protected function setPlatform(AgentPlatform $platform): void
    {
        $this->platform = $platform;
    }

    public function isForcePartitionRewrite(): bool
    {
        return $this->forcePartitionRewrite;
    }

    /**
     * Sets whether or not this agent should be forced to rewrite it's
     * partition table again on next backup
     *
     * @param bool $forcePartitionRewrite
     */
    public function setForcePartitionRewrite(bool $forcePartitionRewrite): void
    {
        $this->forcePartitionRewrite = $forcePartitionRewrite;
    }
}
