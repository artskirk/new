<?php
namespace Datto\App\Controller\Api\V1\Device;

use Datto\RemoteWeb\RemoteWebService;

/**
 * API endpoint for remote web functions
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
class RemoteWeb
{
    /** @var RemoteWebService */
    private $remoteWebService;

    public function __construct(RemoteWebService $remoteWebService)
    {
        $this->remoteWebService = $remoteWebService;
    }

    /**
     * Set remote web force login on the device
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_REMOTE_WEB")
     * @Datto\App\Security\RequiresPermission("PERMISSION_REMOTE_WEB")
     * @param bool $forceLogin the new force login setting
     */
    public function setForceLogin(bool $forceLogin): void
    {
        $this->remoteWebService->setForceLogin($forceLogin);
    }
}
