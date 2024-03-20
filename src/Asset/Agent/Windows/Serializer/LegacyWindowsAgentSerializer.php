<?php

namespace Datto\Asset\Agent\Windows\Serializer;

use Datto\AppKernel;
use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\IncludedVolumesKeyService;
use Datto\Asset\Agent\Serializer\DirectToCloudAgentSettingsSerializer;
use Datto\Asset\Agent\Serializer\LegacyVmxBackupSettingsSerializer;
use Datto\Asset\Agent\Serializer\RescueAgentSettingsSerializer;
use Datto\Asset\Agent\Serializer\EncryptionSettingsSerializer;
use Datto\Asset\Agent\Serializer\ScreenshotVerificationSettingsSerializer;
use Datto\Asset\Agent\Serializer\VirtualizationSettingsSerializer;
use Datto\Asset\Agent\Volumes;
use Datto\Asset\Agent\VolumesCollector;
use Datto\Asset\Agent\VolumesNormalizer;
use Datto\Asset\Agent\VolumesService;
use Datto\Asset\Agent\Windows\WindowsAgent;
use Datto\Asset\Agent\Serializer\LegacyDriverSettingsSerializer;
use Datto\Asset\Serializer\LegacyEmailAddressSettingsSerializer;
use Datto\Asset\Serializer\LegacyLastErrorSerializer;
use Datto\Asset\Serializer\LegacyLocalSettingsSerializer;
use Datto\Asset\Serializer\LegacyOffsiteSettingsSerializer;
use Datto\Asset\Serializer\OffsiteTargetSerializer;
use Datto\Asset\Serializer\OriginDeviceSerializer;
use Datto\Asset\Serializer\ScriptSettingsSerializer;
use Datto\Asset\Serializer\Serializer;
use Datto\Asset\Serializer\VerificationScheduleSerializer;
use Datto\Asset\VerificationSerializer;
use Datto\Config\AgentConfigFactory;
use Datto\Dataset\DatasetFactory;
use Datto\Utility\ByteUnit;
use InvalidArgumentException;
use Datto\Asset\Agent\Serializer\SecuritySettingsSerializer;
use Datto\Asset\Serializer\BackupConstraintsSerializer;

/**
 * @author Bartosz Neuman <bneuman@datto.com>
 */
class LegacyWindowsAgentSerializer implements Serializer
{
    /** @var LegacyLocalSettingsSerializer */
    private $localSerializer;

    /** @var LegacyOffsiteSettingsSerializer */
    private $offsiteSerializer;

    /** @var LegacyEmailAddressSettingsSerializer */
    private $emailAddressesSerializer;

    /** @var LegacyWindowsOperatingSystemSerializer */
    private $operatingSystemSerializer;

    /** @var LegacyDriverSettingsSerializer */
    private $driverSerializer;

    /** @var EncryptionSettingsSerializer */
    private $encryptionSerializer;

    /** @var BackupSettingsSerializer */
    private $backupSettingsSerializer;

    /** @var ScreenshotVerificationSettingsSerializer */
    private $screenshotVerificationSerializer;

    /** @var VirtualizationSettingsSerializer */
    private $virtualizationSerializer;

    /** @var VssWriterSettingsSerializer */
    private $vssWriterSettingsSerializer;

    /** @var SecuritySettingsSerializer */
    private $securitySettingsSerializer;

    /** @var LegacyVmxBackupSettingsSerializer */
    private $vmxBackupSettingsSerializer;

    /** @var ScriptSettingsSerializer */
    private $scriptSettingsSerializer;

    /** @var VerificationScheduleSerializer */
    private $verificationScheduleSerializer;

    /** @var RescueAgentSettingsSerializer */
    private $rescueAgentSettingsSerializer;

    /** @var LegacyLastErrorSerializer */
    private $lastErrorSerializer;

    /** @var OriginDeviceSerializer */
    private $originDeviceSerializer;

    /** @var DirectToCloudAgentSettingsSerializer */
    private $directToCloudAgentSettingsSerializer;

    /** @var OffsiteTargetSerializer */
    private $offsiteTargetSerializer;

    /** @var BackupConstraintsSerializer */
    private $backupConstraintsSerializer;

    /** @var DatasetFactory */
    private $datasetFactory;

    /** @var VolumesService */
    private $volumesService;

    /** @var IncludedVolumesKeyService */
    private $includedVolumesKeyService;

