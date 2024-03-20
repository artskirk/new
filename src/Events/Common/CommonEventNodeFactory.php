<?php

namespace Datto\Events\Common;

use Datto\Asset\AssetType;
use Datto\Config\AgentConfigFactory;
use Datto\Config\DeviceConfig;
use Datto\Common\Resource\PosixHelper;
use Datto\System\Transaction\Transaction;
use Datto\Verification\Stages\VerificationStage;

/**
 * Create Event nodes containing generally-useful information
 *
 * *IMPORTANT*: Changes to nodes created by this factory will affect the schema of any Events that use them.
 *
 * If data nodes are changed, the schema for any Events that use them must be incremented.
 *
 * If either data or context nodes are changed, anything that uses the Event data must be updated.
 * For example, any dashboards or other applications that use the data will need changes.
 */
class CommonEventNodeFactory
{
    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var PosixHelper */
    private $posixHelper;

    /** @var AgentConfigFactory */
    private $agentConfigFactory;

    public function __construct(
        DeviceConfig $deviceConfig,
        PosixHelper $posixHelper,
        AgentConfigFactory $agentConfigFactory
    ) {
        $this->deviceConfig = $deviceConfig;
        $this->posixHelper = $posixHelper;
        $this->agentConfigFactory = $agentConfigFactory;
    }

    public function createAssetData(string $assetKey, string $snapshot = null): AssetData
    {
        $agentConfig = $this->agentConfigFactory->create($assetKey);

        $agentInfo = $agentConfig->getAgentInfo();
        if (!is_array($agentInfo)) {
            $agentInfo = [];
        }

        $isEncrypted = $agentConfig->isEncrypted();

        $isDirectToCloud = $agentConfig->isDirectToCloud();
        $isShadowsnap = $agentConfig->isShadowsnap();
        $agentPlatform = AssetType::getAgentPlatform($agentInfo, $isDirectToCloud, $isShadowsnap);

        return new AssetData(
            $assetKey,
            $agentInfo['hostname'] ?? $agentInfo['name'] ?? '',
            $isEncrypted,
            $agentPlatform ? $agentPlatform->getFriendlyName() : null,
            $agentInfo['agentVersion'] ?? null,
            !empty($agentInfo['apiVersion']) ? $agentInfo['apiVersion'] : null,
            !empty($agentInfo['version']) ? $agentInfo['version'] : null,
            !empty($agentInfo['os_name']) ? $agentInfo['os_name'] : null,
            $snapshot,
            $agentInfo['os_version'] ?? null,
            $agentInfo['shareType'] ?? null
        );
    }

    public function createPlatformData(): PlatformData
    {
        return new PlatformData(
            $this->deviceConfig->getDisplayModel(),
            $this->deviceConfig->getImageVersion() ?? 0,
            $this->posixHelper->getSystemName()['release'],
            $this->deviceConfig->getOs2Version() ?? '',
            $this->deviceConfig->getRole(),
            $this->deviceConfig->getDeploymentEnvironment(),
            $this->deviceConfig->getDatacenterRegion()
        );
    }

    public function createResultsContext(Transaction $transaction): ResultsContext
    {
        $stages = [];
        foreach ($transaction->getCommittedStages() as $stage) {
            /** @var VerificationStage $stage */
            $errorMessage = $stage->getResult()->getErrorMessage();
            $stages[$stage->getName()] = new Result(
                $stage->getResult()->didSucceed(),
                $errorMessage ? [$errorMessage] : null
            );
        }

        return new ResultsContext($stages);
    }

    public function getDeviceId(): int
    {
        return (int) $this->deviceConfig->getDeviceId();
    }

    public function getResellerId(): int
    {
        return (int) $this->deviceConfig->getResellerId();
    }
}
