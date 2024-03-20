<?php

declare(strict_types=1);

namespace Datto\App\Controller\Api\V1\Device\Settings;

use Datto\Service\Device\ClfService;

/**
 * Api endpoint for enabling/disabling CLF
 */
class Clf
{
    private ClfService $clfService;

    public function __construct(
        ClfService $clfService
    ) {
        $this->clfService = $clfService;
    }

    /**
     * Turns on CLF UI
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_DEVICE_SETTINGS_WRITE")
     */
    public function enable(): void
    {
        $this->clfService->toggleClf(true);
    }

    /**
     * Turns off CLF UI
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_DEVICE_SETTINGS_WRITE")
     */
    public function disable(): void
    {
        $this->clfService->toggleClf(false);
    }
}
