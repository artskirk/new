<?php

namespace Datto\App\Controller\Api\V1\Device\Asset\Local;

use Datto\Asset\RecoveryPoint\DestroySnapshotReason;
use Datto\Asset\RecoveryPoint\LocalSnapshotService;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class Snapshots
{
    /** @var LocalSnapshotService */
    private $localSnapshotService;

    public function __construct(
        LocalSnapshotService $localSnapshotService
    ) {
        $this->localSnapshotService = $localSnapshotService;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_DELETE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "assetKey" = @Datto\App\Security\Constraints\AssetExists(),
     * })
     * @param string $assetKey
     * @param int[] $snapshotEpochs
     * @return int[][]
     */
    public function destroy(string $assetKey, array $snapshotEpochs): array
    {
        $result = $this->localSnapshotService->destroy(
            $assetKey,
            $snapshotEpochs,
            DestroySnapshotReason::MANUAL()
        );

        return $result->toArray();
    }

    /**
     * Delete all the snapshots for an asset (except speedsync critical points when the asset is not archived)
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_DELETE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "assetKey" = @Datto\App\Security\Constraints\AssetExists(),
     * })
     * @param string $assetKey
     * @return bool
     */
    public function purge(string $assetKey): bool
    {
        $this->localSnapshotService->purge(
            $assetKey,
            DestroySnapshotReason::MANUAL()
        );

        return true;
    }
}
