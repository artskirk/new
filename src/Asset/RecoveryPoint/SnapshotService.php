<?php

namespace Datto\Asset\RecoveryPoint;

use Datto\Asset\AssetService;
use Datto\Cloud\SpeedSync;
use Datto\Config\AgentConfigFactory;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * @author Justin Giacobbi <justin@datto.com>
 */
abstract class SnapshotService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected AgentConfigFactory $agentConfigFactory;
    protected AssetService $assetService;
    protected SpeedSync $speedsync;

    public function __construct(
        AgentConfigFactory $agentConfigFactory,
        AssetService $assetService,
        SpeedSync $speedsync
    ) {
        $this->agentConfigFactory = $agentConfigFactory;
        $this->assetService = $assetService;
        $this->speedsync = $speedsync;
    }

    /**
     * Identify local deleted snapshots with no corresponding offsite snapshot
     * and delete the associated metadata
     * @param string $assetKey
     */
    protected function reconcileDeletedPoints(string $assetKey): void
    {
        try {
            $agentConfig = $this->agentConfigFactory->create($assetKey);
            $zfsPath = $agentConfig->getZfsBase() . '/' . $assetKey;
            $offsiteEpochs = $this->speedsync->getOffsitePoints($zfsPath);

            $asset = $this->assetService->get($assetKey);
            $recoveryPoints = $asset->getLocal()->getRecoveryPoints();
            $localEpochs = array_keys($recoveryPoints->getBoth());

            $orphans = array_diff($localEpochs, $offsiteEpochs);
            foreach ($orphans as $epoch) {
                if ($recoveryPoints->get($epoch)->isDeleted()) {
                    $recoveryPoints->remove($epoch);
                }
            }

            $this->assetService->save($asset);
        } catch (Throwable $e) {
            $this->logger->setAssetContext($assetKey);
            $this->logger->warning('SPS0001 Failed to update recovery points', ['exception' => $e]);
        }
    }
}
