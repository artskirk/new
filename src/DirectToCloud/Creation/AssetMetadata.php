<?php

namespace Datto\DirectToCloud\Creation;

use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\Encryption\EncryptionKeyStashRecord;
use Datto\Asset\DatasetPurpose;

class AssetMetadata
{
    private string $assetKey;
    private string $assetUuid;
    private string $hostname;
    private string $fqdn;
    private DatasetPurpose $datasetPurpose;
    private AgentPlatform $agentPlatform;
    private string $operatingSystem;
    private ?EncryptionKeyStashRecord $encryptionKeyStashRecord;

    public function __construct(
        string $assetKey,
        string $assetUuid,
        string $hostname,
        string $fqdn,
        DatasetPurpose $datasetPurpose,
        AgentPlatform $agentPlatform,
        string $operatingSystem,
        ?EncryptionKeyStashRecord $encryptionKeyStashRecord
    ) {
        $this->assetKey = $assetKey;
        $this->assetUuid = $assetUuid;
        $this->hostname = $hostname;
        $this->fqdn = $fqdn;
        $this->datasetPurpose = $datasetPurpose;
        $this->agentPlatform = $agentPlatform;
        $this->operatingSystem = $operatingSystem;
        $this->encryptionKeyStashRecord = $encryptionKeyStashRecord;
    }

    public function getAssetKey(): string
    {
        return $this->assetKey;
    }

    public function getAssetUuid(): string
    {
        return $this->assetUuid;
    }

    public function getDatasetPurpose(): DatasetPurpose
    {
        return $this->datasetPurpose;
    }

    public function getAgentPlatform(): AgentPlatform
    {
        return $this->agentPlatform;
    }

    public function getHostname(): string
    {
        return $this->hostname;
    }

    public function getFqdn(): string
    {
        return $this->fqdn;
    }

    public function getOperatingSystem(): string
    {
        return $this->operatingSystem;
    }

    public function getEncryptionKeyStashRecord(): ?EncryptionKeyStashRecord
    {
        return $this->encryptionKeyStashRecord;
    }
}
