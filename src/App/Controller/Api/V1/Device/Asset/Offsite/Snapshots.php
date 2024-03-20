<?php

namespace Datto\App\Controller\Api\V1\Device\Asset\Offsite;

use Datto\Asset\RecoveryPoint\OffsiteSnapshotService;
use Datto\Asset\RecoveryPoint\DestroySnapshotReason;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class Snapshots
{
    /** @var OffsiteSnapshotService */
    private $offsiteSnapshotService;

    public function __construct(OffsiteSnapshotService $offsiteSnapshotService)
    {
        $this->offsiteSnapshotService = $offsiteSnapshotService;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE_RETENTION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_DELETE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_DELETE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "assetKey" = @Datto\App\Security\Constraints\AssetExists(),
     * })
     * @param string $assetKey
     * @param int[] $snapshotEpochs
     * @return int[][]
     */
    public function destroy(string $assetKey, array $snapshotEpochs): array
    {
        $sourceReason = DestroySnapshotReason::MANUAL();

        // TODO: Get client ip address from Symfony container when available.
        $sourceAddress = $_SERVER['REMOTE_IP'] ?? null;

        $result = $this->offsiteSnapshotService->destroy(
            $assetKey,
            $snapshotEpochs,
            $sourceReason,
            $sourceAddress
        );

        return $result->toArray();
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE_RETENTION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_DELETE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_DELETE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "assetKey" = @Datto\App\Security\Constraints\AssetExists(),
     * })
     * @param string $assetKey
     * @return bool
     */
    public function purge(string $assetKey): bool
    {
        $sourceReason = DestroySnapshotReason::MANUAL();

        // TODO: Get client ip address from Symfony container when available.
        $sourceAddress = $_SERVER['REMOTE_IP'] ?? null;

        $this->offsiteSnapshotService->purge(
            $assetKey,
            $sourceReason,
            $sourceAddress
        );

        return true;
    }
}
