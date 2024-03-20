<?php

namespace Datto\Billing;

/**
 * Represents a device's Service Plan.
 * @package Datto\Billing
 */
class ServicePlan
{
    /** @var string */
    private $servicePlanName;

    /** @var string */
    private $servicePlanShortCode;

    /** @var string */
    private $servicePlanDescription;

    /** @var int */
    private $servicePlanQuotaBytes;

    public function __construct(
        string $servicePlanName = '',
        string $servicePlanShortCode = '',
        string $servicePlanDescription = '',
        int $servicePlanQuota = 0
    ) {
        $this->servicePlanName = $servicePlanName;
        $this->servicePlanShortCode = $servicePlanShortCode;
        $this->servicePlanDescription = $servicePlanDescription;
        $this->servicePlanQuotaBytes = $servicePlanQuota;
    }

    public function getServicePlanName(): string
    {
        return $this->servicePlanName;
    }

    public function getServicePlanShortCode(): string
    {
        return $this->servicePlanShortCode;
    }

    public function getServicePlanDescription(): string
    {
        return $this->servicePlanDescription;
    }

    public function getServicePlanQuotaBytes(): int
    {
        return $this->servicePlanQuotaBytes;
    }
}
