<?php

namespace Datto\App\Controller\Api\V1\Device\Asset\Local;

use Datto\Asset\AssetService;
use Datto\Log\LoggerAwareTrait;
use Datto\Service\Retention\RetentionFactory;
use Datto\Service\Retention\RetentionService;
use Datto\Service\Retention\RetentionType;
use Psr\Log\LoggerAwareInterface;

/**
 * This class contains the API endpoints for running local retention operations.
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
 * @author Brian Grogan <bgrogan@datto.com>
 */
class Retention implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var AssetService */
    private $assetService;

    /** @var RetentionFactory */
    private $retentionFactory;

    /** @var RetentionService */
    private $retentionService;

    public function __construct(
        AssetService $assetService,
        RetentionFactory $retentionFactory,
        RetentionService $retentionService
    ) {
        $this->assetService = $assetService;
        $this->retentionFactory = $retentionFactory;
        $this->retentionService = $retentionService;
    }

    /**
     * Runs the snapshot retention process on local snapshots, according to the local retention policy.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_LOCAL_RETENTION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_LOCAL_RETENTION")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "assetKey" = @Datto\App\Security\Constraints\AssetExists(),
     * })
     * @param string $name Name of the asset on which to run local retention
     * @return boolean This call will always return true unless an Exception is thrown
     */
    public function run($assetKey)
    {
        $this->logger->setAssetContext($assetKey);
        $this->logger->info('RET2610 Retention has been triggered manually'); // log code is used by device-web see DWI-2252

        $asset = $this->assetService->get($assetKey);
        $retention = $this->retentionFactory->create($asset, RetentionType::LOCAL());
        $this->retentionService->doRetention($retention, false);

        return true;
    }
}
