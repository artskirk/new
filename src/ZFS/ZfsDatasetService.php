<?php

namespace Datto\ZFS;

use Datto\AppKernel;
use Datto\Common\Resource\PosixHelper;
use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Resource\Sleep;
use Datto\Utility\File\Lock;
use Datto\Utility\File\LockFactory;
use Datto\Log\LoggerFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Core\Storage\SirisStorage;
use Datto\Core\Storage\StorageCreationContext;
use Datto\Core\Storage\StorageInfo;
use Datto\Core\Storage\StorageInterface;
use Datto\Core\Storage\StorageType;
use Datto\Core\Storage\Zfs\ZfsStorage;
use Datto\Utility\Process\ProcessCleanup;
use Datto\Log\DeviceLoggerInterface;
use Datto\Utility\File\Lsof;
use Exception;
use Throwable;

/**
 * Service for interfacing with ZFS datasets.
 *
 * @deprecated Use StorageInterface instead
 *
 * @author Stephen Allan <sallan@datto.com>
 * @author Andrew Cope <acope@datto.com>
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class ZfsDatasetService
{
    const HOMEPOOL_DATASET = 'homePool';
    const HOMEPOOL_DATASET_PATH = '/homePool';
    const HOMEPOOL_HOME_DATASET = 'homePool/home';
    const HOMEPOOL_HOME_DATASET_PATH = '/home';
    const HOMEPOOL_HOME_AGENTS_DATASET = 'homePool/home/agents';
    const HOMEPOOL_HOME_AGENTS_DATASET_PATH = '/home/agents';
    const ZVOL_DATASET_BASE_PATH = '/dev/zvol';

    private Filesystem $filesystem;
    private DeviceLoggerInterface $logger;
    private Lock $lock;
    private ZfsDatasetFactory $zfsDatasetFactory;
    private StorageInterface $storage;
    private SirisStorage $sirisStorage;

    public function __construct(
        Filesystem $filesystem = null,
        DeviceLoggerInterface $logger = null,
        LockFactory $lockFactory = null,
        ZfsDatasetFactory $zfsDatasetFactory = null,
        ProcessCleanup $processCleanup = null,
        StorageInterface $storage = null,
        SirisStorage $sirisStorage = null
    ) {
        $this->logger = $logger ?? LoggerFactory::getDeviceLogger();
        $lockFactory = $lockFactory ?? new LockFactory();
        $this->lock = $lockFactory->getProcessScopedLock("/dev/shm/" . basename(__FILE__));

        $this->filesystem = $filesystem ?? AppKernel::getBootedInstance()->getContainer()->get(Filesystem::class);
        $this->storage = $storage ?? AppKernel::getBootedInstance()->getContainer()->get(ZfsStorage::class);
        $this->sirisStorage = $sirisStorage ?? AppKernel::getBootedInstance()->getContainer()->get(SirisStorage::class);

        $processCleanup = $processCleanup ?? new ProcessCleanup(
            // @codeCoverageIgnoreStart
            new Lsof(),
            new PosixHelper(new ProcessFactory()),
            new Sleep(),
            $this->filesystem
            // @codeCoverageIgnoreEnd
        );

        $this->zfsDatasetFactory = $zfsDatasetFactory ?? new ZfsDatasetFactory(
            // @codeCoverageIgnoreStart
            $processCleanup,
            $this->storage,
            $this->sirisStorage
            // @codeCoverageIgnoreEnd
        );
        $this->zfsDatasetFactory->setLogger($this->logger);
    }

    /**
     * Returns true if all ZFS datasets are mounted,
     * or false if at least one dataset is unmounted
     *
     * @deprecated Use StorageInterface instead
     *
     * @return bool
     */
    public function areAllDatasetsMounted()
    {
        $datasets = $this->getMountableDatasets();

        foreach ($datasets as $dataset) {
            if (!$dataset->isMounted()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Try to mount all mountable datasets.
     * Continue trying to mount even if some fail.
     *
     * @deprecated Use StorageInterface instead
     */
    public function mountDatasets()
    {
        $datasets = $this->getMountableDatasets();

        foreach ($datasets as $dataset) {
            try {
                $dataset->mount();
            } catch (ZfsDatasetException $e) {
                $this->logger->error('ZDS0011 Could not mount dataset', ['dataset' => $dataset->getName(), 'exception' => $e]);
            }
        }
    }

    /**
     * Repairs the datasets by unmounting all datasets, then cleaning and mounting each dataset.
     *
     * @deprecated Use StorageInterface instead
     */
    public function repair()
    {
        if (!$this->lock->exclusive(false)) {
            throw new Exception("Failed to acquire lock, zfs dataset repair already in progress");
        }

        try {
            $this->unmountDatasets();
            $this->cleanAndRemountDatasets();
            $this->logger->info('ZDS0001 Repair of all mountable ZFS datasets was successful');
        } catch (ZfsDatasetException $exception) {
            $this->logger->info('ZDS0000 Repair of all mountable ZFS datasets was not successful', ['exception' => $exception]);
            throw $exception;
        } finally {
            $this->lock->unlock();
        }
    }

    /**
     * Determine if the agent's dataset is mounted
     *
     * @deprecated Use StorageInterface instead
     */
    public function isAgentDatasetMounted(string $agentKey): bool
    {
        try {
            $dataset = $this->zfsDatasetFactory->makePartialDataset(
                self::HOMEPOOL_HOME_AGENTS_DATASET . '/' . $agentKey,
                self::HOMEPOOL_HOME_AGENTS_DATASET_PATH . '/' . $agentKey
            );

            return $dataset->isMounted();
        } catch (ZfsDatasetException $e) {
            $this->logger->error('ZDS0012 Couldn\'t get mount point information', ['exception' => $e]);
            return false;
        }
    }

    /**
     * Determine if all homePool base datasets and optional agent dataset are mounted.
     *
     * @deprecated Use StorageInterface instead
     *
     * @return bool True if all homePool base and base agent datasets are mounted
     */
    public function areBaseAgentDatasetsMounted(): bool
    {
        $datasets = $this->getDefaultDatasets();

        $datasets[] = $this->zfsDatasetFactory->makePartialDataset(
            self::HOMEPOOL_HOME_AGENTS_DATASET,
            self::HOMEPOOL_HOME_AGENTS_DATASET_PATH
        );

        foreach ($datasets as $dataset) {
            try {
                if (!$dataset->isMounted()) {
                    return false;
                }
            } catch (ZfsDatasetException $e) {
                $this->logger->error('ZDS0010 Couldn\'t get mount point information', ['exception' => $e]);
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if all homePool base datasets are mounted.
     *
     * @deprecated Use StorageInterface instead
     *
     * @return bool True if all homePool base datasets are mounted
     */
    public function areBaseDatasetsMounted(): bool
    {
        $datasets = $this->getDefaultDatasets();

        foreach ($datasets as $dataset) {
            if (!$dataset->isMounted()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get all ZFS datasets
     *
     * @deprecated Use StorageInterface instead
     *
     * @return ZfsDataset[] List of all datasets.
     */
    public function getAllDatasets(): array
    {
        $storageInfos = $this->storage->getStorageInfos('', true);
        return $this->convertStorageInfosToZfsDatasets($storageInfos);
    }

    /**
     * Get a ZFS dataset by its name, throw exception if not found
     *
     * @deprecated Use StorageInterface instead
     *
     * @param string $name of the ZFS dataset
     * @return ZfsDataset
     */
    public function getDataset(string $name): ZfsDataset
    {
        try {
            $storageInfo = $this->storage->getStorageInfo($name);
            $dataset = $this->convertStorageInfoToZfsDataset($storageInfo);
        } catch (Throwable $throwable) {
            throw new Exception("Could not get dataset with name '$name'");
        }

        return $dataset;
    }

    /**
     * Find a ZFS dataset by its name, return null if not found
     *
     * @deprecated Use StorageInterface instead
     *
     * @param string $name
     * @return ZfsDataset|null
     */
    public function findDataset(string $name)
    {
        if ($name === '') {
            return null;
        }

        try {
            if (!$this->storage->storageExists($name)) {
                return null;
            }

            $storageInfo = $this->storage->getStorageInfo($name);
            $dataset = $this->convertStorageInfoToZfsDataset($storageInfo);
        } catch (Throwable $throwable) {
            return null;
        }

        return $dataset;
    }

    /**
     * Check if a zfs dataset exists.
     *
     * @deprecated Use StorageInterface instead
     *
     * @param string $name
     * @return bool
     */
    public function exists(string $name)
    {
        return $this->storage->storageExists($name);
    }

    /**
     * @deprecated Use StorageInterface instead
     *
     * @param string $name
     * @param bool $createParents
     * @param array $properties
     * @return ZfsDataset
     */
    public function createDataset(string $name, bool $createParents = false, array $properties = [])
    {
        $nameAndParentId = $this->sirisStorage->getNameAndParentIdFromStorageId($name);
        $shortName = $nameAndParentId['name'];
        $parentId = $nameAndParentId['parentId'];

        $storageCreationContext = new StorageCreationContext(
            $shortName,
            $parentId,
            StorageType::STORAGE_TYPE_FILE,
            StorageCreationContext::MAX_SIZE_DEFAULT,
            $createParents,
            $properties
        );
        $this->storage->createStorage($storageCreationContext);

        return $this->getDataset($name);
    }

    /**
     * Destroy a dataset.
     *
     * @deprecated Use StorageInterface instead
     *
     * @param ZfsDataset $dataset
     * @param bool $recursive
     */
    public function destroyDataset(ZfsDataset $dataset, bool $recursive = false)
    {
        $name = $dataset->getName();
        if (strpos($name, '@') !== false) {
            $this->storage->destroySnapshot($name);
        } else {
            $this->storage->destroyStorage($name, $recursive);
        }
    }

    /**
     * @deprecated Use StorageInterface instead
     *
     * @param StorageInfo[] $storageInfos
     * @return ZfsDataset[]
     */
    private function convertStorageInfosToZfsDatasets(array $storageInfos): array
    {
        $datasets = [];
        foreach ($storageInfos as $storageInfo) {
            $datasets[] = $this->convertStorageInfoToZfsDataset($storageInfo);
        }
        return $datasets;
    }

    /**
     * @deprecated Use StorageInterface instead
     *
     * @param StorageInfo $storageInfo
     * @return ZfsDataset
     */
    private function convertStorageInfoToZfsDataset(StorageInfo $storageInfo): ZfsDataset
    {
        if ($storageInfo->getType() === StorageType::STORAGE_TYPE_FILE && !empty($storageInfo->getFilePath())) {
            $mountpoint = $storageInfo->getFilePath();
        } else {
            $mountpoint = ZfsDataset::MOUNTPOINT_DASH;
        }

        if (!empty($storageInfo->getParent())) {
            $origin = $storageInfo->getParent();
        } else {
            $origin = StorageInfo::STORAGE_PROPERTY_NOT_APPLICABLE;
        }

        $quota = $storageInfo->getQuotaSizeInBytes();
        if ($quota === StorageInfo::STORAGE_SPACE_UNKNOWN) {
            $quota = 0;
        }

        return $this->zfsDatasetFactory->create(
            $storageInfo->getId(),
            $mountpoint,
            $storageInfo->getAllocatedSizeInBytes(),
            $storageInfo->getAllocatedSizeBySnapshotsInBytes(),
            $storageInfo->getCompressRatio(),
            $storageInfo->getFreeSpaceInBytes(),
            $origin,
            $quota,
            $storageInfo->isMounted()
        );
    }

    /**
     * Unmount the all mountable datasets, one at a time.
     *
     * @deprecated Use StorageInterface instead
     */
    private function unmountDatasets()
    {
        $datasets = $this->getMountableDatasets();

        /** @var ZfsDataset[] $datasets */
        $datasets = array_reverse($datasets);

        foreach ($datasets as $dataset) {
            $dataset->unmount();
        }
    }

    /**
     * Remove all files from the mountpoints and remount dataset.
     *
     * @deprecated Use StorageInterface instead
     */
    private function cleanAndRemountDatasets()
    {
        $datasets = $this->getMountableDatasets();

        foreach ($datasets as $dataset) {
            $this->assertDatasetIsNotMounted($dataset);
            $this->cleanDataset($dataset);
            $dataset->mount();
        }
    }

    /**
     * Verify the dataset is unmounted. If it is still mounted, an exception is thrown.
     *
     * @deprecated Use StorageInterface instead
     *
     * @param ZfsDataset $dataset Dataset to check
     */
    private function assertDatasetIsNotMounted(ZfsDataset $dataset)
    {
        if ($dataset->isMounted()) {
            $this->logger->error(
                'ZDS0002 Dataset is still mounted. Expected this to be unmounted',
                [
                    'dataset' => $dataset->getName(),
                    'mountpoint' => $dataset->getMountPoint()
                ]
            );

            $message =
                'Dataset ' . $dataset->getName() .
                ' is still mounted at ' . $dataset->getMountPoint() .
                '. Expected this to be unmounted!';
            throw new ZfsDatasetException($message, $dataset);
        }
    }

    /**
     * Clean the dataset by removing all of the files from the mountpoint.
     *
     * @deprecated Use StorageInterface instead
     *
     * @param ZfsDataset $dataset Dataset to clean
     */
    private function cleanDataset(ZfsDataset $dataset)
    {
        $name = $dataset->getName();
        $mountpoint = $dataset->getMountPoint();

        $folderExists = $this->filesystem->exists($mountpoint);
        if ($folderExists) {
            try {
                $this->logger->debug('ZDS0003 Cleaning dataset mountpoint', ['dataset' => $name, 'mountpoint' => $mountpoint]);
                $this->filesystem->unlinkDir($mountpoint);
            } catch (Exception $exception) {
                $this->logger->error(
                    'ZDS0004 Could not delete files for dataset',
                    [
                        'dataset' => $dataset->getName(),
                        'mountpoint' => $dataset->getMountPoint()
                    ]
                );

                $message =
                    'Could not delete files for dataset ' . $dataset->getName() .
                    ' at mountpoint ' . $dataset->getMountPoint();
                throw new ZfsDatasetException($message, $dataset);
            }
        }
    }

    /**
     * Get mountable ZFS datasets (excludes shares and snapshots)
     *
     * @deprecated Use StorageInterface instead
     *
     * @return ZfsDataset[] List of mountable datasets.
     */
    private function getMountableDatasets()
    {
        $datasets = $this->getAllDatasets();

        $mountableDatasets = array_filter($datasets, function (ZfsDataset $dataset) {
            return $dataset->isMountable();
        });
        return $mountableDatasets;
    }

    /**
     * Returns the default datasets needed by both agents and shares
     *
     * @deprecated Use StorageInterface instead
     *
     * @return ZfsDataset[]
     */
    private function getDefaultDatasets()
    {
        $datasets = [
            $this->zfsDatasetFactory->makePartialDataset(self::HOMEPOOL_DATASET, self::HOMEPOOL_DATASET_PATH),
            $this->zfsDatasetFactory->makePartialDataset(self::HOMEPOOL_HOME_DATASET, self::HOMEPOOL_HOME_DATASET_PATH)
        ];

        return $datasets;
    }
}
