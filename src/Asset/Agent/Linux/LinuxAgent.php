<?php

namespace Datto\Asset\Agent\Linux;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\DirectToCloudAgentSettings;
use Datto\Asset\Agent\DriverSettings;
use Datto\Asset\Agent\EncryptionSettings;
use Datto\Asset\Agent\IncludedVolumesMetaSettings;
use Datto\Asset\Agent\IncludedVolumesSettings;
use Datto\Asset\Agent\OperatingSystem;
use Datto\Asset\Agent\PrePostScripts\PrePostScriptSettings;
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
 * Class to represent a Linux agent.
 *
 * Developer note:
 *   Be sure to make all properties injectable through the constructor, so that the
 *   state of the object can be recreated from a config file. Do NOT provide public
 *   setters for properties that could set the object into an inconsistent state,
 *   e.g. don't provide a setEnabled() method.
 *
 * @author Bartosz Neuman <bneuman@datto.com>
 */
class LinuxAgent extends Agent
{
    /** @var PrePostScriptSettings $prePostScripts */
    private $prePostScripts;

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
        $fullyQualifiedDomainName,
        $uuid,
        $usedBySnapshots,
        $usedLocally,
        $cpuCount,
        $memory,
        $shareAuth,
        bool $fullDiskBackup,
        bool $forcePartitionRewrite,
        PrePostScriptSettings $prePostScripts,
        ScreenshotSettings $screenshot = null,
        ScreenshotVerificationSettings $screenshotVerification = null,
        VirtualizationSettings $virtualizationSettings = null,
        ScriptSettings $scriptSettings = null,
        VerificationSchedule $verificationSchedule = null,
        RescueAgentSettings $rescueAgentSettings = null,
        LastErrorAlert $lastError = null,
        OriginDevice $originDevice = null,
        string $offsiteTarget = null,
        DirectToCloudAgentSettings $directToCloudAgentSettings = null
    ) {
        parent::__construct(
            $name,
            $keyName,
            AssetType::LINUX_AGENT,
            is_null($directToCloudAgentSettings) ? AgentPlatform::DATTO_LINUX_AGENT() : AgentPlatform::DIRECT_TO_CLOUD(),
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
            $usedBySnapshots,
            $usedLocally,
            $cpuCount,
            $memory,
            $shareAuth,
            $fullDiskBackup,
            $forcePartitionRewrite,
            $screenshot,
            $screenshotVerification,
            $scriptSettings,
            $verificationSchedule,
            $rescueAgentSettings,
            $fullyQualifiedDomainName,
            $uuid,
            $lastError,
            $originDevice,
            $directToCloudAgentSettings,
            $offsiteTarget
        );

        $this->prePostScripts = $prePostScripts;
        $this->virtualizationSettings = $virtualizationSettings;
    }

    /**
     * @return PrePostScriptSettings
     */
    public function getPrePostScripts()
    {
        return $this->prePostScripts;
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
