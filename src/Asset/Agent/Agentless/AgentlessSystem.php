<?php

namespace Datto\Asset\Agent\Agentless;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\DirectToCloudAgentSettings;
use Datto\Asset\Agent\DriverSettings;
use Datto\Asset\Agent\EncryptionSettings;
use Datto\Asset\Agent\IncludedVolumesMetaSettings;
use Datto\Asset\Agent\IncludedVolumesSettings;
use Datto\Asset\Agent\OperatingSystem;
use Datto\Asset\Agent\ScreenshotVerificationSettings;
use Datto\Asset\Agent\Volumes;
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
 * Class to represent an Agentless system.
 *
 * Developer note:
 *   Be sure to make all properties injectable through the constructor, so that the
 *   state of the object can be recreated from a config file. Do NOT provide public
 *   setters for properties that could set the object into an inconsistent state,
 *   e.g. don't provide a setEnabled() method.
 *
 * @author Rixhers Ajazi <rajazi@datto.com>
 */
abstract class AgentlessSystem extends Agent
{
    const SUPPORTED_OPERATING_SYSTEMS = [
        'ubuntu',
        'windows',
        'debian',
        'red hat',
        'centos',
    ];

    const UNKNOWN_HOSTNAME = 'unknown';

    /** @var int */
    protected $generated;

    /** @var EsxInfo */
    private $esxInfo;

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
        $usedBySnapshots,
        $usedLocally,
        $cpuCount,
        $memory,
        $generated,
        $type,
        $shareAuth,
        bool $fullDiskBackup,
        bool $forcePartitionRewrite,
        EsxInfo $esxInfo,
        ScreenshotSettings $screenshot = null,
        ScreenshotVerificationSettings $screenshotVerification = null,
        ScriptSettings $scriptSettings = null,
        VerificationSchedule $verificationSchedule = null,
        $rescueAgentSettings = null,
        $fullyQualifiedDomainName = '',
        LastErrorAlert $lastError = null,
        OriginDevice $originDevice = null,
        string $offsiteTarget = null,
        DirectToCloudAgentSettings $directToCloudAgentSettings = null
    ) {
        parent::__construct(
            $name,
            $keyName,
            $type,
            AgentPlatform::AGENTLESS(),
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

        $this->generated = $generated;
        $this->esxInfo = $esxInfo;
    }

    /**
     * Determine if an operating system is on the list of officially supported systems.
     *
     * @param string $osName
     * @return bool
     */
    public static function isOperatingSystemFullySupported(string $osName): bool
    {
        $os = trim(strtolower($osName));
        foreach (static::SUPPORTED_OPERATING_SYSTEMS as $validOS) {
            if (strpos($os, $validOS) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * The epoch time the agentInfo file was created
     *
     * @return int
     */
    public function getGenerated()
    {
        return $this->generated;
    }

    /**
     * @return string
     */
    public function getHostname()
    {
        $hostname = parent::getHostname();
        if (!$hostname || $hostname === static::UNKNOWN_HOSTNAME) {
            return $this->getName();
        }
        return $hostname;
    }

    /**
     * @return string
     */
    public function getDisplayName()
    {
        return $this->getHostname();
    }

    /**
     * @inheritdoc
     */
    public function getPairName(): string
    {
        return $this->getName() ?: $this->getKeyName();
    }

    public function getEsxInfo(): EsxInfo
    {
        return $this->esxInfo;
    }
}