    public function __construct(
        DatasetFactory $datasetFactory = null,
        LegacyLocalSettingsSerializer $localSerializer = null,
        LegacyOffsiteSettingsSerializer $offsiteSerializer = null,
        LegacyEmailAddressSettingsSerializer $emailAddressesSerializer = null,
        LegacyWindowsOperatingSystemSerializer $operatingSystemSerializer = null,
        LegacyDriverSettingsSerializer $driverSerializer = null,
        EncryptionSettingsSerializer $encryptionSerializer = null,
        BackupSettingsSerializer $backupSettingsSerializer = null,
        ScreenshotVerificationSettingsSerializer $screenshotVerificationSerializer = null,
        VirtualizationSettingsSerializer $virtualizationSerializer = null,
        VssWriterSettingsSerializer $vssWriterSettingsSerializer = null,
        SecuritySettingsSerializer $securitySettingsSerializer = null,
        LegacyVmxBackupSettingsSerializer $vmxBackupSettingsSerializer = null,
        ScriptSettingsSerializer $scriptSettingsSerializer = null,
        VerificationScheduleSerializer $verificationScheduleSerializer = null,
        RescueAgentSettingsSerializer $rescueAgentSettingsSerializer = null,
        LegacyLastErrorSerializer $lastErrorSerializer = null,
        OriginDeviceSerializer $originDeviceSerializer = null,
        DirectToCloudAgentSettingsSerializer $directToCloudAgentSettingsSerializer = null,
        OffsiteTargetSerializer $offsiteTargetSerializer = null,
        BackupConstraintsSerializer $backupConstraintsSerializer = null,
        VolumesService $volumesService = null,
        IncludedVolumesKeyService $includedVolumesKeyService = null
    ) {
        $this->datasetFactory = $datasetFactory ?? new DatasetFactory();
        $this->localSerializer = $localSerializer ?? new LegacyLocalSettingsSerializer();
        $this->offsiteSerializer = $offsiteSerializer ?? new LegacyOffsiteSettingsSerializer();
        $this->emailAddressesSerializer = $emailAddressesSerializer ?? new LegacyEmailAddressSettingsSerializer();
        $this->operatingSystemSerializer = $operatingSystemSerializer ?? new LegacyWindowsOperatingSystemSerializer();
        $this->driverSerializer = $driverSerializer ?? new LegacyDriverSettingsSerializer();
        $this->encryptionSerializer = $encryptionSerializer ?? new EncryptionSettingsSerializer();
        $this->backupSettingsSerializer = $backupSettingsSerializer ?? new BackupSettingsSerializer();
        $this->screenshotVerificationSerializer = $screenshotVerificationSerializer ?? new ScreenshotVerificationSettingsSerializer();
        $this->virtualizationSerializer = $virtualizationSerializer ?? new VirtualizationSettingsSerializer();
        $this->vssWriterSettingsSerializer = $vssWriterSettingsSerializer ?? new VssWriterSettingsSerializer();
        $this->securitySettingsSerializer = $securitySettingsSerializer ?? new SecuritySettingsSerializer();
        $this->vmxBackupSettingsSerializer = $vmxBackupSettingsSerializer ?? new LegacyVmxBackupSettingsSerializer();
        $this->scriptSettingsSerializer = $scriptSettingsSerializer ?? new ScriptSettingsSerializer();
        $this->verificationScheduleSerializer = $verificationScheduleSerializer ?? new  VerificationScheduleSerializer();
        $this->rescueAgentSettingsSerializer = $rescueAgentSettingsSerializer ?? new RescueAgentSettingsSerializer();
        $this->lastErrorSerializer = $lastErrorSerializer ?? new LegacyLastErrorSerializer();
        $this->originDeviceSerializer = $originDeviceSerializer ?? new OriginDeviceSerializer();
        $this->directToCloudAgentSettingsSerializer = $directToCloudAgentSettingsSerializer ?? new DirectToCloudAgentSettingsSerializer();
        $this->offsiteTargetSerializer = $offsiteTargetSerializer ?? new OffsiteTargetSerializer();
        $this->backupConstraintsSerializer = $backupConstraintsSerializer ?? new BackupConstraintsSerializer();
        $this->volumesService = $volumesService ?? new VolumesService();
        $this->includedVolumesKeyService = $includedVolumesKeyService ??
            AppKernel::getBootedInstance()->getContainer()->get(IncludedVolumesKeyService::class);
    }

