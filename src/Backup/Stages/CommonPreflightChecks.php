<?php

namespace Datto\Backup\Stages;

use Datto\Backup\BackupException;
use Datto\Core\Storage\SirisStorage;
use Datto\Core\Storage\StorageInterface;
use Datto\Core\Storage\StorageType;
use Datto\Utility\ByteUnit;

/**
 * This class contains preflight checks that are common to agents and shares.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class CommonPreflightChecks extends BackupStage
{
    const FREE_SPACE_ERROR_THRESHOLD_IN_GIB = 30;
    const FREE_SPACE_WARNING_THRESHOLD_IN_GIB = 40;

    private StorageInterface $storage;
    private SirisStorage $sirisStorage;

    public function __construct(
        StorageInterface $storage,
        SirisStorage $sirisStorage
    ) {
        $this->storage = $storage;
        $this->sirisStorage = $sirisStorage;
    }

    /**
     * @inheritdoc
     */
    public function commit()
    {
        $this->assertFreeSpace();
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
    }

    /**
     * Verify that there is enough free space to take a backup
     */
    private function assertFreeSpace()
    {
        $errorThreshold = ByteUnit::GIB()->toByte(self::FREE_SPACE_ERROR_THRESHOLD_IN_GIB);
        $warningThreshold = ByteUnit::GIB()->toByte(self::FREE_SPACE_WARNING_THRESHOLD_IN_GIB);

        $storageId = $this->sirisStorage->getStorageId(SirisStorage::HOME_STORAGE, StorageType::STORAGE_TYPE_FILE);
        $freeSpace = $this->storage->getStorageInfo($storageId)->getFreeSpaceInBytes();

        if ($freeSpace < $errorThreshold) {
            $message = 'Backup skipped due to not enough free space.';
            $this->context->getLogger()->critical('BAK0615 ' . $message);
            throw new BackupException($message);
        } elseif ($freeSpace < $warningThreshold) {
            $message = sprintf(
                'Disk is nearly full (less than %dGB of free space remaining), backup may fail',
                self::FREE_SPACE_WARNING_THRESHOLD_IN_GIB
            );
            $this->context->getLogger()->warning('BAK1615 ' . $message);
        } else {
            $this->context->clearAlert('BAK1615');
        }
        $this->context->clearAlert('BAK0615');
    }
}
