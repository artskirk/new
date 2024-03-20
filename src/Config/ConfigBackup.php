<?php

namespace Datto\Config;

use Datto\ZFS\ZfsDatasetService;

/**
 * Class for accessing information about the ConfigBackup share
 *
 * @author David Desorcie <ddesorcie@datto.com>
 */
class ConfigBackup
{
    const ZFS_PATH = 'homePool/home/configBackup';
    const KEYNAME = 'configBackup';

    /** @var ZfsDatasetService */
    private $datasetService;

    /**
     * @param ZfsDatasetService $zfsDatasetService
     */
    public function __construct(
        ZfsDatasetService $zfsDatasetService
    ) {
        $this->datasetService = $zfsDatasetService;
    }

    /**
     * Get a list of ConfigBackup snapshots as an array of integer snapshot epochs
     *
     * @return int[] snapshot epochs
     */
    public function getSnapshotEpochs(): array
    {
        $dataset = $this->datasetService->getDataset(self::ZFS_PATH);
        $snapshots = $dataset->getSnapshots();
        $epochs = [];
        foreach ($snapshots as $snapshot) {
            $epochs[] = intval($snapshot->getName());
        }
        return $epochs;
    }

    /**
     * Get the used size of ConfigBackup
     *
     * @return int used size in bytes
     */
    public function getUsedSize(): int
    {
        $dataset = $this->datasetService->getDataset(self::ZFS_PATH);
        $usedSize = $dataset->getUsedSize();
        return $usedSize;
    }
}
