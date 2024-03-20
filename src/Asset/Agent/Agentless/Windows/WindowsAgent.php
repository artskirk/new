<?php

namespace Datto\Asset\Agent\Agentless\Windows;

use Datto\Asset\Agent\Agentless\AgentlessSystem;
use Datto\Asset\Agent\Agentless\EsxInfo;
use Datto\Asset\Agent\DriverSettings;
use Datto\Asset\Agent\EncryptionSettings;
use Datto\Asset\Agent\IncludedVolumesMetaSettings;
use Datto\Asset\Agent\IncludedVolumesSettings;
use Datto\Asset\Agent\OperatingSystem;
use Datto\Asset\Agent\RescueAgentSettings;
use Datto\Asset\Agent\ScreenshotVerificationSettings;
use Datto\Asset\Agent\VirtualizationSettings;
use Datto\Asset\Agent\Volumes;
use Datto\Asset\AssetType;
use Datto\Asset\EmailAddressSettings;
use Datto\Asset\LastErrorAlert;
use Datto\Asset\LocalSettings;
use Datto\Asset\OffsiteSettings;
use Datto\Asset\OriginDevice;
use Datto\Asset\ScriptSettings;
use Datto\Asset\VerificationSchedule;
use Datto\Dataset\ZFS_Dataset;
use Datto\Screenshot\ScreenshotSettings;

/**
 * Class to represent an Agentless Windows system
 *
 * Developer note:
 *   Be sure to make all properties injectable through the constructor, so that the
 *   state of the object can be recreated from a config file. Do NOT provide public
 *   setters for properties that could set the object into an inconsistent state,
 *   e.g. don't provide a setEnabled() method.
 *
 * @author Rixhers Ajazi <rajazi@datto.com>
 */
class WindowsAgent extends AgentlessSystem
{
    /** @var VirtualizationSettings $virtualizationSettings */
    private $virtualizationSettings;

    public function __construct(
        $name,
        $keyName,
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
        EncryptionSettings $encryptionSettings,
        $hostname,
        $uuid,
        $fullyQualifiedDomainName,
        $usedBySnapshots,
        $usedLocally,
        $generated,
        $cpuCount,
        $memory,
        $shareAuth,
        bool $fullDiskBackup,
        bool $forcePartitionRewrite,
        EsxInfo $esxInfo,
        ScreenshotSettings $screenshot = null,
        ScreenshotVerificationSettings $screenshotVerification = null,
        VirtualizationSettings $virtualizationSettings = null,
        ScriptSettings $scriptSettings = null,
        VerificationSchedule $verificationSchedule = null,
        RescueAgentSettings $rescueAgentSettings = null,
        LastErrorAlert $lastError = null,
        OriginDevice $originDevice = null,
        string $offsiteTarget = null
    ) {
        parent::__construct(
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
            $encryptionSettings,
            $hostname,
            $uuid,
            $usedBySnapshots,
            $usedLocally,
            $cpuCount,
            $memory,
            $generated,
            AssetType::AGENTLESS_WINDOWS,
            $shareAuth,
            $fullDiskBackup,
            $forcePartitionRewrite,
            $esxInfo,
            $screenshot,
            $screenshotVerification,
            $scriptSettings,
            $verificationSchedule,
            $rescueAgentSettings,
            $fullyQualifiedDomainName,
            $lastError,
            $originDevice,
            $offsiteTarget
        );

        $this->virtualizationSettings = $virtualizationSettings;
    }

    /**
     * Get the virtualization settings
     *
     * @return VirtualizationSettings
     */
    public function getVirtualizationSettings()
    {
        return $this->virtualizationSettings;
    }
}
