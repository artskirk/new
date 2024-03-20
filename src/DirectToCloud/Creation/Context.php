<?php

namespace Datto\DirectToCloud\Creation;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Retention;
use Datto\Asset\VerificationSchedule;
use Datto\ZFS\ZfsDataset;

/**
 * Context for creating a direct-to-cloud agent.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class Context
{
    // Populated on construction
    private AssetMetadata $assetMetadata;
    private Retention $retention;
    private Retention $offsiteRetention;
    private int $resellerId;
    private VerificationSchedule $verificationSchedule;
    private string $offsiteTarget;
    private bool $useExistingDataset;
    private bool $archived;

    // Populated in transaction
    private ?Agent $agent;
    private ZfsDataset $dataset;

    public function __construct(
        AssetMetadata $assetMetadata,
        Retention $retention,
        Retention $offsiteRetention,
        int $resellerId,
        VerificationSchedule $verificationSchedule,
        string $offsiteTarget,
        bool $useExistingDataset,
        bool $archived
    ) {
        $this->assetMetadata = $assetMetadata;
        $this->retention = $retention;
        $this->offsiteRetention = $offsiteRetention;
        $this->resellerId = $resellerId;
        $this->verificationSchedule = $verificationSchedule;
        $this->offsiteTarget = $offsiteTarget;
        $this->useExistingDataset = $useExistingDataset;
        $this->archived = $archived;
    }

    /**
     * @return AssetMetadata
     */
    public function getAssetMetadata(): AssetMetadata
    {
        return $this->assetMetadata;
    }

    /**
     * This happens to match the agent's UUID.
     *
     * @return string
     */
    public function getAssetKey(): string
    {
        return $this->assetMetadata->getAssetKey();
    }

    /**
     * @return Retention
     */
    public function getRetention(): Retention
    {
        return $this->retention;
    }

    /**
     * @return Retention
     */
    public function getOffsiteRetention(): Retention
    {
        return $this->offsiteRetention;
    }

    /**
     * @return int
     */
    public function getResellerId(): int
    {
        return $this->resellerId;
    }

    /**
     * @return Agent|null
     */
    public function getAgent()
    {
        return $this->agent;
    }

    /**
     * @return Agent
     * @throws \Exception
     */
    public function getAgentOrThrow(): Agent
    {
        if (!isset($this->agent)) {
            throw new \Exception('Agent object not found on context');
        }

        return $this->agent;
    }

    /**
     * @param Agent $agent
     */
    public function setAgent(Agent $agent)
    {
        $this->agent = $agent;
    }

    /**
     * @return bool
     */
    public function useExistingDataset(): bool
    {
        return $this->useExistingDataset;
    }

    /**
     * @return ZfsDataset|null
     */
    public function getDataset()
    {
        return $this->dataset;
    }

    /**
     * @param ZfsDataset $dataset
     */
    public function setDataset(ZfsDataset $dataset)
    {
        $this->dataset = $dataset;
    }

    public function getOffsiteTarget(): string
    {
        return $this->offsiteTarget;
    }

    /**
     * @return VerificationSchedule
     */
    public function getVerificationSchedule(): VerificationSchedule
    {
        return $this->verificationSchedule;
    }

    /**
     * @return bool
     */
    public function isArchived(): bool
    {
        return $this->archived;
    }

    public function getLogContext(): array
    {
        return [
            'assetMetadata' => [
                'assetKey' => $this->assetMetadata->getAssetKey(),
                'hostname' => $this->assetMetadata->getHostname(),
                'fqdn' => $this->assetMetadata->getFqdn(),
                'datasetPurpose' => $this->assetMetadata->getDatasetPurpose(),
                'agentPlatform' => $this->assetMetadata->getAgentPlatform(),
                'operatingSystem' => $this->assetMetadata->getOperatingSystem()
            ],
            'archived' => $this->archived
        ];
    }
}
