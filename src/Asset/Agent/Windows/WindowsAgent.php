<?php

namespace Datto\Asset\Agent\Windows;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\DiffMergeSettings;
use Datto\Asset\Agent\DriverSettings;
use Datto\Asset\Agent\DirectToCloudAgentSettings;
use Datto\Asset\Agent\EncryptionSettings;
use Datto\Asset\Agent\IncludedVolumesMetaSettings;
use Datto\Asset\Agent\IncludedVolumesSettings;
use Datto\Asset\Agent\OperatingSystem;
use Datto\Asset\Agent\RescueAgentSettings;
use Datto\Asset\Agent\ScreenshotVerificationSettings;
use Datto\Asset\Agent\VirtualizationSettings;
use Datto\Asset\Agent\VmxBackupSettings;
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
use Datto\Asset\BackupConstraints;

/**
 * Class to represent a Windows agent.
 *
 * Developer note:
 *   Be sure to make all properties injectable through the constructor, so that the
 *   state of the object can be recreated from a config file. Do NOT provide public
 *   setters for properties that could set the object into an inconsistent state,
 *   e.g. don't provide a setEnabled() method.
 *
 * @author Bartosz Neuman <bneuman@datto.com>
 */
class WindowsAgent extends Agent
{
    /** Lowset DWA version that sujpports per volume diffmerge */
    const DWA_VERSION_PER_VOL_DIFFMERGE = '2.4.0.0';

    /** DWA Platform */
    const PLATFORM_DWA = 'DWA';

    /** @var int */
    private $generated;

    /** @var DiffMergeSettings */
    private $diffMergeSettings;

    /** @var bool */
    private $virtualMachine;

    /** @var BackupSettings */
    private $backupSettings;

    /** @var VirtualizationSettings $virtualizationSettings */
    private $virtualizationSettings;

    /** @var  VssWriterSettings */
    private $vssWriterSettings;

    /** @var VmxBackupSettings */
    private $vmxBackupSettings;

    public function __construct(
        $name,
        $keyName,
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
        EncryptionSettings $encryptionSettings,
        $hostname,
        $fullyQualifiedDomainName,
        $uuid,
        $usedBySnapshots,
        $usedLocally,
        $generated,
        $cpuCount,
        $memory,
        BackupSettings $backupSettings,
        $shareAuth,
        bool $fullDiskBackup,
        bool $forcePartitionRewrite,
        $virtualMachine,
        ScreenshotSettings $screenshot = null,
        ScreenshotVerificationSettings $screenshotVerification = null,
        VirtualizationSettings $virtualizationSettings = null,
        VssWriterSettings $vssWriterSettings = null,
        VmxBackupSettings $vmxBackupSettings = null,
        ScriptSettings $scriptSettings = null,
        VerificationSchedule $verificationSchedule = null,
        RescueAgentSettings $rescueAgentSettings = null,
        LastErrorAlert $lastError = null,
        OriginDevice $originDevice = null,
        DirectToCloudAgentSettings $directToCloudAgentSettings = null,
        string $offsiteTarget = null,
        BackupConstraints $backupConstraints = null
    ) {
        parent::__construct(
            $name,
            $keyName,
            AssetType::WINDOWS_AGENT,
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
            $offsiteTarget,
            $backupConstraints
        );

        $this->generated = $generated;
        $this->virtualMachine = $virtualMachine;
        $this->backupSettings = $backupSettings;
        $this->virtualizationSettings = $virtualizationSettings;
        $this->vssWriterSettings = $vssWriterSettings ?: new VssWriterSettings();
        $this->vmxBackupSettings = $vmxBackupSettings ?: new VmxBackupSettings();
    }

    /**
     * One-time configuration for an agent when first set up.
     */
    public function initialConfiguration()
    {
        $this->vssWriterSettings->excludeDfsWriter();
    }

    /**
     * The epoch time the agentInfo file was created.
     *
     * @return int
     */
    public function getGenerated()
    {
        return $this->generated;
    }

    /**
     * @return bool
     */
    public function isVirtualMachine()
    {
        return $this->virtualMachine;
    }

    /**
     * @return BackupSettings
     */
    public function getBackupSettings()
    {
        return $this->backupSettings;
    }

    /**
     * @return VssWriterSettings
     */
    public function getVssWriterSettings()
    {
        return $this->vssWriterSettings;
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

    /**
     * Get the VMX Backup settings.
     *
     * @return VmxBackupSettings
     */
    public function getVmxBackupSettings()
    {
        return $this->vmxBackupSettings;
    }

    /**
     * @inheritDoc
     */
    public function isVolumeDiffMergeSupported()
    {
        return $this->getPlatform()->getShortName() === self::PLATFORM_DWA &&
               version_compare($this->getDriver()->getAgentVersion(), self::DWA_VERSION_PER_VOL_DIFFMERGE, '>=') &&
               !$this->isRescueAgent();
    }
}
