<?php

namespace Datto\Feature\Features;

use Datto\Billing\ServicePlanService;
use Datto\Feature\Context;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Checks whether the device has the Local Retention configuration feature
 * available or not.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class ConfigurableLocalRetention extends Feature
{
    /** @var ServicePlanService */
    private $servicePlanService;

    /**
     * ConfigurableLocalRetention constructor.
     *
     * @param string|null $name
     * @param Context|null $context
     * @param ServicePlanService|null $servicePlanService
     */
    public function __construct(
        string $name = null,
        Context $context = null,
        ServicePlanService $servicePlanService = null
    ) {
        parent::__construct($name, $context);
        $this->servicePlanService = $servicePlanService ?: new ServicePlanService();
    }

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

    /**
     * @return bool
     */
    protected function checkDeviceConstraints()
    {
        return $this->servicePlanService->get()->getServicePlanName() !== ServicePlanService::PLAN_TYPE_FREE;
    }
}
