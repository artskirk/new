<?php
namespace Datto\Cloud;

use Datto\Asset\Asset;
use Datto\Resource\DateTimeService;
use Datto\Utility\Cloud\SpeedSync;

/**
 * Service for accessing speedsync status information
 *
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class SpeedSyncStatusService
{
    /** @var SpeedSync */
    private $speedSync;

    /** @var DateTimeService */
    private $dateTimeService;

    /**
     * @param SpeedSync $speedSync
     * @param DateTimeService $dateTimeService
     */
    public function __construct(SpeedSync $speedSync, DateTimeService $dateTimeService)
    {
        $this->speedSync = $speedSync;
        $this->dateTimeService = $dateTimeService;
    }

    /**
     * @param Asset $asset asset to get offsite points for
     * @return \int[] list of timestamps of offsite points
     */
    public function getAssetOffsitePoints(Asset $asset)
    {
        return $this->speedSync->getRemotePoints($asset->getDataset()->getZfsPath());
    }

    /**
     * Get the speedsync actions for all assets
     *
     * @return array of speedsync actions for each asset, indexed by asset keyname
     */
    public function getSpeedsyncActionsByAsset(): array
    {
        $rawActions = $this->speedSync->getActions();

        $actionsByAsset = [];
        foreach ($rawActions as $action) {
            $snapshotString = $action['snapshot'];
            $point = $this->getPointFromSnapshotString($snapshotString);
            $asset = $this->getKeyNameFromSnapshotString($snapshotString);
            $action['point'] = $point;

            if ($action['size'] > 0 && $action['sent'] > 0 && $action['rate'] > 0) {
                $action['eta'] = $this->dateTimeService->getTime() +
                    ceil(($action['size'] - $action['sent']) / $action['rate']);
            }

            $actionsByAsset[$asset] = $action;
        }
        return $actionsByAsset;
    }

    /**
     * Refresh all cached speedsync data.
     */
    public function refreshCaches()
    {
        $this->speedSync->refreshAllTask();
    }

    /**
     * @param string $snapshotString speedsync string describing a snapshot
     * @return int snapshot epoch
     */
    private function getPointFromSnapshotString($snapshotString): int
    {
        return substr($snapshotString, strpos($snapshotString, '@') + 1);
    }

    /**
     * @param string $snapshotString speedsync string describing a snapshot
     * @return string asset keyName
     */
    private function getKeyNameFromSnapshotString($snapshotString): string
    {
        return substr(
            $snapshotString,
            strrpos($snapshotString, '/') + 1,
            strpos($snapshotString, '@') - strlen($snapshotString)
        );
    }
}
