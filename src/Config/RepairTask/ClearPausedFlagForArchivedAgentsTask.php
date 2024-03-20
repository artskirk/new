<?php

namespace Datto\Config\RepairTask;

use Datto\Asset\LocalSettings;
use Datto\Config\AgentConfigFactory;
use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;

/**
 * Fixes configuration of archived agents which have left over paused status
 * flags set as those should be mutually exclusive states.
 */
class ClearPausedFlagForArchivedAgentsTask implements
    ConfigRepairTaskInterface,
    LoggerAwareInterface
{
    use LoggerAwareTrait;

    private AgentConfigFactory $agentConfigFactory;

    public function __construct(AgentConfigFactory $agentConfigFactory)
    {
        $this->agentConfigFactory = $agentConfigFactory;
    }

    /**
     * @inheritdoc
     */
    public function run(): bool
    {
        $assetKeys = $this->agentConfigFactory->getAllKeyNames();
        $assetsUpdated = false;

        foreach ($assetKeys as $assetKey) {
            $config = $this->agentConfigFactory->create($assetKey);

            if ($config->isArchived() && $config->isPaused()) {
                $this->logger->info(
                    'CFG0140 Archived asset has paused flag set, clearing...',
                    ['assetKeyName' => $assetKey]
                );

                $config->clear('backupPause');
                $config->clear('backupPauseUntil');
                $config->clear('backupPauseWhileMetered');

                $assetsUpdated = true;
            }
        }

        return $assetsUpdated;
    }
}