    /**
     * Serialize the given object into an array (not a string!).
     *
     * @param WindowsAgent $agent object to convert into an array
     * @return array Serialized object
     */
    public function serialize($agent)
    {
        $agentInfo = array(
            'name' => $agent->getName(),
            'hostname' => $agent->getHostname(),
            'hostName' => $agent->getHostname(),
            'generated' => $agent->getGenerated(),
            'usedBySnaps' => $agent->getUsedBySnapshots(),
            'localUsed' => $agent->getUsedLocally(),
            'fqdn' => $agent->getFullyQualifiedDomainName(),
            'uuid' => $agent->getUuid(),
            'cores' => $agent->getCpuCount(),
            'cpus' => $agent->getCpuCount(),
            'memory' => round(ByteUnit::BYTE()->toMib($agent->getMemory())),
            'ram' => "{$agent->getMemory()}", // Old code stores this as string!
            'isVirtualMachine' => $agent->isVirtualMachine(),
            'volumes' => $agent->getVolumes()->toArray()
        );


        $agentInfo = array_merge_recursive(
            $agentInfo,
            $this->operatingSystemSerializer->serialize($agent->getOperatingSystem()),
            $this->driverSerializer->serialize($agent->getDriver())
        );

        $fileArray = array(
            'agentInfo' => serialize($agentInfo),
            'dateAdded' => $agent->getDateAdded(),
            'emails' => $this->emailAddressesSerializer->serialize($agent->getEmailAddresses()),
            'fullDiskBackup' => $agent->isFullDiskBackup(),
            'forcePartitionRewrite' => $agent->isForcePartitionRewrite(),
            'lastError' => $this->lastErrorSerializer->serialize($agent->getLastError()),
            // Note: if .shadowSnap key did not exist, and $agentInfo['type'] did not exist, then platform is ShadowSnap
            // when we save again, this will cause AssetRepository to write out .shadowSnap key file
            'shadowSnap' => $agent->getPlatform() === AgentPlatform::SHADOWSNAP(),
            'offsiteTarget' => $this->offsiteTargetSerializer->serialize($agent->getOffsiteTarget()),
            BackupConstraintsSerializer::KEY => $this->backupConstraintsSerializer->serialize($agent->getBackupConstraints())
        );

        $fileArray = array_merge_recursive(
            $fileArray,
            $this->localSerializer->serialize($agent->getLocal()), // backupPause, interval, schedule, retention, recoveryPoints
            $this->offsiteSerializer->serialize($agent->getOffsite()), // offsiteControl, offsiteRetentionLimits, offsiteSchedule, offsiteRetention, offSitePoints, offSitePointsCache
            $this->encryptionSerializer->serialize($agent->getEncryption()), // encryption, encryptionTempAccess, encryptionKeyStash
            $this->backupSettingsSerializer->serialize($agent->getBackupSettings()), // backupEngine
            $this->virtualizationSerializer->serialize($agent->getVirtualizationSettings()), // legacyVM
            $this->vssWriterSettingsSerializer->serialize($agent->getVssWriterSettings()), // vssWriters, vssExclude
            $this->securitySettingsSerializer->serialize($agent->getShareAuth()), // shareAuth
            $this->vmxBackupSettingsSerializer->serialize($agent->getVmxBackupSettings()), // backupVMX
            $this->scriptSettingsSerializer->serialize($agent->getScriptSettings()), // scriptSettings
            $this->rescueAgentSettingsSerializer->serialize($agent->getRescueAgentSettings()), // rescueAgentSettings
            $this->directToCloudAgentSettingsSerializer->serialize($agent->getDirectToCloudAgentSettings()), // directToCloudAgentSettings
            $this->originDeviceSerializer->serialize($agent->getOriginDevice()), // originDevice
        );

        $verificationSerializer = new VerificationSerializer(
            $this->screenshotVerificationSerializer,
            $this->verificationScheduleSerializer
        ); // screenshotVerification

        $fileArray = array_merge_recursive(
            $fileArray,
            $verificationSerializer->serialize($agent->getVerificationSchedule(), $agent->getScreenshotVerification())
        );

        return $fileArray;
    }

