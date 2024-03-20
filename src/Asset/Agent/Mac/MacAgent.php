<?php

namespace Datto\Asset\Agent\Mac;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\DriverSettings;
use Datto\Asset\Agent\EncryptionSettings;
use Datto\Asset\Agent\IncludedVolumesMetaSettings;
use Datto\Asset\Agent\IncludedVolumesSettings;
use Datto\Asset\Agent\OperatingSystem;
use Datto\Asset\Agent\PrePostScripts\PrePostScriptSettings;
use Datto\Asset\Agent\RescueAgentSettings;
use Datto\Asset\Agent\ScreenshotVerificationSettings;
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
 * Class to represent a Mac agent.
 *
 * Developer note:
 *   Be sure to make all properties injectable through the constructor, so that the
 *   state of the object can be recreated from a config file. Do NOT provide public
 *   setters for properties that could set the object into an inconsistent state,
 *   e.g. don't provide a setEnabled() method.
 *
 * @deprecated Mac agents are essentially not supported. Do not worry about maintaining code in here. It is ok to
 * completely remove the Mac code if it becomes an obstacle to future features/fixes, just ensure we have release notes
 * for the removal.
 * @author Rixhers Ajazi <rajazi@datto.com>
 */
class MacAgent extends Agent
{
    /** @var PrePostScriptSettings $prePostScripts */
    private $prePostScripts;

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
            AssetType::MAC_AGENT,
            AgentPlatform::DATTO_MAC_AGENT(),
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
            null,
            $offsiteTarget
        );
        $this->prePostScripts = $prePostScripts;
    }

    /**
     * @deprecated Mac agents are essentially not supported. Do not worry about maintaining code in here. It is ok to
     * completely remove the Mac code if it becomes an obstacle to future features/fixes, just ensure we have release
     * notes for the removal.
     * @return PrePostScriptSettings
     */
    public function getPrePostScripts()
    {
        return $this->prePostScripts;
    }
}
