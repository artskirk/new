<?php

namespace Datto\Asset\Agent\Mac\Serializer;

use Datto\AppKernel;
use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\IncludedVolumesKeyService;
use Datto\Asset\Agent\Serializer\EncryptionSettingsSerializer;
use Datto\Asset\Agent\Serializer\LegacyDriverSettingsSerializer;
use Datto\Asset\Agent\Serializer\LegacyPrePostScriptsSerializer;
use Datto\Asset\Agent\Serializer\ScreenshotVerificationSettingsSerializer;
use Datto\Asset\Agent\Volumes;
use Datto\Asset\Agent\VolumesCollector;
use Datto\Asset\Agent\VolumesNormalizer;
use Datto\Asset\Agent\VolumesService;
use Datto\Asset\Serializer\LegacyEmailAddressSettingsSerializer;
use Datto\Asset\Serializer\LegacyLocalSettingsSerializer;
use Datto\Asset\Serializer\LegacyLastErrorSerializer;
use Datto\Asset\Serializer\LegacyOffsiteSettingsSerializer;
use Datto\Asset\Agent\Mac\MacAgent;
use Datto\Asset\Serializer\OffsiteTargetSerializer;
use Datto\Asset\Serializer\OriginDeviceSerializer;
use Datto\Asset\Serializer\ScriptSettingsSerializer;
use Datto\Asset\Serializer\Serializer;
use Datto\Asset\Serializer\VerificationScheduleSerializer;
use Datto\Asset\VerificationSerializer;
use Datto\Config\AgentConfigFactory;
use Datto\Dataset\DatasetFactory;
use InvalidArgumentException;
use Datto\Asset\Agent\Serializer\SecuritySettingsSerializer;

/**
* @deprecated Mac agents are essentially not supported. Do not worry about maintaining code in here. It is ok to
 * completely remove the Mac code if it becomes an obstacle to future features/fixes, just ensure we have release notes
 * for the removal.
 * @author Rixhers Ajazi <rajazi@datto.com>
 */
class LegacyMacAgentSerializer implements Serializer
{
    /** @var LegacyLocalSettingsSerializer */
    private $localSerializer;

    /** @var LegacyOffsiteSettingsSerializer */
    private $offsiteSerializer;

    /** @var LegacyEmailAddressSettingsSerializer */
    private $emailAddressesSerializer;

    /** @var LegacyMacOperatingSystemSerializer */
    private $operatingSystemSerializer;

    /** @var LegacyDriverSettingsSerializer */
    private $driverSerializer;

    /** @var EncryptionSettingsSerializer */
    private $encryptionSerializer;

    /** @var Serializer */
    private $prePostScriptsSerializer;

    /** @var ScreenshotVerificationSettingsSerializer */
    private $screenshotVerificationSerializer;

    /** @var SecuritySettingsSerializer */
    private $securitySettingsSerializer;

    /** @var ScriptSettingsSerializer */
    private $scriptSettingsSerializer;

    /** @var VerificationScheduleSerializer */
    private $verificationScheduleSerializer;

    /** @var LegacyLastErrorSerializer */
    private $lastErrorSerializer;

    /** @var OriginDeviceSerializer */
    private $originDeviceSerializer;

