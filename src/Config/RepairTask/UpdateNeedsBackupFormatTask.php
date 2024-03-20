<?php

namespace Datto\Config\RepairTask;

use Datto\App\Controller\Api\V1\Device\Asset\Agent\DirectToCloud\Backup;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\AssetService;
use Datto\Backup\BackupRequest;
use Datto\Config\AgentConfigFactory;
use Datto\Config\AgentStateFactory;
use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\Feature\FeatureService;
use Psr\Log\LoggerInterface;

/**
 * If any needsBackup flags exist format is no longer int it is the format found in
 * BackupRequest.php so we need to update those existing flags.
 */
class UpdateNeedsBackupFormatTask implements ConfigRepairTaskInterface
{
    /** @var AgentService */
    private $agentService;

    /** @var AgentConfigFactory */
    private $agentConfigFactory;

    /** @var AssetService */
    private $assetService;

    /** @var FeatureService */
    private $featureService;

    /** @var LoggerInterface */
    private $logger;

    /**
     * @param AgentService $agentService
     * @param AssetService $assetService
     * @param FeatureService $featureService
     * @param LoggerInterface $logger
     */
    public function __construct(
        AgentService $agentService,
        AgentConfigFactory $agentConfigFactory,
        AssetService $assetService,
        FeatureService $featureService,
        LoggerInterface $logger
    ) {
        $this->agentService = $agentService;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->assetService = $assetService;
        $this->featureService = $featureService;
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function run(): bool
    {
        $assets = $this->assetService->getAll();

        $assetsUpdated = false;
        foreach ($assets as $asset) {
            $config = $this->agentConfigFactory->create($asset->getKeyName());
            if ($config->has(BackupRequest::NEEDS_BACKUP_FLAG)) {
                $needsBackup = json_decode($config->get(BackupRequest::NEEDS_BACKUP_FLAG), true);

                // If the file is already in the correct format, ignore it
                if (isset($needsBackup['queuedTime']) && isset($needsBackup['metadata'])) {
                    continue;
                }

                $this->logger->info('CFG0022 Asset has needsBackup flag, updating format...', [
                    'assetKeyName' => $asset->getKeyName()
                ]);

                $backupRequest = new BackupRequest($needsBackup, []);
                $config->saveRecord($backupRequest);
                $assetsUpdated = true;
            }
        }

        return $assetsUpdated;
    }
}
