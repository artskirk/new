<?php

namespace Datto\App\Controller\Api\V1\Device;

use Datto\Asset\AssetService;
use Datto\Asset\AssetType;
use Datto\Cloud\CloudStorageUsageService;
use Datto\Cloud\SpeedSync;
use Datto\Cloud\SpeedSyncStatusService;
use Datto\Utility\ByteUnit;

/**
 * API endpoints for offsite synchronization status
 *
 * @author Peter Salu <psalu@datto.com>
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class Synchronize
{
    /** @var CloudStorageUsageService */
    private $cloudStorageUsageService;

    /** @var SpeedSyncStatusService */
    private $speedSyncStatusService;

    /** @var AssetService */
    private $assetService;

    public function __construct(
        CloudStorageUsageService $cloudStorageUsageService,
        SpeedSyncStatusService $speedSyncStatusService,
        AssetService $assetService
    ) {
        $this->cloudStorageUsageService = $cloudStorageUsageService;
        $this->speedSyncStatusService = $speedSyncStatusService;
        $this->assetService = $assetService;
    }

    /**
     * Gets the cloud storage usage for all assets.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_READ")
     * @return array
     */
    public function getCloudStorageUsage(): array
    {
        $assets = $this->assetService->getAll();
        $cloudStorage = [];

        foreach ($assets as $asset) {
            if ($asset->getOffsiteTarget() !== SpeedSync::TARGET_CLOUD) {
                continue;
            }

            $spaceUsed = $this->cloudStorageUsageService->getAssetCloudStorageUsage($asset);
            $cloudStorage[] = [
                'spaceUsed' => round(ByteUnit::BYTE()->toGiB($spaceUsed), 2),
                'keyName' => $asset->getKeyName(),
                'displayName' => $asset->getDisplayName(),
                'isShare' => $asset->isType(AssetType::SHARE),
                'assetType' => $asset->getType()
            ];
        }

        $sortBySpaceUsed = function ($asset1, $asset2) {
            return $asset2['spaceUsed'] <=> $asset1['spaceUsed'];
        };
        usort($cloudStorage, $sortBySpaceUsed);

        return $cloudStorage;
    }

    /**
     * Get status of speedsync offsite replication
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_READ")
     * @return array
     */
    public function getOffsiteReplicationStatus(): array
    {
        $speedsyncActions = $this->speedSyncStatusService->getSpeedsyncActionsByAsset();
        $assets = $this->assetService->getAll();

        $result = [];

        foreach ($assets as $asset) {
            $offsitePointsArray = $this->speedSyncStatusService->getAssetOffsitePoints($asset);
            $assetData = [
                'keyName' => $asset->getKeyName(),
                'displayName' => $asset->getDisplayName(),
                'offsitePoint' => (is_array($offsitePointsArray) && !empty($offsitePointsArray)) ?
                    max($offsitePointsArray) : null,
                'isReplicated' => $asset->getOriginDevice()->isReplicated(),
                'isShare' => $asset->isType(AssetType::SHARE)
            ];

            $hasSpeedsyncAction = array_key_exists($asset->getKeyName(), $speedsyncActions);
            if ($hasSpeedsyncAction) {
                $syncData = $speedsyncActions[$asset->getKeyName()];
                $syncData = array_merge([
                    'action' => '',
                    'size' => 0,
                    'rate' => 0,
                    'sent' => 0,
                    'eta' => 0,
                    'point' => 0
                ], $syncData);
                $assetData['syncIsActive'] = in_array($syncData['action'], ['build', 'stream', 'send']);
                $assetData['syncData'] = $syncData;
            }

            $result[] = $assetData;
        }

        return $result;
    }

    /**
     * Refresh cached speedsync data.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_WRITE")
     */
    public function refresh(): void
    {
        $this->speedSyncStatusService->refreshCaches();
    }
}
