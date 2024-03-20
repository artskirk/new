<?php

namespace Datto\Service\CloudManagedConfig\Mappers;

use Datto\DeviceConfig\Config\DeviceConfig;
use Datto\Service\Verification\ScreenshotVerificationDeviceConfig;

/**
 * This class handles mapping the DeviceConfig DTO (used to transport configs between device and cloud) and local
 * settings.
 */
class DeviceConfigMapper
{
    private ScreenshotVerificationDeviceConfig $screenshotVerificationDeviceConfig;

    public function __construct(ScreenshotVerificationDeviceConfig $screenshotVerificationDeviceConfig)
    {
        $this->screenshotVerificationDeviceConfig = $screenshotVerificationDeviceConfig;
    }

    public function get(): DeviceConfig
    {
        return new DeviceConfig(
            $this->screenshotVerificationDeviceConfig->isEnabled()
        );
    }

    public function apply(DeviceConfig $deviceConfig): void
    {
        $this->screenshotVerificationDeviceConfig->setEnabled($deviceConfig->isScreenshotVerificationEnabled());
    }
}
