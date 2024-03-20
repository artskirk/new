<?php

namespace Datto\Feature\Features;

use Datto\Asset\Agent\Agent;
use Datto\Asset\AssetType;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines if the device supports file system integrity verification,
 * or if it has been disabled by support.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class FilesystemIntegrityCheck extends Feature
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
     * {@inheritdoc}
     */
    protected function checkAssetConstraints()
    {
        $asset = $this->context->getAsset();
        $isSupportedAsset = !$asset->getOriginDevice()->isReplicated() && !$asset->isType(AssetType::SHARE);
        $isSupportedOperatingSystem = $asset instanceof Agent && $asset->isSupportedOperatingSystem();
        $isSupported = $isSupportedAsset && $isSupportedOperatingSystem;

        return $isSupported;
    }

    /**
     * {@inheritdoc}
     */
    protected function checkDeviceConstraints()
    {
        $deviceConfig = $this->context->getDeviceConfig();

        return !$deviceConfig->isSnapNAS()
            && !$deviceConfig->has('disableFilesystemIntegrityVerification');
    }
}
