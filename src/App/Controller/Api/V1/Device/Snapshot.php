<?php
namespace Datto\App\Controller\Api\V1\Device;

use Datto\Asset\AssetService;
use Datto\Asset\AssetType;
use Datto\Cloud\AssetSyncService;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Exception;

/**
 * API endpoint for snapshot functions
 *
 * Important note:
 *   This is an API endpoint, and NOT the actual library class.
 *   Do NOT add actual functionality to this class. Use library
 *   classes (and functions, if you must) instead!
 *
 * How to call this API?
 *   Please refer to the /api/api.php file and/or the
 *   Datto\API\Server class for details on how to call this API.
 */
class Snapshot
{
    /** @var AssetService */
    private $assetService;

    public function __construct(AssetService $assetService)
    {
        // Required for doctrine autoloader to find custom annotations.
        AnnotationRegistry::registerLoader('class_exists');

        $this->assetService = $assetService;
    }

    /**
     * FIXME move this endpoint to v1/device/offsite or something
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "hostname" = @Datto\App\Security\Constraints\AssetExists(),
     *     "snapshot" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[-]{0,1}\d+$~")
     * })
     * @param string $assetKey
     * @param int $snapshotEpoch
     * @return bool
     */
    public function send(string $assetKey, int $snapshotEpoch)
    {
        $asset = $this->assetService->get($assetKey);
        if ($asset->getOriginDevice()->isReplicated()) {
            throw new Exception('The snaphots of replicated assets cannot be sent offsite.');
        }
        if ($asset->isType(AssetType::ZFS_SHARE)) {
            throw new Exception('Snapshots of legacy ZFS shares cannot be sent offsite on-demand.');
        }

        $assetSyncService = new AssetSyncService($assetKey);
        return $assetSyncService->replicateOffsite($snapshotEpoch);
    }
}
