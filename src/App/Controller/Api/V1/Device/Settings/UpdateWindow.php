<?php

namespace Datto\App\Controller\Api\V1\Device\Settings;

use Datto\System\Update\UpdateWindowService;

/**
 * API endpoint to query and change maintenance mode settings.
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
 * @author Chad Kosie <ckosie@datto.com>
 */
class UpdateWindow
{
    /** @var UpdateWindowService */
    private $updateWindowService;

    public function __construct(UpdateWindowService $updateWindowService)
    {
        $this->updateWindowService = $updateWindowService;
    }

    /**
     * API call to set the start and end hours of the device update window
     *
     * FIXME We should combine v1/device/settings/{upgradeChannel,updateWindow} and v1/device/system/upgrade
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_UPGRADES")
     * @Datto\App\Security\RequiresPermission("PERMISSION_UPGRADES")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "start" = @Symfony\Component\Validator\Constraints\Type("integer"),
     *   "end" = @Symfony\Component\Validator\Constraints\Type("integer")
     * })
     *
     * @param int $startHour
     * @param int $endHour
     * @return bool
     */
    public function setWindow($startHour, $endHour)
    {
        $this->updateWindowService->setWindow($startHour, $endHour);

        return true;
    }

    /**
     * API call to retrieve the start and end hours of the device update window
     *
     * FIXME We should combine v1/device/settings/{upgradeChannel,updateWindow} and v1/device/system/upgrade
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_UPGRADES")
     * @Datto\App\Security\RequiresPermission("PERMISSION_UPGRADES")
     * @return array
     */
    public function getWindow()
    {
        $updateWindow = $this->updateWindowService->getWindow();
        return [
            'startHour' => $updateWindow->getStartHour(),
            'endHour' => $updateWindow->getEndHour()
        ];
    }
}
