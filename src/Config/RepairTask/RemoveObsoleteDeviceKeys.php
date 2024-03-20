<?php

namespace Datto\Config\RepairTask;

use Datto\Config\DeviceConfig;
use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;

/**
 * Remove obsolete device key files
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class RemoveObsoleteDeviceKeys implements ConfigRepairTaskInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    const KEYS = [
        'motherboard',
        'disableScreenShotUI',
        'disablebmr',
        'disableRoundTrip',
        'disableSendEvents',
        'doOffsiteRetention',
        'enableLogShipping',
        'newBackupAgentless',
        'newBackupDwa',
        'newBackupLinux',
        'newBackupMac',
        'newBackupRescueAgent',
        'newBackupShadowSnap',
        'newBackupShare',
        'autoDiffMerge',
        'staleRestores',
        'isDattoUk',
        'enableDtcMultiVolume',
        'mac',
        'publicIP',
        'ipmiRotateAdminPassword',
        'diffMergeScreenshots',
        'nextNetworkCheck',
        'chkufsdDisable',
        'chkufsdFallback',
        'integrityCheckTimeoutChkufsd'
    ];

    private DeviceConfig $deviceConfig;

    public function __construct(DeviceConfig $deviceConfig)
    {
        $this->deviceConfig = $deviceConfig;
    }

    public function run(): bool
    {
        $changesOccurred = false;

        foreach (self::KEYS as $key) {
            $changesOccurred = $this->checkAndRemove($key) || $changesOccurred;
        }

        return $changesOccurred;
    }

    private function checkAndRemove(string $key): bool
    {
        if ($this->deviceConfig->has($key)) {
            $this->logger->warning("CFG0009 clearing device key", ['deviceKeyFile' => "/datto/config/$key"]);
            $this->deviceConfig->clear($key);
            return true;
        }

        return false;
    }
}
