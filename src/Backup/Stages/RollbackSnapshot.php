<?php

namespace Datto\Backup\Stages;

use Datto\Asset\AssetType;
use Datto\Core\Storage\SirisStorage;
use Datto\Core\Storage\StorageInterface;
use Datto\Core\Storage\StorageType;
use Throwable;

/**
 * This backup stage rolls back the live dataset to the most recent snapshot, if one exists.
 * This will remove any changes that may have occurred to the live dataset between backups.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class RollbackSnapshot extends BackupStage
{
    private StorageInterface $storage;
    private SirisStorage $sirisStorage;

    public function __construct(StorageInterface $storage, SirisStorage $sirisStorage)
    {
        $this->storage = $storage;
        $this->sirisStorage = $sirisStorage;
    }

    /**
     * @inheritdoc
     */
    public function commit()
    {
        if (!$this->context->isFullBackup()) {
            $this->rollbackToMostRecentSnapshot();
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
     * This will clean up any data that may have been transferred before an exception was thrown.
     */
    public function rollback()
    {
        $this->context->getLogger()->debug('RLL0205 SnapRollback Started.. mode: rollback');
        $this->rollbackToMostRecentSnapshot();
    }

    /**
     * Rollback to the most recent snapshot, if one exists.
     */
    private function rollbackToMostRecentSnapshot()
    {
        try {
            $asset = $this->context->getAsset();
            $assetKeyName = $asset->getKeyName();
            $isAgent = $asset->isType(AssetType::AGENT);

            $storageType = $isAgent ? StorageType::STORAGE_TYPE_FILE : StorageType::STORAGE_TYPE_BLOCK;
            $storageId = $this->sirisStorage->getStorageId($assetKeyName, $storageType);

            $this->storage->rollbackToLatestSnapshot($storageId);
        } catch (Throwable $ex) {
            $this->context->getLogger()->warning('BAK0103 Failed to rollback live dataset before backup - continuing', ['exception' => $ex]);
        }
    }
}
