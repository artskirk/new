<?php

namespace Datto\App\Controller\Api\V1\Device;

use Datto\Asset\Agent\Agent;
use Datto\Asset\AssetService;
use Datto\Asset\Share\Share;
use Datto\Restore\RestoreService;
use Datto\Restore\RestoreType;
use Datto\Samba\SambaManager;
use Datto\Util\DateTimeZoneService;
use Exception;

/**
 * API endpoint to query and change device settings.
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
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class Restore
{
    /** @var RestoreService */
    private $restoreService;

    /** @var SambaManager */
    private $sambaManager;

    /** @var DateTimeZoneService */
    private $dateTimeZoneService;

    /** @var AssetService */
    private $assetService;

    public function __construct(
        RestoreService $restoreService,
        SambaManager $sambaManager,
        DateTimeZoneService $dateTimeZoneService,
        AssetService $assetService
    ) {
        $this->restoreService = $restoreService;
        $this->sambaManager = $sambaManager;
        $this->dateTimeZoneService = $dateTimeZoneService;
        $this->assetService = $assetService;
    }

    /**
     * Get a list of restores that do not appear in the normal UI
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_READ")
     * @return string JSON encoded array of restores' data
     */
    public function getOrphans()
    {
        return $this->restoreService->getOrphans();
    }

    /**
     * Get a list of all restores for a given asset
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *      "assetKey" = @Datto\App\Security\Constraints\AssetExists()
     * })
     * @param string $assetKey
     * @return \Datto\Restore\Restore[]
     */
    public function getAllForAsset(string $assetKey)
    {
        return $this->restoreService->getForAsset($assetKey);
    }

    /**
     * Removes the specified restore
     *
     * FIXME This endpoint should be split by type; or the permission should be checked more granularly
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_WRITE")
     * @param string $assetKeyName Name of the restore
     * @param string $point Which restore point
     * @param string $type type/suffix of restore (file, active, etc.)
     * @param bool $forced Whether or not to force (i.e. close all open connections)
     * @return bool Temporary, delete when unmount implemented
     */
    public function remove($assetKeyName, $point, $type, $forced = false)
    {
        // TODO: support other restore types
        if ($type !== RestoreType::EXPORT) {
            throw new Exception('The restore:remove endpoint currently only supports image exports.');
        }

        $asset = $this->assetService->get($assetKeyName);
        $dateFormat = $this->dateTimeZoneService->localizedDateFormat('time-date-hyphenated');
        $date = date($dateFormat, $point);

        if ($asset instanceof Agent) {
            $restorePoint = $asset->getHostname() . "-" . $date;
        } elseif ($asset instanceof Share) {
            $restorePoint = $assetKeyName . "-" . $date;
        } else {
            throw new Exception("Unsupported asset type");
        }

        $hasOpenConnections = count($this->sambaManager->getOpenClientConnections($restorePoint)) > 0;
        if (!$forced && $hasOpenConnections) {
            throw new Exception("Share has open connections");
        }

        $restore = $this->restoreService->create($assetKeyName, $point, $type);
        $this->restoreService->remove($restore);
        $this->restoreService->save();
    }
}
