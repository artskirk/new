<?php

namespace Datto\Backup\Stages;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Rescue\RescueAgentService;
use Datto\Backup\BackupException;
use Exception;

/**
 * This backup stage take a snapshot of the live dataset.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class TakeSnapshot extends BackupStage
{
    /** @var RescueAgentService */
    private $rescueAgentService;

    public function __construct(RescueAgentService $rescueAgentService)
    {
        $this->rescueAgentService = $rescueAgentService;
    }

    public function commit()
    {
        $startTime = $this->context->getStartTime();
        $asset = $this->context->getAsset();

        if ($asset instanceof Agent && $asset->isRescueAgent()) {
            try {
                $snapshotTime = $this->rescueAgentService->doBackup($asset, $startTime, $this->context->isForced());
            } catch (Exception $e) {
                $snapshotTime = null;
            }
        } else {
            $snapshotTime = $asset->getDataset()->takeSnapshot($startTime, $this->context->getSnapshotTimeout());
        }

        if (!$snapshotTime) {
            $message = 'Take snapshot failed!';
            $this->context->getLogger()->critical('BAK4260 ' . $message);
            throw new BackupException($message);
        }
        $this->context->setSnapshotTime($snapshotTime);
        $this->context->clearAlert('BAK4260');

        $this->context->reloadAsset();
    }

    public function cleanup()
    {
        // Nothing to do
    }
}
