<?php

namespace Datto\Dataset;

use Datto\Common\Utility\Filesystem;
use Datto\Core\Storage\SirisStorage;
use Datto\Core\Storage\StorageCreationContext;
use Datto\Core\Storage\StorageInterface;
use Datto\Core\Storage\StorageType;
use Datto\System\MountManager;
use Datto\ZFS\ZfsService;
use Datto\Utility\Process\ProcessCleanup;
use Exception;
use InvalidArgumentException;
use Throwable;

/**
 * Dataset class dedicated to manipulating ZFS datasets.
 *
 * @deprecated Use StorageInterface instead
 *
 * @author Dan Fuhry <dfuhry@dattobackup.com>
 */
class ZFS_Dataset extends Dataset
{
    const LIVE_DATASET_BASE_PATH = '/home/agents';

    private ProcessCleanup $processCleanup;

    public function __construct(
        string $zfsPath,
        Filesystem $filesystem,
        ZfsService $zfsService,
        MountManager $mountManager,
        ProcessCleanup $processCleanup,
        StorageInterface $storage,
        SirisStorage $sirisStorage
    ) {
        parent::__construct($zfsPath, $filesystem, $zfsService, $mountManager, $storage, $sirisStorage);

        $this->processCleanup = $processCleanup;
    }

    /**
     * Create a ZFS filesystem
     *
     * @deprecated Use StorageInterface instead
     * @param string|null $size
     * @param string|null $format
     * @return bool  Whether or not the filesystem was created
     */
    public function create($size = null, $format = null)
    {
        if ($this->zfsPath[0] === '/' || $this->zfsPath[strlen($this->zfsPath)-1] === '/') {
            throw new InvalidArgumentException('zfs paths must not have leading or trailing slashes: ' . $this->zfsPath);
        }

        return $this->createFilesystem();
    }

    /**
     * Destroys the given local snapshot.
     *
     * @deprecated Use StorageInterface instead
     * @param int $snapshot Snapshot that is to be destroyed.
     */
    public function destroySnapshot($snapshot)
    {
        $snapshotPath = $this->zfsPath . '@' . $snapshot;

        $this->processCleanup->logProcessesUsingDirectory($this->getMountPoint(), $this->logger);
        $this->storage->destroySnapshot($snapshotPath);
    }

    /**
     * Destroy the dataset
     *
     * @deprecated Use StorageInterface instead
     */
    public function destroy()
    {
        $this->destroyIfUnmounted(true);
    }

    /**
     * Mount the dataset to the given path
     *
     * @deprecated Use StorageInterface instead
     * @param string $mountPath  Not used for ZFS_Dataset
     * @return bool  Whether or not the dataset was properly mounted
     */
    public function mount($mountPath = null)
    {
        $storageInfo = $this->storage->getStorageInfo($this->zfsPath);
        if (!$storageInfo->isMounted()) {
            $this->storage->mountStorage($this->zfsPath);
            $storageInfo = $this->storage->getStorageInfo($this->zfsPath);
        }
        return $storageInfo->isMounted();
    }

    public function getLiveDatasetBasePath()
    {
        return self::LIVE_DATASET_BASE_PATH;
    }

    /**
     * Roll back the dataset to the given point. This is destructive - it will discard all data written since the snapshot.
     *
     * @deprecated Use StorageInterface instead
     * @param string $point Snapshot name (just the part after the @)
     */
    public function rollbackTo($point)
    {
        try {
            $snapshotPath = $this->zfsPath . '@' . $point;
            $this->storage->rollbackToSnapshotDestructive($snapshotPath, true);
        } catch (Throwable $throwable) {
            throw new Exception(
                "Failed to perform rollback: " . $throwable->getMessage(),
                $throwable->getCode(),
                $throwable
            );
        }
    }

    /**
     * @deprecated Use StorageInterface instead
     */
    public function getMountPoint()
    {
        try {
            return $this->storage->getStorageInfo($this->zfsPath)->getFilePath();
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Destroy dataset if the mountpoint is not set.
     *
     * @deprecated Use StorageInterface instead
     * @param bool $recursive
     */
    private function destroyIfUnmounted($recursive)
    {
        if ($this->mountManager->isMounted($this->getMountPoint())) {
            throw new Exception('Cannot destroy dataset without unmounting it first.');
        }

        if ($this->storage->storageExists($this->zfsPath)) {
            $this->storage->destroyStorage($this->zfsPath, $recursive);
        }
    }

    /**
     * Creates a filesystem
     *
     * @deprecated Use StorageInterface instead
     * @return bool  Whether or not the fs was created
     */
    private function createFilesystem()
    {
        if ($this->storage->storageExists($this->zfsPath)) {
            return true;
        }

        try {
            $nameAndParentId = $this->sirisStorage->getNameAndParentIdFromStorageId($this->zfsPath);
            $shortName = $nameAndParentId['name'];
            $parentId = $nameAndParentId['parentId'];

            $storageCreationContext = new StorageCreationContext(
                $shortName,
                $parentId,
                StorageType::STORAGE_TYPE_FILE,
                StorageCreationContext::MAX_SIZE_DEFAULT,
                true
            );
            $this->storage->createStorage($storageCreationContext);
        } catch (Throwable $throwable) {
            $this->logger->error(
                'ZDS1000 There was an issue creating the filesystem',
                ['storagePath' => $this->zfsPath, 'exception' => $throwable]
            );
            throw new Exception(
                'There was an issue creating the filesystem: ' . $throwable->getMessage(),
                $throwable->getCode(),
                $throwable
            );
        }

        return $this->storage->storageExists($this->zfsPath);
    }
}
