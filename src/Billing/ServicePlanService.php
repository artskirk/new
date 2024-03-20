<?php

namespace Datto\Billing;

use Datto\Config\DeviceConfig;

/**
 * Represents the service plan of the device
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class ServicePlanService
{
    const PLAN_TYPE_FREE = 'free';
    const PLAN_TYPE_PEER_TO_PEER = 'peertopeer';

    const SERVICE_PLAN_NAME = 'servicePlanName';
    const SERVICE_PLAN_SHORT_CODE = 'servicePlanShortCode';
    const SERVICE_PLAN_DESCRIPTION = 'servicePlanDescription';
    const SERVICE_PLAN_QUOTA_BYTES = 'capacity';

    /** @var DeviceConfig */
    private $deviceConfig;

    /**
     * @param DeviceConfig $deviceConfig
     */
    public function __construct(DeviceConfig $deviceConfig = null)
    {
        $this->deviceConfig = $deviceConfig ?: new DeviceConfig();
    }

    /**
     * Get the device's service plan
     * @return ServicePlan
     */
    public function get()
    {
        $servicePlanArray = json_decode($this->deviceConfig->get(DeviceConfig::KEY_SERVICE_PLAN_INFO), true);
        return new ServicePlan(
            $servicePlanArray[self::SERVICE_PLAN_NAME] ?? '',
            $servicePlanArray[self::SERVICE_PLAN_SHORT_CODE] ?? '',
            $servicePlanArray[self::SERVICE_PLAN_DESCRIPTION] ?? '',
            // if billing doesn't run in time, clients of this code may assert. Defaulting it to 0 prevents that
            $servicePlanArray[self::SERVICE_PLAN_QUOTA_BYTES] ?? 0
        );
    }

    /**
     * Save the device's service plan
     * @param ServicePlan $servicePlan
     */
    public function save(ServicePlan $servicePlan)
    {
        $servicePlanArray = array(
            self::SERVICE_PLAN_NAME => $servicePlan->getServicePlanName(),
            self::SERVICE_PLAN_SHORT_CODE => $servicePlan->getServicePlanShortCode(),
            self::SERVICE_PLAN_DESCRIPTION => $servicePlan->getServicePlanDescription(),
            self::SERVICE_PLAN_QUOTA_BYTES => $servicePlan->getServicePlanQuotaBytes()
        );

        $this->deviceConfig->set(DeviceConfig::KEY_SERVICE_PLAN_INFO, json_encode($servicePlanArray));
    }
}
