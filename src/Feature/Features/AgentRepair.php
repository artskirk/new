<?php

namespace Datto\Feature\Features;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentPlatform;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines if the device and the agent supports repairing
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class AgentRepair extends Feature
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

    protected function checkDeviceConstraints()
    {
        $deviceConfig = $this->context->getDeviceConfig();
        $billingService = $this->context->getBillingService();

        return !$deviceConfig->isSnapNAS() &&
            !$billingService->isOutOfService();
    }

    protected function checkAssetConstraints()
    {
        $asset = $this->context->getAsset();
        if (!isset($asset)) {
            return true;
        }

        $unsupported = $asset->getOriginDevice()->isReplicated() ||
            $asset->getLocal()->isArchived() ||
            !($asset instanceof Agent) ||
            $asset->isRescueAgent() ||
            $asset->getPlatform() === AgentPlatform::DIRECT_TO_CLOUD();

        return !$unsupported;
    }
}
