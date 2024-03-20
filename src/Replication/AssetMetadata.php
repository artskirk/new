<?php

namespace Datto\Replication;

use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\AssetType;
use Datto\Asset\DatasetPurpose;
use Exception;

/**
 * Simple object model for basic asset information.
 *
 * @author John Roland <jroland@datto.com>
 */
class AssetMetadata
{
    const ALLOWED_TYPES = [
        AssetType::WINDOWS_AGENT,
        AssetType::LINUX_AGENT,
        AssetType::MAC_AGENT,
        AssetType::NAS_SHARE,
        AssetType::ISCSI_SHARE,
        AssetType::EXTERNAL_NAS_SHARE,
        AssetType::AGENTLESS_LINUX,
        AssetType::AGENTLESS_WINDOWS
    ];

    /** @var string */
    private $assetKey;

    /** @var DatasetPurpose */
    private $datasetPurpose;

    /** @var AgentPlatform */
    private $agentPlatform;

    /** @var string */
    private $hostname;

    /** @var string */
    private $fqdn;

    /** @var string */
    private $displayName;

    /** @var string */
    private $operatingSystem;

    /** @var int */
    private $originDeviceId;

    /** @var int */
    private $originResellerId;

    /** @var boolean */
    private $encryption;

    /** @var array */
    private $encryptionKeyStash;

    /** @var string */
    private $agentVersion;

    /** @var bool */
    private $isMigrationInProgress;

    /**
     * @param string $assetKey
     */
    public function __construct(string $assetKey)
    {
        $this->assetKey = $assetKey;
    }

    /**
     * @return string
     */
    public function getAssetKey(): string
    {
        return $this->assetKey;
    }

    /**
     * @return DatasetPurpose|null
     */
    public function getDatasetPurpose()
    {
        return $this->datasetPurpose;
    }

    /**
     * @param DatasetPurpose $datasetPurpose
     */
    public function setDatasetPurpose(DatasetPurpose $datasetPurpose)
    {
        $this->datasetPurpose = $datasetPurpose;
    }

    /**
     * @return AgentPlatform|null
     */
    public function getAgentPlatform()
    {
        return $this->agentPlatform;
    }

    /**
     * @param AgentPlatform|null $agentPlatform
     */
    public function setAgentPlatform(AgentPlatform $agentPlatform = null)
    {
        $this->agentPlatform = $agentPlatform;
    }

    /**
     * @return string|null
     */
    public function getAgentVersion()
    {
        return $this->agentVersion;
    }

    /**
     * @param string|null $agentVersion
     */
    public function setAgentVersion(string $agentVersion = null)
    {
        $this->agentVersion = $agentVersion;
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
    public function setHostname($hostname)
    {
        $this->hostname = $hostname;
    }

    /**
     * @return string
     */
    public function getFqdn()
    {
        return $this->fqdn;
    }

    /**
     * @param string $fqdn
     */
    public function setFqdn($fqdn)
    {
        $this->fqdn = $fqdn;
    }

    /**
     * @return string
     */
    public function getDisplayName()
    {
        return $this->displayName;
    }

    /**
     * @param string $displayName
     */
    public function setDisplayName($displayName)
    {
        $this->displayName = $displayName;
    }

    /**
     * @return string
     */
    public function getOperatingSystem()
    {
        return $this->operatingSystem;
    }

    /**
     * @param string $operatingSystem
     */
    public function setOperatingSystem($operatingSystem)
    {
        $this->operatingSystem = $operatingSystem;
    }

    /**
     * @return int
     */
    public function getOriginDeviceId()
    {
        return $this->originDeviceId;
    }

    /**
     * @param int $originDeviceId
     */
    public function setOriginDeviceId($originDeviceId)
    {
        $this->originDeviceId = $originDeviceId;
    }

    /**
     * @return int
     */
    public function getOriginResellerId()
    {
        return $this->originResellerId;
    }

    /**
     * @param int $originResellerId
     */
    public function setOriginResellerId($originResellerId)
    {
        $this->originResellerId = $originResellerId;
    }

    /**
     * @return boolean
     */
    public function getEncryption()
    {
        return $this->encryption;
    }

    /**
     * @param boolean
     */
    public function setEncryption($encryption)
    {
        $this->encryption = $encryption;
    }


    /**
     * @return array|null
     */
    public function getEncryptionKeyStash()
    {
        return $this->encryptionKeyStash;
    }

    /**
     * @param array|null $encryptionKeyStash
     */
    public function setEncryptionKeyStash($encryptionKeyStash)
    {
        $this->encryptionKeyStash = $encryptionKeyStash;
    }

    /**
     * @return bool
     */
    public function getIsMigrationInProgress()
    {
        return $this->isMigrationInProgress;
    }

    /**
     * @param bool $isMigrationInProgress
     */
    public function setIsMigrationInProgress($isMigrationInProgress)
    {
        $this->isMigrationInProgress = $isMigrationInProgress;
    }

    /**
     * @param string $assetKey
     * @param mixed $assetMetadataArray
     * @return AssetMetadata
     */
    public static function fromArray(string $assetKey, $assetMetadataArray): AssetMetadata
    {
        if (!isset($assetMetadataArray['datasetPurpose'])) {
            throw new Exception('The following field is required for asset metadata: datasetPurpose');
        }

        $assetMetadataObject = new AssetMetadata($assetKey);

        $datasetPurpose = DatasetPurpose::memberOrNullByValue($assetMetadataArray['datasetPurpose']);
        $assetMetadataObject->setDatasetPurpose($datasetPurpose);

        $agentPlatform = AgentPlatform::memberOrNullByValue($assetMetadataArray['agentPlatform']);
        $assetMetadataObject->setAgentPlatform($agentPlatform);

        $assetMetadataObject->setHostname($assetMetadataArray['hostname'] ?? null);
        $assetMetadataObject->setFqdn($assetMetadataArray['fqdn'] ?? null);
        $assetMetadataObject->setDisplayName($assetMetadataArray['displayName'] ?? null);
        $assetMetadataObject->setOperatingSystem($assetMetadataArray['operatingSystem'] ?? null);
        $assetMetadataObject->setOriginDeviceId($assetMetadataArray['originDeviceId'] ?? null);
        $assetMetadataObject->setOriginResellerId($assetMetadataArray['originResellerId'] ?? null);
        $assetMetadataObject->setEncryption($assetMetadataArray['encryption'] ?? false);
        $assetMetadataObject->setEncryptionKeyStash($assetMetadataArray['encryptionKeyStash'] ?? null);
        $assetMetadataObject->setIsMigrationInProgress($assetMetadataArray['isMigrationInProgress'] ?? false);

        return $assetMetadataObject;
    }
}
