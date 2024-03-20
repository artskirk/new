<?php

namespace Datto\Backup\Stages;

use Datto\Asset\RecoveryPoint\RecoveryPointHistoryRecord;
use Datto\Asset\TransfersService;
use Datto\Dataset\ZFS_Dataset;
use Datto\Dataset\ZVolDataset;
use Datto\Common\Utility\Filesystem;
use Datto\Utility\ByteUnit;

/**
 * Update the asset key files after a share snapshot.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class UpdateShareAsset extends BackupStage
{
    /** @var Filesystem */
    private $filesystem;

    /** @var TransfersService */
    private $transfersService;

    public function __construct(
        TransfersService $transfersService,
        Filesystem $filesystem
    ) {
        $this->transfersService = $transfersService;
        $this->filesystem = $filesystem;
    }

    /**
     * @inheritdoc
     */
    public function commit()
    {
        $this->updateRecoveryPoints();
        $this->regenerateTransfers();
        $this->updateSpaceUsed();
        $this->updateAgentInfo();
        $this->context->reloadAsset();
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
        // Nothing to do
    }

    private function updateRecoveryPoints()
    {
        $recoveryPointsPath = $this->context->getAgentConfig()->getConfigFilePath('recoveryPoints');
        $recoveryPoints = array_filter(explode(PHP_EOL, $this->filesystem->fileGetContents($recoveryPointsPath)));
        $recoveryPoints[] = $this->context->getSnapshotTime();
        $this->filesystem->filePutContents($recoveryPointsPath, implode(PHP_EOL, array_unique($recoveryPoints)) . PHP_EOL);
    }

    private function regenerateTransfers()
    {
        $assetKey = $this->context->getAsset()->getKeyName();
        $transfersPath = $this->context->getAgentConfig()->getConfigFilePath('transfers');
        $this->filesystem->unlinkIfExists($transfersPath);
        $this->transfersService->generateMissing($assetKey);
    }

    private function updateSpaceUsed()
    {
        /** @var ZVolDataset|ZFS_Dataset $dataset */
        $dataset = $this->context->getAsset()->getDataset();
        $snapshotEpoch = $this->context->getSnapshotTime();
        $snapshotSize = $dataset->getSnapshotSize($snapshotEpoch);
        $totalSize = $dataset->getUsedSize();

        $recoveryPointHistory = new RecoveryPointHistoryRecord();
        $this->context->getAgentConfig()->loadRecord($recoveryPointHistory);
        $recoveryPointHistory->addTransfer($snapshotEpoch, $snapshotSize);
        $recoveryPointHistory->addTotalUsed($snapshotEpoch, $totalSize);
        $this->context->getAgentConfig()->saveRecord($recoveryPointHistory);
    }

    private function updateAgentInfo()
    {
        /** @var ZVolDataset|ZFS_Dataset $dataset */
        $dataset = $this->context->getAsset()->getDataset();

        $agentInfo = unserialize($this->context->getAgentConfig()->get("agentInfo"), ['allowed_classes' => false]);

        $agentInfo['localUsed'] = ByteUnit::BYTE()->toGiB($dataset->getUsedSize());

        $usedBySnapshots = 0;
        $snapshots = $dataset->listSnapshots();
        foreach ($snapshots as $snapshot) {
            $usedSize = (int)$dataset->getSnapshotSize($snapshot);
            $usedBySnapshots += ($usedSize > 0) ? $usedSize : 0;
        }
        $agentInfo['usedBySnaps'] = ByteUnit::BYTE()->toGib($usedBySnapshots);

        $this->context->getAgentConfig()->set('agentInfo', serialize($agentInfo));
    }
}
