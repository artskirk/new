<?php

namespace Datto\System\Migration\Device\Stage;

use Datto\Cloud\SpeedSync;
use Datto\System\Migration\Context;
use Datto\System\Migration\Stage\AbstractMigrationStage;
use Datto\ZFS\ZfsDatasetService;
use Exception;

/**
 * Add configBackup to SpeedSync
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class AddRemoteConfigBackupStage extends AbstractMigrationStage
{
    const CONFIG_BACKUP_ZFS_PATH = 'homePool/home/configBackup';

    /** @var SpeedSync */
    private $speedSync;

    /** @var ZfsDatasetService */
    private $zfsDatasetService;

    /**
     * @param Context $context
     * @param SpeedSync $speedSync
     * @param ZfsDatasetService $zfsDatasetService
     */
    public function __construct(
        Context $context,
        SpeedSync $speedSync,
        ZfsDatasetService $zfsDatasetService
    ) {
        parent::__construct($context);
        $this->speedSync = $speedSync;
        $this->zfsDatasetService = $zfsDatasetService;
    }

    /**
     * @inheritdoc
     */
    public function commit()
    {
        try {
            $configBackupDataset = $this->zfsDatasetService->findDataset(self::CONFIG_BACKUP_ZFS_PATH);
        } catch (Exception $e) {
            $configBackupDataset = null;
        }

        if ($configBackupDataset) {
            $result = $this->speedSync->add(self::CONFIG_BACKUP_ZFS_PATH, SpeedSync::TARGET_CLOUD);
            if ($result !== 0) {
                throw new Exception("SpeedSync add failed for " . self::CONFIG_BACKUP_ZFS_PATH);
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
    }

    /**
     * @inheritdoc
     */
    public function rollback()
    {
    }
}
