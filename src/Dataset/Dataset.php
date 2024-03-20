<?php

namespace Datto\Dataset;

use Datto\Asset\Asset;
use Datto\System\MountManager;
use Datto\Common\Utility\Filesystem;
use Datto\Core\Storage\SirisStorage;
use Datto\Core\Storage\SnapshotCreationContext;
use Datto\Core\Storage\StorageInterface;
use Datto\Core\Storage\StorageType;
use Datto\Log\LoggerAwareTrait;
use Datto\ZFS\ZfsService;
use Exception;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Abstract Dataset class to define all properties and methods that MUST be used.
 *
 * @deprecated Use StorageInterface instead
 */
abstract class Dataset implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const SNAPSHOT_NAME_FORMAT = '%s@%d';
    const UNKNOWN_USED_SIZE = '-1';
    const HOUR_TIMEOUT = 3600;

    protected $zfsPath;

    /**
     * ZFS attribute cache. Used by getAttribute()/setAttribute()
     * @var array
     */
    protected array $attributeCache = [];

    protected Filesystem $filesystem;
    protected MountManager $mountManager;
    protected StorageInterface $storage;
    protected SirisStorage $sirisStorage;

    private ZfsService $zfsService;

    public function __construct(
        string $zfsPath,
        Filesystem $filesystem,
        ZfsService $zfsService,
        MountManager $mountManager,
        StorageInterface $storage,
        SirisStorage $sirisStorage
    ) {
        $this->zfsPath = $zfsPath;
        $this->filesystem = $filesystem;
        $this->zfsService = $zfsService;
        $this->mountManager = $mountManager;
        $this->storage = $storage;
        $this->sirisStorage = $sirisStorage;
    }

    abstract public function create($size, $format);
    abstract public function destroy();
    abstract public function mount($path = null);
    abstract public function getMountPoint();

    /**
     * Get the base path for the live dataset.
     *
     * @return string The live dataset base path for the dataset
     */
    abstract public function getLiveDatasetBasePath();

    /**
     * Determine if the given dataset exists.
     *
     * @deprecated Use StorageInterface instead
     * @return bool
     */
    public function exists()
    {
        return $this->storage->storageExists($this->zfsPath);
    }

    /**
     * Take a snapshot of the dataset named after the current UNIX time, and return this name.
     *
     * @deprecated Use StorageInterface instead
     * @param int $timestamp The timestamp to use in the snapshot's name
     * @param int $timeout Timeout for the zfs call, default 60 seconds
     * @return int The timestamp used in the snapshot's name
     */
    public function takeSnapshot($timestamp, $timeout = 3600)
    {
        $hostname = basename($this->zfsPath);
        $snapshotName = sprintf(self::SNAPSHOT_NAME_FORMAT, $this->zfsPath, $timestamp);

        $this->logger->setAssetContext($hostname);
        $this->logger->info('ZFS4002 Running ZFS Snapshot', ['snapshot' => $snapshotName]);

        try {
            $snapshotCreationContext = new SnapshotCreationContext($timestamp, $timeout);
            $this->storage->takeSnapshot($this->zfsPath, $snapshotCreationContext);
        } catch (Throwable $throwable) {
            return null;
        }

        try {
            $this->zfsService->setCachedUsedSize($this->zfsPath, (int)$timestamp, 0);
        } catch (Throwable $throwable) {
            $this->logger->warning('ZFS4003 Failed to update zfs cache', ['exception' => $throwable]);
        }

        return $timestamp;
    }

    /**
     * Delete all files currently stored in this dataset, but leave the dataset's existing snapshot chain intact
     * @deprecated
     */
    public function delete()
    {
        $liveDatasetPath = $this->getMountPoint();
        $resemblesRealMountPoint = strpos($liveDatasetPath, Asset::BASE_MOUNT_PATH) === 0
            || strpos($liveDatasetPath, ZFS_Dataset::LIVE_DATASET_BASE_PATH) === 0;

        if (!$resemblesRealMountPoint) {
            throw new Exception("Invalid live dataset mount point: " . $liveDatasetPath);
        }

        $files = $this->filesystem->glob($liveDatasetPath . '/*');
        $success = true;

        foreach ($files as $file) {
            try {
                $this->filesystem->unlinkDir($file);
            } catch (Throwable $throwable) {
                $success = false;
            }
        }

        if (!$success) {
            throw new Exception('Cannot delete active dataset for ' . $this->zfsPath);
        }
    }

    /**
     * Returns a list (array) of snapshots (epoch times)
     *
     * @deprecated Use StorageInterface instead
     * @return array  An array of snapshots (epoch times)
     */
    public function listSnapshots(): array
    {
        try {
            $snapshots = $this->storage->listSnapshotNames($this->zfsPath, true);
            $snapshots = array_filter($snapshots, function ($snapshot) {
                return is_numeric($snapshot);
            });

            return $snapshots;
        } catch (Throwable $throwable) {
            return [];
        }
    }

    /**
     * Returns the used size of the dataset
     *
     * @deprecated Use StorageInterface instead
     * @return string
     */
    public function getUsedSize($snapshot = '')
    {
        $isSnapshot = !empty($snapshot);
        $suffix = $isSnapshot ? '@' . $snapshot : '';
        $zfsPath = $this->zfsPath . $suffix;

        if (isset($this->attributeCache[$zfsPath]['used'])) {
            return $this->attributeCache[$zfsPath]['used'];
        }

        try {
            if ($isSnapshot) {
                $snapshotInfo = $this->storage->getSnapshotInfo($zfsPath);
                $size = $snapshotInfo->getUsedSizeInBytes();
            } else {
                $storageInfo = $this->storage->getStorageInfo($zfsPath);
                $size = $storageInfo->getAllocatedSizeInBytes();
            }
            $this->attributeCache[$zfsPath]['used'] = $size;
            return $size;
        } catch (Throwable $throwable) {
            return self::UNKNOWN_USED_SIZE;
        }
    }

    /**
     * Returns the used size of the dataset's snapshots
     *
     * @deprecated Use StorageInterface instead
     * @return string
     */
    public function getUsedBySnapshotsSize()
    {
        try {
            return $this->getAttribute('usedbysnapshots');
        } catch (Throwable $throwable) {
            return self::UNKNOWN_USED_SIZE;
        }
    }

    /**
     * Gets the current, ZFS listed size of a snapshot (formatted as 9.21M, 155K, etc)
     *
     * @deprecated Use StorageInterface instead
     * @param $snapshot string  The snapshot (epoch time) for which size is desired
     * @return string  Size of the snapshot (formatted as 9.21M, 155K, etc)
     */
    public function getSnapshotSize($snapshot)
    {
        return $this->getUsedSize($snapshot);
    }
    /** Accessors */
    /**
     * Returns the ZFS path of the loaded dataset
     *
     * @return string
     */
    public function getZfsPath()
    {
        return $this->zfsPath;
    }

    /**
     * Verifies that the dataset has been properly mounted
     *
     * @return bool Whether or not the zfs dataset or zvol partition is mounted
     */
    public function isMounted(): bool
    {
        return $this->mountManager->isMounted($this->getMountPoint());
    }

    public function unmount()
    {
        try {
            $mountPoint = $this->getMountPoint();
            if ($this->mountManager->isMounted($mountPoint)) {
                $this->mountManager->unmount($mountPoint);
            }
            $this->filesystem->unlinkDir($mountPoint);
        } catch (Throwable $throwable) {
            throw new Exception(
                'Could not unmount ' . $mountPoint . ' ~ ' . $throwable->getMessage(),
                $throwable->getCode(),
                $throwable
            );
        }
    }

    /**
     * Get a filesystem attribute.
     *
     * @deprecated Use StorageInterface instead
     * @param string $attribute
     * @return string|false
     */
    public function getAttribute($attribute)
    {
        if (isset($this->attributeCache[$this->zfsPath][$attribute])) {
            return $this->attributeCache[$this->zfsPath][$attribute];
        }

        try {
            if ($attribute === 'datto:uuid') {
                $properties = $this->storage->getStorageProperties($this->zfsPath, ['datto:uuid']);
                $attributeValue = $properties['datto:uuid'] ?? '';
            } else {
                $storageInfo = $this->storage->getStorageInfo($this->zfsPath);

                switch ($attribute) {
                    case 'usedbysnapshots':
                        $attributeValue = $storageInfo->getAllocatedSizeBySnapshotsInBytes();
                        break;
                    case 'mounted':
                        $attributeValue = $storageInfo->isMounted();
                        break;
                    case 'mountpoint':
                        if ($storageInfo->getType() === StorageType::STORAGE_TYPE_FILE) {
                            $attributeValue = $storageInfo->getFilePath();
                        } else {
                            $attributeValue = '-';
                        }
                        break;
                    default:
                        $attributeValue = false;
                }
            }

            // A value of "-" means the attribute is null
            if ($attributeValue === '-') {
                $attributeValue = false;
            }

            if (!isset($this->attributeCache[$this->zfsPath])) {
                $this->attributeCache[$this->zfsPath] = [];
            }
            $this->attributeCache[$this->zfsPath][$attribute] = $attributeValue;

            return $attributeValue;
        } catch (Throwable $throwable) {
            throw new Exception(
                "Could not get attribute $attribute from filesystem {$this->zfsPath}",
                $throwable->getCode(),
                $throwable
            );
        }
    }


    /**
     * Set a filesystem attribute.  Note that user-defined ZFS property names must contain a colon, e.g. "datto:uuid".
     *
     * @deprecated Use StorageInterface instead
     * @param string $attribute
     * @param string $value
     */
    public function setAttribute($attribute, $value)
    {
        try {
            $this->storage->setStorageProperties($this->zfsPath, [$attribute => $value]);
        } catch (Throwable $throwable) {
            throw new Exception(
                "Could not set attribute $attribute to $value on filesystem {$this->zfsPath}",
                $throwable->getCode(),
                $throwable
            );
        }

        if (!isset($this->attributeCache[$this->zfsPath])) {
            $this->attributeCache[$this->zfsPath] = [];
        }

        $this->attributeCache[$this->zfsPath][$attribute] = $value;
    }
}
