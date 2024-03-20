<?php

namespace Datto\Dataset;

use Datto\AppKernel;
use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Common\Resource\PosixHelper;
use Datto\Common\Resource\Sleep;
use Datto\Common\Utility\Block\Blockdev;
use Datto\Core\Storage\SirisStorage;
use Datto\Core\Storage\StorageInterface;
use Datto\Core\Storage\Zfs\ZfsStorage;
use Datto\Log\DeviceLogger;
use Datto\Log\LoggerFactory;
use Datto\System\MountManager;
use Datto\Utility\File\Lsof;
use Datto\Utility\Process\ProcessCleanup;
use Datto\ZFS\ZfsService;

/**
 * Factory for ZFS_Dataset and ZVolDataset.
 * These classes cannot be instantiated through the container because they store asset specific state (zfsPath).
 *
 * @deprecated Use StorageInterface instead
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class DatasetFactory
{
    private ZfsService $zfsService;
    private Filesystem $filesystem;
    private MountManager $mountManager;
    private Sleep $sleep;
    private ProcessCleanup $processCleanup;
    private Blockdev $blockdev;
    private StorageInterface $storage;
    private SirisStorage $sirisStorage;
    private ProcessFactory $processFactory;
    private DeviceLogger $deviceLogger;

    public function __construct(
        Filesystem $filesystem = null,
        ZfsService $zfsService = null,
        MountManager $mountManager = null,
        Sleep $sleep = null,
        ProcessCleanup $processCleanup = null,
        Blockdev $blockdev = null,
        StorageInterface $storage = null,
        SirisStorage $sirisStorage = null,
        ProcessFactory $processFactory = null,
        DeviceLogger $deviceLogger = null
    ) {
        $this->processFactory = $processFactory ?? new ProcessFactory();
        $this->filesystem = $filesystem ?? new Filesystem($this->processFactory);
        $this->zfsService = $zfsService ?? new ZfsService();
        $this->mountManager = $mountManager ?? new MountManager();
        $this->sleep = $sleep ?? new Sleep();
        $this->processCleanup = $processCleanup ?? new ProcessCleanup(
            // @codeCoverageIgnoreStart
            new Lsof(),
            new PosixHelper($this->processFactory),
            new Sleep(),
            new Filesystem($this->processFactory)
            // @codeCoverageIgnoreEnd
        );
        $this->blockdev = $blockdev ?? new Blockdev($this->processFactory);
        $this->storage = $storage ?? AppKernel::getBootedInstance()->getContainer()->get(ZfsStorage::class);
        $this->sirisStorage = $sirisStorage ?? AppKernel::getBootedInstance()->getContainer()->get(SirisStorage::class);
        $this->deviceLogger = $deviceLogger ?? LoggerFactory::getDeviceLogger();
    }

    public function createZvolDataset(string $zfsPath): ZVolDataset
    {
        $zvolDataset = new ZVolDataset(
            $zfsPath,
            $this->filesystem,
            $this->zfsService,
            $this->mountManager,
            $this->sleep,
            $this->blockdev,
            $this->storage,
            $this->sirisStorage,
            $this->processFactory
        );
        $zvolDataset->setLogger($this->deviceLogger);

        return $zvolDataset;
    }

    public function createZfsDataset(string $zfsPath): ZFS_Dataset
    {
        $zfsDataset = new ZFS_Dataset(
            $zfsPath,
            $this->filesystem,
            $this->zfsService,
            $this->mountManager,
            $this->processCleanup,
            $this->storage,
            $this->sirisStorage
        );
        $zfsDataset->setLogger($this->deviceLogger);

        return $zfsDataset;
    }
}
