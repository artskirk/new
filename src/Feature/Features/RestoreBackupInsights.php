<?php

namespace Datto\Feature\Features;

use Datto\Asset\Agent\Linux\LinuxAgent;
use Datto\Asset\Agent\Mac\MacAgent;
use Datto\Asset\Agent\Agentless\Linux\LinuxAgent as LinuxAgentless;
use Datto\Config\DeviceConfig;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Represents the Backup Insights feature
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class RestoreBackupInsights extends Feature
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

    /**
     * {@inheritdoc}
     */
    public function checkDeviceConstraints(): bool
    {
        $deviceConfig = $this->context->getDeviceConfig();

        $featureFlagEnabled = $deviceConfig->get(DeviceConfig::KEY_ENABLE_BACKUP_INSIGHTS);

        return $featureFlagEnabled
            && $this->isDeviceModelSupported($deviceConfig);
    }

    private function isDeviceModelSupported(DeviceConfig $deviceConfig): bool
    {
        return !$deviceConfig->isSnapNAS() &&
            !$deviceConfig->isAlto() &&
            !$deviceConfig->isAltoXL();
    }

    /**
     * {@inheritdoc}
     */
    public function checkAssetConstraints(): bool
    {
        $agent = $this->context->getAsset();
        $isMacOrLinux = $agent instanceof LinuxAgent || $agent instanceof LinuxAgentless || $agent instanceof MacAgent;

        return !$isMacOrLinux;
    }
}
