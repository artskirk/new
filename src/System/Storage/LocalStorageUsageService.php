<?php

namespace Datto\System\Storage;

use Datto\Asset\AssetService;
use Datto\Common\Resource\ProcessFactory;
use Datto\ZFS\ZfsDatasetService;
use Datto\ZFS\ZpoolService;

/**
 * Access information about the device's local storage usage
 *
 * @author Peter Salu <psalu@datto.com>
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class LocalStorageUsageService
{
    /** @var AssetService */
    private $assetService;

    /** @var ZpoolService */
    private $zpoolService;

    private ProcessFactory $processFactory;

    public function __construct(
        AssetService $assetService,
        ZpoolService $zpoolService,
        ProcessFactory $processFactory
    ) {
        $this->assetService = $assetService;
        $this->zpoolService = $zpoolService;
        $this->processFactory = $processFactory;
    }

    /**
     * Get the local storage data for all assets
     *
     * @return int[] array with asset keynames as keys, and bytes of storage used as values
     */
    public function getSpaceUsedByAssets()
    {
        $assets = $this->assetService->getAll();
        $assetInformation = [];

        foreach ($assets as $asset) {
            $assetInformation[$asset->getKeyName()] = $asset->getDataset()->getUsedSize();
        }

        return $assetInformation;
    }

    /**
     * @return int The amount of free space on homepool in bytes
     */
    public function getFreeSpace(): int
    {
        return $this->zpoolService->getFreeSpace(ZfsDatasetService::HOMEPOOL_HOME_DATASET);
    }

    /**
     * @return int The amount of space used by offsite transfer files in bytes
     */
    public function getOffsiteTransferSpace(): int
    {
        $process = $this->processFactory->get(['du', '--bytes', '/datto/transfer']);

        $process->run();
        $output = $process->getOutput();
        $outputData = explode("\t", $output);
        $offsiteTransferSpaceUsed = is_array($outputData) ? $outputData[0] : 0;

        return $offsiteTransferSpaceUsed;
    }
}
