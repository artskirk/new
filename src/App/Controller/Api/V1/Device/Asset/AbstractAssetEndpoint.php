<?php

namespace Datto\App\Controller\Api\V1\Device\Asset;

use Datto\Asset\AssetService;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * This class encapsulates all common logic for setting
 * up an API endpoint for all assets including setting up the
 * asset service.
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
 * @author Philipp Heckel <ph@datto.com>
 */
abstract class AbstractAssetEndpoint extends AbstractController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var AssetService */
    protected $assetService;

    public function __construct(AssetService $assetService)
    {
        $this->assetService = $assetService;
    }
}
