<?php

namespace Datto\ZFS;

use Datto\Core\Storage\SirisStorage;
use Datto\Core\Storage\StorageInterface;
use Datto\Utility\Process\ProcessCleanup;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;

/**
 * Creates ZfsDataset objects.
 *
 * @deprecated Use StorageInterface instead
 *
 * @author Sri Ramanujam <sramanujam@datto.com>
 */
class ZfsDatasetFactory implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private ProcessCleanup $processCleanup;
    private StorageInterface $storage;
    private SirisStorage $sirisStorage;

    public function __construct(
        ProcessCleanup $processCleanup,
        StorageInterface $storage,
        SirisStorage $sirisStorage
    ) {
        $this->processCleanup = $processCleanup;
        $this->storage = $storage;
        $this->sirisStorage = $sirisStorage;
    }

    /**
     * Make a partial dataset instance that only includes a name and mountpoint.
     *
     * Beware: This is an incomplete instance and should not be blindly relied on.
     *
     * @deprecated Use StorageInterface instead
     *
     * @param string $datasetName
     * @param string $datasetPath
     * @return ZfsDataset
     */
    public function makePartialDataset(string $datasetName, string $datasetPath): ZfsDataset
    {
        $zfsDataset = $this->create(
            $datasetName,
            $datasetPath,
            0,
            0,
            0.0,
            0,
            '-',
            0,
            false
        );

        return $zfsDataset;
    }

    /**
     * Creates a ZfsDataset from all of the required parameters.  This function should be private, since we want
     * users of this factory to go through zfs list whenever possible.
     *
     * @deprecated Use StorageInterface instead
     *
     * @return ZfsDataset
     */
    public function create(
        string $name,
        string $mountPoint,
        int $usedSpace,
        int $usedSpaceBySnapshots,
        float $compressionRatio,
        int $availableSpace,
        string $origin,
        int $quota,
        bool $mounted
    ): ZfsDataset {
        $zfsDataset = new ZfsDataset(
            $name,
            $mountPoint,
            $usedSpace,
            $usedSpaceBySnapshots,
            $compressionRatio,
            $availableSpace,
            $origin,
            $quota,
            $mounted,
            $this->processCleanup,
            $this->storage,
            $this->sirisStorage
        );
        $zfsDataset->setLogger($this->logger);

        return $zfsDataset;
    }
}
