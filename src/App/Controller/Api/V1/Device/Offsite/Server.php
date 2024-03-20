<?php

namespace Datto\App\Controller\Api\V1\Device\Offsite;

use Datto\Service\Offsite\OffsiteServerService;

/**
 * This is an API end-point to expose off-site server information.
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
 * @author Alex Mankowski <amankowski@datto.com>
 */
class Server
{
    /** @var OffsiteServerService */
    private $offsiteServerService;

    public function __construct(
        OffsiteServerService $offsiteServerService
    ) {
        $this->offsiteServerService = $offsiteServerService;
    }

    /**
     * Gets the off-site server IP address for the device.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_READ")
     * @return string Returns IP address of primary off-site server.
     */
    public function getServerAddress()
    {
        return $this->offsiteServerService->getServerAddress();
    }
}
