<?php

namespace Datto\System\Migration\Device\Stage;

use Datto\Asset\RecoveryPoint\DestroySnapshotReason;
use Datto\Cloud\SpeedSync;
use Datto\System\Migration\Context;
use Datto\System\Migration\Stage\AbstractMigrationStage;
use Exception;

/**
 * Delete the remote configBackup from SpeedSync so that it's not orphaned when we move to a new array.
 * @author Peter Geer <pgeer@datto.com>
 */
class DeleteConfigBackupStage extends AbstractMigrationStage
{
    const CONFIG_BACKUP_PATH = 'homePool/home/configBackup';

    /** @var SpeedSync */
    private $speedSync;

    /**
     * @param Context $context
     * @param SpeedSync $speedSync
     */
    public function __construct(Context $context, SpeedSync $speedSync)
    {
        parent::__construct($context);
        $this->speedSync = $speedSync;
    }

    /**
     * @inheritdoc
     */
    public function commit()
    {
        $this->checkForOtherDatasets();
        $this->removeConfigBackup();
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
        // Nothing to do
    }

    /**
     * @inheritdoc
     */
    public function rollback()
    {
        $datasets = $this->speedSync->getJobs();
        if (!isset($datasets[static::CONFIG_BACKUP_PATH])) {
            $this->speedSync->add(static::CONFIG_BACKUP_PATH, SpeedSync::TARGET_CLOUD);
        }
    }

    /**
     * Check for remote datasets other than configBackup
     */
    private function checkForOtherDatasets()
    {
        $datasets = $this->speedSync->getJobs();
        foreach ($datasets as $dataset => $status) {
            if ($dataset !== static::CONFIG_BACKUP_PATH) {
                throw new Exception("Device has existing datasets");
            }
        }
    }

    /**
     * Remove the remote configBackup snapshot points
     */
    private function removeConfigBackup()
    {
        $datasets = $this->speedSync->getJobs();
        if (isset($datasets[static::CONFIG_BACKUP_PATH])) {
            $this->speedSync->remoteDestroy(static::CONFIG_BACKUP_PATH, DestroySnapshotReason::MIGRATION());
        }
    }
}