    /** @var OffsiteTargetSerializer */
    private $offsiteTargetSerializer;

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
        LegacyMacOperatingSystemSerializer $operatingSystemSerializer = null,
        LegacyDriverSettingsSerializer $driverSerializer = null,
        EncryptionSettingsSerializer $encryptionSerializer = null,
        LegacyPrePostScriptsSerializer $prePostScriptsSerializer = null,
        ScreenshotVerificationSettingsSerializer $screenshotVerificationSerializer = null,
        SecuritySettingsSerializer $securitySettingsSerializer = null,
        ScriptSettingsSerializer $scriptSettingsSerializer = null,
        VerificationScheduleSerializer $verificationScheduleSerializer = null,
        LegacyLastErrorSerializer $lastErrorSerializer = null,
        OriginDeviceSerializer $originDeviceSerializer = null,
        OffsiteTargetSerializer $offsiteTargetSerializer = null,
        VolumesService $volumesService = null,
        IncludedVolumesKeyService $includedVolumesKeyService = null
    ) {
        $this->datasetFactory = $datasetFactory ?? new DatasetFactory();
        $this->localSerializer = $localSerializer ?? new LegacyLocalSettingsSerializer();
        $this->offsiteSerializer = $offsiteSerializer ?? new LegacyOffsiteSettingsSerializer();
        $this->emailAddressesSerializer = $emailAddressesSerializer ?? new LegacyEmailAddressSettingsSerializer();
        $this->operatingSystemSerializer = $operatingSystemSerializer ?? new LegacyMacOperatingSystemSerializer();
        $this->driverSerializer = $driverSerializer ?? new LegacyDriverSettingsSerializer();
        $this->encryptionSerializer = $encryptionSerializer ?? new EncryptionSettingsSerializer();
        $this->prePostScriptsSerializer = $prePostScriptsSerializer ?? new LegacyPrePostScriptsSerializer();
        $this->screenshotVerificationSerializer = $screenshotVerificationSerializer ?? new ScreenshotVerificationSettingsSerializer();
        $this->securitySettingsSerializer = $securitySettingsSerializer ?? new SecuritySettingsSerializer();
        $this->scriptSettingsSerializer = $scriptSettingsSerializer ?? new ScriptSettingsSerializer();
        $this->verificationScheduleSerializer = $verificationScheduleSerializer ?? new VerificationScheduleSerializer();
        $this->lastErrorSerializer = $lastErrorSerializer ?? new LegacyLastErrorSerializer();
        $this->originDeviceSerializer = $originDeviceSerializer ?? new OriginDeviceSerializer();
        $this->offsiteTargetSerializer = $offsiteTargetSerializer ?? new OffsiteTargetSerializer();
        $this->volumesService = $volumesService ?? new VolumesService();
        $this->includedVolumesKeyService = $includedVolumesKeyService ??
            AppKernel::getBootedInstance()->getContainer()->get(IncludedVolumesKeyService::class);
    }

    /**
     * Serialize the given object into an array (not a string!).
     *
     * @deprecated Mac agents are essentially not supported. Do not worry about maintaining code in here. It is ok to
     * completely remove the Mac code if it becomes an obstacle to future features/fixes, just ensure we have release notes
     * for the removal.
     * @param MacAgent $agent object to convert into an array
     * @return array Serialized object
     */
    public function serialize($agent)
    {
        $agentInfo = array(
            'name' => $agent->getName(),
            'hostname' => $agent->getHostname(),
            'hostName' => $agent->getHostname(),
            'usedBySnaps' => $agent->getUsedBySnapshots(),
            'localUsed' => $agent->getUsedLocally(),
            'fqdn' => $agent->getFullyQualifiedDomainName(),
            'uuid' => $agent->getUuid(),
            'cpus' => $agent->getCpuCount(),
            'ram' => $agent->getMemory(),
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
            $this->localSerializer->serialize($agent->getLocal()), // backupPause, interval, schedule, retention, recoveryPoints
            $this->offsiteSerializer->serialize($agent->getOffsite()), // offsiteControl, offsiteRetentionLimits, offsiteSchedule, offsiteRetention, offSitePoints, offSitePointsCache
            $this->encryptionSerializer->serialize($agent->getEncryption()), // encryption, encryptionTempAccess, encryptionKeyStash
            $this->prePostScriptsSerializer->serialize($agent->getPrePostScripts()), // pps
            $this->securitySettingsSerializer->serialize($agent->getShareAuth()), // shareAuth
            $this->scriptSettingsSerializer->serialize($agent->getScriptSettings()), // scriptSettings
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
     * Create an object from the given array.
     *
     * @deprecated Mac agents are essentially not supported. Do not worry about maintaining code in here. It is ok to
     * completely remove the Mac code if it becomes an obstacle to future features/fixes, just ensure we have release
     * notes for the removal.
     * @param array $fileArray Serialized object
     * @return MacAgent built with the array's data
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
        $prePostScripts = $this->prePostScriptsSerializer->unserialize($fileArray);
        $screenshotVerification = $this->screenshotVerificationSerializer->unserialize($fileArray);
        $shareAuth = $this->securitySettingsSerializer->unserialize($fileArray);
        $scriptSettings = $this->scriptSettingsSerializer->unserialize($fileArray);
        $verificationSchedule = $this->verificationScheduleSerializer->unserialize($fileArray);
        $lastError = $this->lastErrorSerializer->unserialize(@$fileArray['lastError']);
        $offsiteTarget = $this->offsiteTargetSerializer->unserialize(@$fileArray['offsiteTarget']);

        // TODO: investigate combining some of these into a new object (or with existing objects) and use them in the builder
        $hostname = isset($agentInfo['hostname']) ? $agentInfo['hostname'] : null;
        $fqdn = empty($agentInfo['fqdn']) ? $name : $agentInfo['fqdn'];
        $uuid = isset($agentInfo['uuid']) ? $agentInfo['uuid'] : null;
        $usedBySnaps = isset($agentInfo['usedBySnaps']) ? $agentInfo['usedBySnaps'] : null;
        $localUsed = isset($agentInfo['localUsed']) ? floatval($agentInfo['localUsed']) : null;
        $cpus = isset($agentInfo['cpus']) ? intval($agentInfo['cpus']) : null;
        $ram = isset($agentInfo['ram']) ? intval($agentInfo['ram']) : null;
        $rescueAgentSettings = null; // There is no salvation for Mac users

        $dataset = $this->datasetFactory->createZfsDataset(sprintf(Agent::ZFS_PATH_TEMPLATE, $keyName));

        $agent = new MacAgent(
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
            $fqdn,
            $uuid,
            $usedBySnaps,
            $localUsed,
            $cpus,
            $ram,
            $shareAuth,
            $fullDiskBackup,
            $forcePartitionRewrite,
            $prePostScripts,
            null,
            $screenshotVerification,
            $scriptSettings,
            $verificationSchedule,
            $rescueAgentSettings,
            $lastError,
            $originDevice,
            $offsiteTarget
        );

        return $agent;
    }
}
