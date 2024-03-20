<?php

namespace Datto\Service\Verification;

use Datto\Config\DeviceConfig;

class ScreenshotVerificationDeviceConfig
{
    private DeviceConfig $deviceConfig;

    public function __construct(DeviceConfig $deviceConfig)
    {
        $this->deviceConfig = $deviceConfig;
    }

    public function setEnabled(bool $enabled): void
    {
        $settingValue = $enabled ? 0 : 1;
        $this->deviceConfig->set('disableScreenshots', $settingValue);
    }

    public function isEnabled(): bool
    {
        // A 0 in the disableScreenshots file will delete the file. A 1 means that enabled is false.
        $fileExists = $this->deviceConfig->has('disableScreenshots');
        $isEnabled = !$fileExists;

        return $isEnabled;
    }
}
