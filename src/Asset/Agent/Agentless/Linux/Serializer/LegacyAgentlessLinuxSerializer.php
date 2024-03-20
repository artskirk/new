<?php

namespace Datto\Asset\Agent\Agentless\Linux\Serializer;

use Datto\AppKernel;
use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Agentless\Serializer\LegacyEsxInfoSerializer;
use Datto\Asset\Agent\IncludedVolumesKeyService;
use Datto\Asset\Agent\Serializer\EncryptionSettingsSerializer;
use Datto\Asset\Agent\Serializer\DirectToCloudAgentSettingsSerializer;
use Datto\Asset\Agent\Agentless\Serializer\LegacyAgentlessDriverSettingsSerializer;
use Datto\Asset\Agent\Serializer\RescueAgentSettingsSerializer;
use Datto\Asset\Agent\Serializer\ScreenshotVerificationSettingsSerializer;
use Datto\Asset\Agent\Serializer\VirtualizationSettingsSerializer;
use Datto\Asset\Agent\VolumesService;
use Datto\Asset\AssetType;
use Datto\Asset\Serializer\LegacyEmailAddressSettingsSerializer;
use Datto\Asset\Serializer\LegacyLastErrorSerializer;
use Datto\Asset\Serializer\LegacyLocalSettingsSerializer;
use Datto\Asset\Serializer\LegacyOffsiteSettingsSerializer;
use Datto\Asset\Agent\Agentless\Linux\LinuxAgent;
use Datto\Asset\Serializer\OffsiteTargetSerializer;
use Datto\Asset\Serializer\OriginDeviceSerializer;
use Datto\Asset\Serializer\ScriptSettingsSerializer;
use Datto\Asset\Serializer\Serializer;
use Datto\Asset\Serializer\VerificationScheduleSerializer;
use Datto\Asset\VerificationSerializer;
use Datto\Dataset\DatasetFactory;
use Datto\Utility\ByteUnit;
use InvalidArgumentException;
use Datto\Asset\Agent\Serializer\SecuritySettingsSerializer;

/**
 * @author Rixhers Ajazi <rajazi@datto.com>
 */
class LegacyAgentlessLinuxSerializer implements Serializer
{
    /** @var LegacyLocalSettingsSerializer */
    private $localSerializer;

    /** @var LegacyOffsiteSettingsSerializer */
    private $offsiteSerializer;

    /** @var LegacyEmailAddressSettingsSerializer */
    private $emailAddressesSerializer;

    /** @var LegacyAgentlessLinuxOperatingSystemSerializer */
    private $operatingSystemSerializer;

    /** @var LegacyAgentlessDriverSettingsSerializer */
    private $driverSerializer;

    /** @var EncryptionSettingsSerializer */
    private $encryptionSerializer;

    /** @var ScreenshotVerificationSettingsSerializer */
    private $screenshotVerificationSerializer;

    /** @var VirtualizationSettingsSerializer */
    private $virtualizationSerializer;

    /** @var SecuritySettingsSerializer */
    private $securitySettingsSerializer;

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

