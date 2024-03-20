<?php

namespace Datto\App\Controller\Api\V1\Device;

use Datto\System\CheckinService;

/**
 * API endpoints to run and check on checkin
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
class Checkin
{
    /** @var CheckinService */
    private $checkinService;

    public function __construct(CheckinService $checkinService)
    {
        $this->checkinService = $checkinService;
    }

    /**
     * Run checkin in the background
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_CHECKIN")
     * @Datto\App\Security\RequiresPermission("PERMISSION_CHECKIN")
     *
     * @return bool
     */
    public function start(): bool
    {
        $this->checkinService->checkin();

        return true;
    }

    /**
     * Returns information about checkin
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_CHECKIN")
     * @Datto\App\Security\RequiresPermission("PERMISSION_CHECKIN")
     *
     * @return array
     */
    public function get(): array
    {
        return [
            'timestamp' => $this->checkinService->getTimestamp(),
            'seconds' => $this->checkinService->getSecondsSince()
        ];
    }
}
