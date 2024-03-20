<?php

namespace Datto\App\Controller\Api\V1\Device\System;

use Datto\Upgrade\UpgradeService;

/**
 * Endpoint for device upgrades
 *
 * @author Michael Meyer <mmeyer@datto.com>
 */
class Upgrades
{
    /** @var UpgradeService */
    private $upgradeService;

    public function __construct(UpgradeService $upgradeService)
    {
        $this->upgradeService = $upgradeService;
    }

    /**
     * Upgrades device to the latest available image.
     *
     * FIXME We should combine v1/device/settings/{upgradeChannel,updateWindow} and v1/device/system/upgrade
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_UPGRADES")
     * @Datto\App\Security\RequiresPermission("PERMISSION_UPGRADES")
     * @return bool
     */
    public function upgradeToLatest(): bool
    {
        $this->upgradeService->upgradeToLatestImage();

        return true;
    }
}
