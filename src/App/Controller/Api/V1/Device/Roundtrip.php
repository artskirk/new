<?php

namespace Datto\App\Controller\Api\V1\Device;

use Datto\Roundtrip\RoundtripManager;

/**
 * API endpoint for Roundtrip delegation.
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
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class Roundtrip
{
    /* @var RoundtripManager */
    private $roundtripManager;

    public function __construct(RoundtripManager $roundtripManager)
    {
        $this->roundtripManager = $roundtripManager;
    }

    /**
     * Checks whether or not the device has an active Roundtrip
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ROUNDTRIP")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ROUNDTRIP")
     *
     * @return string|bool The mount point if it exists, False if it was not found
     */
    public function hasActiveRoundtrip()
    {
        return $this->roundtripManager->hasActiveRoundtrip();
    }
}
