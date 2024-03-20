<?php

namespace Datto\App\Controller\Api\V1\Device\Settings;

use Datto\System\MaintenanceModeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

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
 * @author Philipp Heckel <ph@datto.com>
 */
class MaintenanceMode extends AbstractController
{
    /** @var MaintenanceModeService */
    private $maintenanceModeService;

    public function __construct(
        MaintenanceModeService $maintenanceModeService
    ) {
        $this->maintenanceModeService = $maintenanceModeService;
    }

    /**
     * Enables the maintenance mode for a period of time
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_MAINTENANCE_MODE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_MAINTENANCE_MODE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "hours" = {
     *     @Symfony\Component\Validator\Constraints\Type(type = "int"),
     *     @Symfony\Component\Validator\Constraints\NotNull()
     *   }
     * })
     * @param int $hours
     * @return int End time stamp
     */
    public function enable(int $hours): int
    {
        $user = $this->getUser();
        $userName = $user ? $user->getUserIdentifier() : null;
        $this->maintenanceModeService->enable($hours, $userName);
        return $this->maintenanceModeService->getEndTime();
    }

    /**
     * DON'T USE THIS ENDPOINT UNLESS YOU ARE CLOUD API AND YOU KNOW WHAT YOU ARE DOING!
     *
     * This endpoint is designed to be used by cloud api to programatically set inhibitAllCron without going through
     * the full cloud device enable flow. If you are not cloud api, you definitely want to call the enable() function
     * above this one.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_CLOUD_MANAGED_MAINTENANCE")
     * @Datto\App\Security\RequiresFeature("FEATURE_MAINTENANCE_MODE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_MAINTENANCE_MODE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "endTime" = {
     *     @Symfony\Component\Validator\Constraints\Type(type = "int"),
     *     @Symfony\Component\Validator\Constraints\NotNull()
     *   }
     * })
     * @param int $endTime Unix timestamp of maintenance end.
     * @return int End timestamp
     */
    public function internalEnable(int $endTime): int
    {
        $user = $this->getUser();
        $userName = $user ? $user->getUserIdentifier() : null;
        $this->maintenanceModeService->enableUntilTime($endTime, $userName, true);
        return $this->maintenanceModeService->getEndTime();
    }

    /**
     * Disables the maintenance mode
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_MAINTENANCE_MODE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_MAINTENANCE_MODE")
     * @return bool
     */
    public function disable(): bool
    {
        $user = $this->getUser();
        $userName = $user ? $user->getUserIdentifier() : null;
        $this->maintenanceModeService->disable($userName);
        return true;
    }

    /**
     * DON'T USE THIS ENDPOINT UNLESS YOU ARE CLOUD API AND YOU KNOW WHAT YOU ARE DOING!
     *
     * This endpoint is designed to be used by cloud api to programatically unset inhibitAllCron without going through
     * the full cloud device disable flow. If you are not cloud api, you definitely want to call the disable() function
     * above this one.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_CLOUD_MANAGED_MAINTENANCE")
     * @Datto\App\Security\RequiresFeature("FEATURE_MAINTENANCE_MODE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_MAINTENANCE_MODE")
     * @return bool
     */
    public function internalDisable(): bool
    {
        $user = $this->getUser();
        $userName = $user ? $user->getUserIdentifier() : null;
        $this->maintenanceModeService->disable($userName, true);
        return true;
    }

    /**
     * Returns whether or not maintenance mode is currently enabled.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_MAINTENANCE_MODE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_MAINTENANCE_MODE")
     * @return bool True if maintenance mode is enabled, False otherwise
     */
    public function isEnabled(): bool
    {
        return $this->maintenanceModeService->isEnabled();
    }
}
