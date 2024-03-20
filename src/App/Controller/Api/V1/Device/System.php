<?php

namespace Datto\App\Controller\Api\V1\Device;

use Datto\Cloud\SpeedTestService;
use Datto\Util\DateTimeZoneService;
use Exception;

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
class System
{
    /** @var DateTimeZoneService */
    private $timeZoneService;

    /** @var SpeedTestService */
    private $speedTestService;

    public function __construct(
        DateTimeZoneService $timeZoneService,
        SpeedTestService $speedTestService
    ) {
        $this->timeZoneService = $timeZoneService;
        $this->speedTestService = $speedTestService;
    }

    /**
     * Get the current time-zone of the device.
     *
     * FIXME This endpoint should be in v1/device/time or something; when moving, please note that BMR calls this endpoint!
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_TIME_ZONE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_TIME_ZONE_READ")
     * @return array
     */
    public function getTimeZone()
    {
        $timeZone = $this->timeZoneService->getTimeZone();

        if ($timeZone === false) {
            throw new Exception('Unable to get time-zone');
        }

        return array(
            'timeZone' => $timeZone
        );
    }

    /**
     * Set the current time-zone of the device.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_TIME_ZONE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_TIME_ZONE_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "timezone" = @Datto\App\Security\Constraints\TimeZone()
     * })
     * @param string $timeZone
     */
    public function setTimeZone(string $timeZone): void
    {
        $success = $this->timeZoneService->setTimeZone($timeZone);

        if (!$success) {
            throw new Exception('Unable to set time-zone');
        }
    }

    /**
     * Runs a speed test and returns the results in KB/s.
     *
     * FIXME This should be moved to v1/device/offsite
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_WRITE")
     * @return float|int
     */
    public function calculateMaximumUploadSpeed()
    {
        return $this->speedTestService->calculateMaximumUploadSpeed();
    }
}
