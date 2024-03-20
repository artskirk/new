<?php

namespace Datto\App\Controller\Api\V1\Device\Asset\Offsite;

use Datto\App\Controller\Api\V1\Device\Asset\AbstractAssetEndpoint;
use Datto\Asset\AssetService;
use Datto\Cloud\SpeedSyncMaintenanceService;

/**
 * API endpoints to expose offsite sync maintenance functionality for individual assets.
 *
 * Important note:
 *   This is an API endpoint, and NOT the actual library class.
 *   Do NOT add actual functionality to this class. Use library
 *   classes (and functions, if you must) instead!
 *
 * How to call this API?
 *   Please refer to the /api/api.php file and/or the
 *   Datto\API\Server class for details on how to call this API.
 *
 * @author Christopher R. Wicks <cwicks@datto.com>
 * @codeCoverageIgnore
 */
class Maintenance extends AbstractAssetEndpoint
{
    /** @var SpeedSyncMaintenanceService */
    private $speedSyncService;

    public function __construct(
        AssetService $assetService,
        SpeedSyncMaintenanceService $speedSyncService
    ) {
        parent::__construct($assetService);
        $this->speedSyncService = $speedSyncService;
    }

    /**
     * Pause offsite sync for the given asset.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "assetKeyName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param $assetKeyName
     * @return boolean true if successful
     */
    public function pause($assetKeyName)
    {
        $this->speedSyncService->pauseAsset($assetKeyName);
        return true;
    }

    /**
     * Resume offsite sync for the given asset.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "assetKeyName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param $assetKeyName
     * @return boolean true if successful
     */
    public function resume($assetKeyName)
    {
        $this->speedSyncService->resumeAsset($assetKeyName);
        return true;
    }

    /**
     * Is offsite sync paused for the asset?
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_READ")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "assetKeyName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param $assetKeyName
     * @return bool
     */
    public function isPaused($assetKeyName)
    {
        return $this->speedSyncService->isAssetPaused($assetKeyName);
    }

    /**
     * Get the list of asset names (not key names) for which offsite sync is paused.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_READ")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_READ")
     * @return string[]
     */
    public function getPausedAssetNames()
    {
        return $this->speedSyncService->getPausedAssetNames();
    }
}
