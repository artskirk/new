<?php


namespace Datto\Feature\Features;

use Datto\Config\DeviceConfig;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;
use Datto\Utility\ByteUnit;

class RoundtripNas extends Feature
{
    const RT_ENABLE_THRESHOLD_TIB = 10;

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

    protected function checkDeviceConstraints(): bool
    {
        $deviceConfig = $this->context->getDeviceConfig();
        $featureFlagEnabled = $deviceConfig->get(DeviceConfig::KEY_ENABLE_RT_NG_NAS);

        $deviceServicePlanService = $this->context->getServicePlanService();
        $quotaInBytes = $deviceServicePlanService->get()->getServicePlanQuotaBytes();
        $quotaInTib = ByteUnit::BYTE()->convertTo(ByteUnit::TIB(), $quotaInBytes);
        $doesQuotaPassThreshold = $quotaInTib >= self::RT_ENABLE_THRESHOLD_TIB;

        return $featureFlagEnabled || $doesQuotaPassThreshold;
    }
}
