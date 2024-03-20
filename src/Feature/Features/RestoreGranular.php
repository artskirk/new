<?php

namespace Datto\Feature\Features;

use Datto\Billing\ServicePlanService;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Represents granular restore / Kroll feature
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class RestoreGranular extends Feature
{
    /**
     * {@inheritdoc}
     */
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::PHYSICAL,
            DeviceRole::VIRTUAL
        ];
    }

    /** @inheritdoc */
    protected function checkDeviceConstraints()
    {
        $deviceConfig = $this->context->getDeviceConfig();

        $isSnapNas = $deviceConfig->isSnapNAS();
        $plan = $this->context->getServicePlanService()->get();
        $isFree = $plan->getServicePlanName() === ServicePlanService::PLAN_TYPE_FREE;
        $ifIsAltoShouldShowKroll = true;
        if ($deviceConfig->isAlto()) {
            $hasKrollOption = $deviceConfig->get('krollOption');
            $ifIsAltoShouldShowKroll = ($deviceConfig->isAlto3() || $deviceConfig->isAlto4() || $hasKrollOption);
        }

        return !$isSnapNas && !$isFree && $ifIsAltoShouldShowKroll;
    }
}
