<?php

namespace Datto\Cloud;

use Datto\Asset\Asset;
use Datto\Asset\AssetService;
use Datto\Common\Resource\ProcessFactory;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Access information about the device's cloud storage usage
 *
 * @author Peter Salu <psalu@datto.com>
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class CloudStorageUsageService
{
    const SPEEDSYNC_TIMEOUT = 90;

    private ProcessFactory $processFactory;

    public function __construct(
        ProcessFactory $processFactory
    ) {
        $this->processFactory = $processFactory;
    }

    /**
     * Get the amount of cloud storage used by an asset
     *
     * @param asset $asset asset to get cloud storage usage of
     * @return int The amount of cloud space the asset uses in bytes
     */
    public function getAssetCloudStorageUsage(Asset $asset): int
    {
        $path = $asset->getDataset()->getZfsPath();

        $process = $this->processFactory
            ->get(['speedsync', 'remote', 'get', 'used', $path])
            ->setTimeout(self::SPEEDSYNC_TIMEOUT);

        try {
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            return 0;
        }

        return intval($process->getOutput());
    }
}
