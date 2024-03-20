<?php

namespace Datto\App\Controller\Api\V1\Device\Asset;

use Datto\Asset\AssetService;
use Datto\BMR\BmrService;
use Throwable;

/**
 * This class contains the API endpoints for creating and destroying dataset clones.
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
 * @author Micheal Corbeil <mcorbeil@datto.com>
 * @author Charles Shapleigh <cshapleigh@datto.com>
 * @deprecated No longer used with the new "pull" cloning on the stick.
 */
class DatasetClone extends AbstractAssetEndpoint
{
    /** @var BmrService */
    private $bmrService;

    public function __construct(
        AssetService $assetService,
        BmrService $bmrService
    ) {
        parent::__construct($assetService);
        $this->bmrService = $bmrService;
    }
    /**
     * Clone a zfs
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_BMR_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agent" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~"),
     *   "snapshot" = @Symfony\Component\Validator\Constraints\Type(type = "numeric"),
     *   "extension" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~bmr~")
     * })
     * @param string $agent Name of the agent
     * @param string $snapshot Name of the snapshot
     * @param string $extension Extension to use for path to image clone
     *
     * @return bool $result
     */
    public function create($agent, $snapshot, $extension)
    {
        $asset = $this->assetService->get($agent);
        try {
            $this->bmrService->create($asset, $snapshot, $extension);
            return true;
        } catch (Throwable $e) {
            $this->logger->setAssetContext($agent);
            $this->logger->error('BMR0005 Error cloning dataset.', ['exception' => $e]);
            return false;
        }
    }

    /**
     * Destroy a zfs clone
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_BMR_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agent" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~"),
     *   "snapshot" = @Symfony\Component\Validator\Constraints\Type(type = "numeric"),
     *   "extension" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~bmr~")
     * })
     * @param string $agent the agent with the clone
     * @param string $snapshot the snapshot for the agent with the clone
     * @param string $extension the extension for the zfs clone path
     * @return bool $result true on success, false on failure
     */
    public function destroy($agent, $snapshot, $extension)
    {
        $asset = $this->assetService->get($agent);
        try {
            $this->bmrService->destroy($asset, $snapshot, $extension);
            return true;
        } catch (Throwable $e) {
            $this->logger->setAssetContext($agent);
            $this->logger->error('BMR0006 Error destroying dataset.', ['exception' => $e]);
            return false;
        }
    }
}
