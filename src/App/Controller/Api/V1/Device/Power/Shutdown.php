<?php

namespace Datto\App\Controller\Api\V1\Device\Power;

use Datto\System\PowerManager;

/**
 * API endpoint to shut down the device
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
 * @author Christopher Bitler <cbitler@datto.com>
 */
class Shutdown
{
    /** @var PowerManager */
    private $powerManager;

    public function __construct(
        PowerManager $powerManager
    ) {
        $this->powerManager = $powerManager;
    }

    /**
     * Immediately shuts down a device.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_POWER_MANAGEMENT")
     * @Datto\App\Security\RequiresPermission("PERMISSION_POWER_MANAGEMENT")
     */
    public function now(): void
    {
        $this->powerManager->shutdownNow();
    }
}
