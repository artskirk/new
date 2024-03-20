<?php

namespace Datto\App\Controller\Api\V1\Device\Asset;

use Datto\Asset\Asset;
use Datto\Asset\AssetService;
use Datto\Asset\AssetSummaryService;
use Datto\Asset\RecoveryPoint\RecoveryPointInfoService;
use Datto\Asset\Serializer\LegacyRecoveryPointsMetaSerializer;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class RecoveryPoints
{
    /** @var RecoveryPointInfoService */
    private $recoveryPointInfoService;

    /** @var AssetService */
    private $assetService;

    /** @var AssetSummaryService */
    private $assetSummaryService;

    /** @var LegacyRecoveryPointsMetaSerializer */
    private $legacyRecoveryPointMetaSerializer;

    public function __construct(
        AssetService $assetService,
        RecoveryPointInfoService $recoveryPointInfoService,
        AssetSummaryService $assetSummaryService,
        LegacyRecoveryPointsMetaSerializer $legacyRecoveryPointMetaSerializer
    ) {
        $this->recoveryPointInfoService = $recoveryPointInfoService;
        $this->assetService = $assetService;
        $this->assetSummaryService = $assetSummaryService;
        $this->legacyRecoveryPointMetaSerializer = $legacyRecoveryPointMetaSerializer;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "assetKey" = @Datto\App\Security\Constraints\AssetExists()
     * })
     * @param string $assetKey
     * @return array
     */
    public function getAllByAsset(string $assetKey): array
    {
        $asset = $this->assetService->get($assetKey);

        $this->recoveryPointInfoService->refreshCaches($asset);
        $points = $this->recoveryPointInfoService->getRecoveryPointsInfoAsArray($asset);

        return [
            'recoveryPoints' => $points
        ];
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "assetKey" = @Datto\App\Security\Constraints\AssetExists()
     * })
     * @param string $assetKey
     * @return array
     */
    public function getLocalByAsset(string $assetKey): array
    {
        $asset = $this->assetService->get($assetKey);

        $points = $this->recoveryPointInfoService->getLocalRecoveryPointsInfoAsArray($asset);

        return [
            'recoveryPoints' => $points
        ];
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "assetKey" = @Datto\App\Security\Constraints\AssetExists(),
     *     "snapshotEpoch" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^\d+$~")
     * })
     * @param string $assetKey
     * @param int $snapshotEpoch
     * @return array
     */
    public function getByAsset(string $assetKey, int $snapshotEpoch): array
    {
        $asset = $this->assetService->get($assetKey);

        $point = $this->recoveryPointInfoService->get($asset, $snapshotEpoch);

        if ($point !== null) {
            $point = $point->toArray();
        }

        return [
            'recoveryPoint' => $point
        ];
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_CONTINUITY_AUDIT")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "assetKey" = @Datto\App\Security\Constraints\AssetExists()
     * })
     * @param string $assetKey
     * @param int $startTime
     * @param int $endTime
     * @return array
     */
    public function getContinuityInfoByAsset(string $assetKey, int $startTime, int $endTime): array
    {
        $asset = $this->assetService->get($assetKey);

        $this->recoveryPointInfoService->refreshCaches($asset);
        $rawPoints = $this->recoveryPointInfoService->getRecoveryPointsInfoAsArray($asset);

        $points = [];
        foreach ($rawPoints as $epoch => $point) {
            if ($epoch >= $startTime && $epoch <= $endTime) {
                $points[] = [
                    'snapshotEpoch' => $epoch,
                    'existsLocally' => $point['existsLocally'],
                    'existsOffsite' => $point['existsOffsite'],
                    'offsiteStatus' => $point['offsiteStatus'],
                    'screenshotStatus' => $point['screenshotStatus'],
                    'screenshotVerificationSuccess' => !$point['verification']['local']['hasError'],
                ];
            }
        }

        return [
            'recoveryPoints' => $points
        ];
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_READ")
     * @param string $assetKey
     * @return array
     */
    public function getSummaryByAsset(string $assetKey): array
    {
        $asset = $this->assetService->get($assetKey);

        return [
            'summary' => $this->getSummaryAsArray($asset)
        ];
    }

    /**
     * Gets a serialized copy of a single recoveryPointsMeta entry from file on disk.
     * Use this if you plan to unserialize this on another device.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_READ")
     *
     * @param string $assetKey
     * @param int $snapshot
     * @return array
     */
    public function getLocalRecoveryPointInfo(string $assetKey, int $snapshot)
    {
        $asset = $this->assetService->get($assetKey);
        $recoveryPoints = $asset->getLocal()->getRecoveryPoints();

        if (!$recoveryPoints->exists($snapshot)) {
            throw new \Exception("Recovery point $assetKey@$snapshot not found");
        }

        return $this->legacyRecoveryPointMetaSerializer->singleToArray($recoveryPoints->get($snapshot));
    }

    /**
     * Gets a full serialized copy of the recoveryPointsMeta file on disk.
     * Use this if you plan to unserialize this on another device.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_READ")
     *
     * @param string $assetKey
     * @return array
     */
    public function getAllLocalRecoveryPointInfo(string $assetKey)
    {
        $asset = $this->assetService->get($assetKey);
        $recoveryPoints = $asset->getLocal()->getRecoveryPoints();

        return $this->legacyRecoveryPointMetaSerializer->toArray($recoveryPoints);
    }

    /**
     * @param Asset $asset
     * @return array
     */
    private function getSummaryAsArray(Asset $asset)
    {
        return $this->assetSummaryService->getSummary($asset)->toArray();
    }
}
