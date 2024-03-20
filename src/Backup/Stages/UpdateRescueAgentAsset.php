<?php

namespace Datto\Backup\Stages;

use Datto\Asset\Agent\Rescue\RescueAgentService;
use Datto\Utility\ByteUnit;
use Datto\ZFS\ZfsService;

/**
 * Update rescue agent metadata after snapshot.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class UpdateRescueAgentAsset extends BackupStage
{
    /** @var RescueAgentService */
    private $rescueAgentService;

    /** @var ZfsService */
    private $zfsService;

    public function __construct(
        RescueAgentService $rescueAgentService,
        ZfsService $zfsService
    ) {
        $this->rescueAgentService = $rescueAgentService;
        $this->zfsService = $zfsService;
    }

    /**
     * @inheritdoc
     */
    public function commit()
    {
        $this->updateRecoveryPoints();
        $this->updateUsedSpace();
        $this->context->reloadAsset();
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
        // Nothing to do here.
    }

    private function updateRecoveryPoints()
    {
        $asset = $this->context->getAsset();
        $snapshotEpochs = $this->zfsService->getSnapshots($asset->getDataset()->getZfsPath());
        $filteredSnapshots = $this->rescueAgentService->filterNonRescueAgentSnapshots($asset->getKeyName(), $snapshotEpochs);

        $this->context->getAgentConfig()->set('recoveryPoints', implode(PHP_EOL, $filteredSnapshots) . PHP_EOL);
    }

    private function updateUsedSpace()
    {
        $spaceUsed = $this->context->getAsset()->getDataset()->getUsedSize();
        $agentInfo = unserialize($this->context->getAgentConfig()->get('agentInfo'), ['allowed_classes' => false]);
        $agentInfo['localUsed'] = round(ByteUnit::BYTE()->toGiB($spaceUsed), 2);
        $this->context->getAgentConfig()->set('agentInfo', serialize($agentInfo));
    }
}
