<?php

namespace Datto\Feature\Features;

use Datto\Asset\Agent\Agent;
use Datto\Asset\AssetType;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determine if asset verifications are available.
 *
 * Note: This feature is specific to screenshot verifications, not filesystem integrity checks
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class Verifications extends Feature
{
    /**
     * {@inheritdoc}
     */
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::PHYSICAL,
            DeviceRole::VIRTUAL,
            DeviceRole::CLOUD,
            DeviceRole::AZURE
        ];
    }

    /**
     * Check whether or not the feature is supported for the device
     *
     * @return bool
     */
    protected function checkDeviceConstraints()
    {
        $deviceConfig = $this->context->getDeviceConfig();

        $isNonSupportedModel = ($deviceConfig->isSirisLite() && !$deviceConfig->isAltoXL())
            || $deviceConfig->isSnapNAS();

        $supported = !$isNonSupportedModel;

        return $supported;
    }

    /**
     * Check whether or not the feature is supported for the asset
     *
     * @return bool
     */
    protected function checkAssetConstraints()
    {
        try {
            $asset = $this->context->getAsset();
            if (!isset($asset)) {
                return true;
            }

            $isReplicated = $asset->getOriginDevice()->isReplicated();
            if ($isReplicated) {
                return false;
            }

            $isAgent = $asset->isType(AssetType::AGENT);
            if (!$isAgent) {
                return false;
            }

            /** @var Agent $asset*/
            if (!$asset->isSupportedOperatingSystem()) {
                return false;
            }

            $isMac = $asset->isType(AssetType::MAC_AGENT);
            if ($isMac) {
                return false;
            }

            $archiveService = $this->context->getArchiveService();
            if ($archiveService->isArchived($asset->getKeyName())) {
                return false;
            }

            /** @var Agent $asset */
            return $asset->getScreenshot()->isSupported();
        } catch (\Throwable $e) {
            // Unknown asset type.
            return false;
        }
    }
}
