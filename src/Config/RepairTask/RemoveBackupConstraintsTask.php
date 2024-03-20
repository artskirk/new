<?php

namespace Datto\Config\RepairTask;

use Datto\Asset\Agent\AgentService;
use Datto\Asset\AssetService;
use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\Feature\FeatureService;
use Datto\Log\DeviceLoggerInterface;

/**
 * Removes backupConstraints key from unsupported devices.
 * The backupConstraints key exists to validate an agent's configuration (i.e volumes)
 * before proceeding with the backup process.
 */
class RemoveBackupConstraintsTask implements ConfigRepairTaskInterface
{
    /** @var AgentService */
    private $agentService;

    /** @var AssetService */
    private $assetService;

    /** @var FeatureService */
    private $featureService;

    /** @var DeviceLoggerInterface */
    private $logger;

    /**
     * @param AgentService $agentService
     * @param AssetService $assetService
     * @param FeatureService $featureService
     * @param DeviceLoggerInterface $logger
     */
    public function __construct(
        AgentService $agentService,
        AssetService $assetService,
        FeatureService $featureService,
        DeviceLoggerInterface $logger
    ) {
        $this->agentService = $agentService;
        $this->assetService = $assetService;
        $this->featureService = $featureService;
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function run(): bool
    {
        $agents = $this->agentService->getAll();

        $agentsUpdated = false;
        foreach ($agents as $agent) {
            if (!$this->featureService->isSupported(FeatureService::FEATURE_AGENT_BACKUP_CONSTRAINTS, null, $agent) &&
                $agent->getBackupConstraints()
            ) {
                $agent->setBackupConstraints(null);
                $this->agentService->save($agent);

                $agentsUpdated = true;
                $this->logger->info('CFG0023 Removed backup constraints for asset.', [
                    'assetUuid' => $agent->getKeyName()
                ]);
            }
        }

        return $agentsUpdated;
    }
}
