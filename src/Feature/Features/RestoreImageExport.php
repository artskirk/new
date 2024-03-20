<?php

namespace Datto\Feature\Features;

use Datto\Asset\Agent\Agent;
use Datto\Asset\AssetType;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines if the device supports image exports.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class RestoreImageExport extends Feature
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
    public function checkDeviceConstraints()
    {
        $deviceConfig = $this->context->getDeviceConfig();

        return !$deviceConfig->isSnapNAS();
    }

    /**
     * {@inheritdoc}
     */
    protected function checkAssetConstraints()
    {
        /* @var Agent $agent */
        $agent = $this->context->getAsset();
        $isShare = $agent->isType(AssetType::SHARE);
        $isMacAgent = $agent->isType(AssetType::MAC_AGENT);
        $supported = !$isShare && !$isMacAgent;

        return $supported;
    }
}