    /**
     * Create an object from the given array.
     *
     * @param array $fileArray Serialized object
     * @return WindowsAgent built with the array's data
     */
    public function unserialize($fileArray)
    {
        if (!isset($fileArray['agentInfo']) || !$fileArray['agentInfo']) {
            throw new InvalidArgumentException('Cannot read "agentInfo" contents.');
        }

        $agentInfo = @unserialize($fileArray['agentInfo'], ['allowed_classes' => false]);

        if (empty($agentInfo['name'])) {
            throw new InvalidArgumentException('Cannot read "name" attribute for agent.');
        }

        $name = $agentInfo['name'];
        $keyName = $fileArray['keyName'];
        $fullDiskBackup = $fileArray['fullDiskBackup'] ?? false;
        $forcePartitionRewrite = $fileArray['forcePartitionRewrite'] ?? false;
        $dateAdded = isset($fileArray['dateAdded']) ? $fileArray['dateAdded'] : null;

        $originDevice = $this->originDeviceSerializer->unserialize($fileArray);
        $directToCloudAgentSettings = $this->directToCloudAgentSettingsSerializer->unserialize($fileArray);

        $local = $this->localSerializer->unserialize($fileArray);
        $offsite = $this->offsiteSerializer->unserialize($fileArray);
        $emailAddresses = $this->emailAddressesSerializer->unserialize($fileArray);

        $operatingSystem = $this->operatingSystemSerializer->unserialize($agentInfo);
        $volumes = $this->volumesService->getVolumesFromKey($keyName);
        $includedVolumesSettings = $this->includedVolumesKeyService->loadFromKey($keyName);
        $includedVolumesMetaSettings = $this->volumesService->getIncludedVolumeMetaSettings(
            $keyName,
            explode("\n", $fileArray['recoveryPoints'] ?? '')
        );
        $driver = $this->driverSerializer->unserialize($agentInfo);
        $encryption = $this->encryptionSerializer->unserialize($fileArray);
        $backupSettings = $this->backupSettingsSerializer->unserialize($fileArray); // backupEngine
        $screenshotVerification = $this->screenshotVerificationSerializer->unserialize($fileArray);
        $virtualizationVerification = $this->virtualizationSerializer->unserialize($fileArray);
        $vssWriters = $this->vssWriterSettingsSerializer->unserialize($fileArray);
        $shareAuth = $this->securitySettingsSerializer->unserialize($fileArray);
        $vmxBackup = $this->vmxBackupSettingsSerializer->unserialize($fileArray);
        $scriptSettings = $this->scriptSettingsSerializer->unserialize($fileArray);
        $verificationSchedule = $this->verificationScheduleSerializer->unserialize($fileArray);
        $rescueAgentSettings = $this->rescueAgentSettingsSerializer->unserialize($fileArray);
        $lastError = $this->lastErrorSerializer->unserialize(@$fileArray['lastError']);
        $offsiteTarget = $this->offsiteTargetSerializer->unserialize(@$fileArray['offsiteTarget']);
        $backupConstraints = $this->backupConstraintsSerializer->unserialize(@$fileArray[BackupConstraintsSerializer::KEY]);

        // TODO: investigate combining some of these into a new object (or with existing objects) and use them in the builder
        $hostname = isset($agentInfo['hostname']) ? $agentInfo['hostname'] : null;
        $fqdn = empty($agentInfo['fqdn']) ? $name : $agentInfo['fqdn'];
        $uuid = isset($agentInfo['uuid']) ? $agentInfo['uuid'] : null;
        $usedBySnaps = isset($agentInfo['usedBySnaps']) ? floatval($agentInfo['usedBySnaps']) : null;
        $localUsed = isset($agentInfo['localUsed']) ? floatval($agentInfo['localUsed']) : null;
        $generated = isset($agentInfo['generated']) ? intval($agentInfo['generated']) : null;
        $cpus = isset($agentInfo['cpus']) ? intval($agentInfo['cpus']) : null;
        $ram = isset($agentInfo['ram']) ? intval($agentInfo['ram']) : null;
        $isVirtualMachine = $agentInfo['isVirtualMachine'] ?? false;

        if (!is_null($directToCloudAgentSettings)) {
            $platform = AgentPlatform::DIRECT_TO_CLOUD();
        } elseif (($fileArray['shadowSnap'] ?? false) === true || is_null($agentInfo['type'] ?? null)) {
            $platform = AgentPlatform::SHADOWSNAP();
        } else {
            $platform = AgentPlatform::DATTO_WINDOWS_AGENT();
        }

        $dataset = $this->datasetFactory->createZfsDataset(sprintf(Agent::ZFS_PATH_TEMPLATE, $keyName));

        $agent = new WindowsAgent(
            $name,
            $keyName,
            $platform,
            $dateAdded,
            $dataset,
            $local,
            $offsite,
            $emailAddresses,
            $operatingSystem,
            $volumes,
            $includedVolumesSettings,
            $includedVolumesMetaSettings,
            $driver,
            $encryption,
            $hostname,
            $fqdn,
            $uuid,
            $usedBySnaps,
            $localUsed,
            $generated,
            $cpus,
            $ram,
            $backupSettings,
            $shareAuth,
            $fullDiskBackup,
            $forcePartitionRewrite,
            $isVirtualMachine,
            null,
            $screenshotVerification,
            $virtualizationVerification,
            $vssWriters,
            $vmxBackup,
            $scriptSettings,
            $verificationSchedule,
            $rescueAgentSettings,
            $lastError,
            $originDevice,
            $directToCloudAgentSettings,
            $offsiteTarget,
            $backupConstraints
        );

        return $agent;
    }
}
