<?php

namespace Datto\App\Controller\Api\V1\Device\Config\Login;

use Datto\Config\Login\LocalLoginService;

/**
 * API endpoint to control local login.
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
 * @author Stephen Allan <sallan@datto.com>
 */
class Local
{
    /** @var LocalLoginService */
    private $localLoginService;

    public function __construct(LocalLoginService $localLoginService)
    {
        $this->localLoginService = $localLoginService;
    }

    /**
     * Enable local login.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_REMOTE_WEB")
     * @Datto\App\Security\RequiresPermission("PERMISSION_REMOTE_WEB")
     */
    public function enable(): void
    {
        $this->localLoginService->enable();
    }

    /**
     * Disable local login.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_REMOTE_WEB")
     * @Datto\App\Security\RequiresPermission("PERMISSION_REMOTE_WEB")
     */
    public function disable(): void
    {
        $this->localLoginService->disable();
    }

    /**
     * Check if local login is enabled.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_REMOTE_WEB")
     * @Datto\App\Security\RequiresPermission("PERMISSION_REMOTE_WEB")
     *
     * @return bool True if local login is enabled, False otherwise
     */
    public function isEnabled(): bool
    {
        return $this->localLoginService->isEnabled();
    }
}
