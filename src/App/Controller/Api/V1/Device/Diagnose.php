<?php

namespace Datto\App\Controller\Api\V1\Device;

use Datto\Service\Device\DriveLight;

/**
 * API endpoint for diagnostic device functions.
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
class Diagnose
{
    private DriveLight $driveLight;

    public function __construct(DriveLight $driveLights)
    {
        $this->driveLight = $driveLights;
    }

    /**
     * Lights up the drive light of the given drive for the given
     * amount of time.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_STORAGE_DIAGNOSE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_STORAGE_DIAGNOSE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "drive" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[a-z0-9]+$~"),
     *   "timeout" = @Symfony\Component\Validator\Constraints\Range(min = 0, max = 60)
     * })
     * @param string $drive Drive string, e.g. sda1 for /dev/sda1
     * @param int $timeout Time in seconds to light the device light
     * @return bool Expected to be true, unless the timeout is not reached due to an early failure
     */
    public function blinkDriveLight(string $drive, int $timeout = 10): bool
    {
        return $this->driveLight->blink($drive, $timeout);
    }
}