    /** @var LegacyEsxInfoSerializer */
    private $legacyEsxInfoSerializer;

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
        LegacyAgentlessLinuxOperatingSystemSerializer $operatingSystemSerializer = null,
        LegacyAgentlessDriverSettingsSerializer $driverSerializer = null,
        EncryptionSettingsSerializer $encryptionSerializer = null,
        ScreenshotVerificationSettingsSerializer $screenshotVerificationSerializer = null,
        VirtualizationSettingsSerializer $virtualizationSerializer = null,
        SecuritySettingsSerializer $securitySettingsSerializer = null,
        ScriptSettingsSerializer $scriptSettingsSerializer = null,
        VerificationScheduleSerializer $verificationScheduleSerializer = null,
        RescueAgentSettingsSerializer $rescueAgentSettingsSerializer = null,
        LegacyLastErrorSerializer $lastErrorSerializer = null,
        OriginDeviceSerializer $originDeviceSerializer = null,
        OffsiteTargetSerializer $offsiteTargetSerializer = null,
        LegacyEsxInfoSerializer $legacyEsxInfoSerializer = null,
        VolumesService $volumesService = null,
        IncludedVolumesKeyService $includedVolumesKeyService = null,
        DirectToCloudAgentSettingsSerializer $directToCloudAgentSettingsSerializer = null
    ) {
        $this->datasetFactory = $datasetFactory ?? new DatasetFactory();
        $this->localSerializer = $localSerializer ?? new LegacyLocalSettingsSerializer();
        $this->offsiteSerializer = $offsiteSerializer ?? new LegacyOffsiteSettingsSerializer();
        $this->emailAddressesSerializer = $emailAddressesSerializer ?? new LegacyEmailAddressSettingsSerializer();
        $this->operatingSystemSerializer = $operatingSystemSerializer ?? new LegacyAgentlessLinuxOperatingSystemSerializer();
        $this->driverSerializer = $driverSerializer ?? new LegacyAgentlessDriverSettingsSerializer();
        $this->encryptionSerializer = $encryptionSerializer ?? new EncryptionSettingsSerializer();
        $this->screenshotVerificationSerializer = $screenshotVerificationSerializer ?? new ScreenshotVerificationSettingsSerializer();
        $this->virtualizationSerializer = $virtualizationSerializer ?? new VirtualizationSettingsSerializer();
        $this->securitySettingsSerializer = $securitySettingsSerializer ?? new SecuritySettingsSerializer();
        $this->scriptSettingsSerializer = $scriptSettingsSerializer ?? new ScriptSettingsSerializer();
        $this->verificationScheduleSerializer = $verificationScheduleSerializer ?? new VerificationScheduleSerializer();
        $this->rescueAgentSettingsSerializer = $rescueAgentSettingsSerializer ?? new RescueAgentSettingsSerializer();
        $this->lastErrorSerializer = $lastErrorSerializer ?? new LegacyLastErrorSerializer();
        $this->originDeviceSerializer = $originDeviceSerializer ?? new OriginDeviceSerializer();
        $this->directToCloudAgentSettingsSerializer = $directToCloudAgentSettingsSerializer ?? new DirectToCloudAgentSettingsSerializer();
        $this->offsiteTargetSerializer = $offsiteTargetSerializer ?? new OffsiteTargetSerializer();
        $this->legacyEsxInfoSerializer = $legacyEsxInfoSerializer ?? new LegacyEsxInfoSerializer();
        $this->volumesService = $volumesService ?? new VolumesService();
        $this->includedVolumesKeyService = $includedVolumesKeyService ??
            AppKernel::getBootedInstance()->getContainer()->get(IncludedVolumesKeyService::class);
    }

    /**
     * {@inheritdoc}
     */
    public function serialize($agent)
    {
        $agentInfo = array(
            'name' => $agent->getName(),
            'hostname' => $agent->getHostname(),
            'hostName' => $agent->getHostname(),
            'uuid' => $agent->getUuid(),
            'usedBySnaps' => $agent->getUsedBySnapshots(),
            'localUsed' => $agent->getUsedLocally(),
            'cpus' => $agent->getCpuCount(),
            'ram' => $agent->getMemory(),
            'generated' => $agent->getGenerated(),
            'cores' => $agent->getCpuCount(),
            'memory' => intval(round(ByteUnit::BYTE()->toMiB($agent->getMemory()))),
            'type' => AssetType::AGENTLESS_LINUX,
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
            'offsiteTarget' => $this->offsiteTargetSerializer->serialize($agent->getOffsiteTarget())
        );

        $fileArray = array_merge_recursive(
            $fileArray,
            $this->localSerializer->serialize($agent->getLocal()), // backupPause, interval, schedule, retention, ...
            // ... recoveryPoints
            $this->offsiteSerializer->serialize($agent->getOffsite()), // offsiteControl, offsiteRetentionLimits, ...
            // ... offsiteSchedule, offsiteRetention, offSitePoints, offSitePointsCache
            $this->encryptionSerializer->serialize($agent->getEncryption()), // encryption, encryptionTempAccess, ...
            // ... encryptionKeyStash
            $this->virtualizationSerializer->serialize($agent->getVirtualizationSettings()), // legacyVM
            $this->securitySettingsSerializer->serialize($agent->getShareAuth()), // shareAuth
            $this->scriptSettingsSerializer->serialize($agent->getScriptSettings()), // scriptSettings
            $this->directToCloudAgentSettingsSerializer->serialize($agent->getDirectToCloudAgentSettings()), // directToCloudAgentSettings
            $this->rescueAgentSettingsSerializer->serialize($agent->getRescueAgentSettings()), // rescueAgentSettings
            $this->originDeviceSerializer->serialize($agent->getOriginDevice()) // originDevice
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
     * {@inheritdoc}
     */
    public function unserialize($fileArray)
    {
        if (!isset($fileArray['agentInfo']) || !$fileArray['agentInfo']) {
            throw new InvalidArgumentException('Cannot read "agentInfo" contents.');
        }

        $agentInfo = @unserialize($fileArray['agentInfo'], ['allowed_classes' => false]);

        if (!isset($agentInfo['name']) || empty($agentInfo['name'])) {
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
        $screenshotVerification = $this->screenshotVerificationSerializer->unserialize($fileArray);
        $virtualizationVerification = $this->virtualizationSerializer->unserialize($fileArray);
        $scriptSettings = $this->scriptSettingsSerializer->unserialize($fileArray);
        $verificationSchedule = $this->verificationScheduleSerializer->unserialize($fileArray);
        $rescueAgentSettings = $this->rescueAgentSettingsSerializer->unserialize($fileArray);
        $lastError = $this->lastErrorSerializer->unserialize(@$fileArray['lastError']);
        $offsiteTarget = $this->offsiteTargetSerializer->unserialize(@$fileArray['offsiteTarget']);
        $esxInfo = $this->legacyEsxInfoSerializer->unserialize(@$fileArray['esxInfo']);

        // TODO: investigate combining some of these into a new object (or with existing objects) and use them in the builder
        $hostname = isset($agentInfo['hostname']) ? $agentInfo['hostname'] : null;
        $uuid = isset($agentInfo['uuid']) ? $agentInfo['uuid'] : null;
        $usedBySnaps = isset($agentInfo['usedBySnaps']) ? floatval($agentInfo['usedBySnaps']) : null;
        $localUsed = isset($agentInfo['localUsed']) ? floatval($agentInfo['localUsed']) : null;
        $cpus = isset($agentInfo['cpus']) ? intval($agentInfo['cpus']) : null;
        $ram = isset($agentInfo['ram']) ? intval($agentInfo['ram']) : null;
        $generated = isset($agentInfo['generated']) ? intval($agentInfo['generated']) : null;
        $shareAuth = $this->securitySettingsSerializer->unserialize($fileArray);

        $dataset = $this->datasetFactory->createZfsDataset(sprintf(Agent::ZFS_PATH_TEMPLATE, $keyName));

        $agent = new LinuxAgent(
            $name,
            $keyName,
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
            $uuid,
            $usedBySnaps,
            $localUsed,
            $cpus,
            $ram,
            $generated,
            $shareAuth,
            $fullDiskBackup,
            $forcePartitionRewrite,
            $esxInfo,
            null,
            $screenshotVerification,
            $virtualizationVerification,
            $scriptSettings,
            $verificationSchedule,
            $rescueAgentSettings,
            $lastError,
            $originDevice,
            $offsiteTarget,
            $directToCloudAgentSettings
        );

        return $agent;
    }
}
