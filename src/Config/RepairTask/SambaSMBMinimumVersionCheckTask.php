<?php

namespace Datto\Config\RepairTask;

use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\AgentService;
use Datto\Config\DeviceConfig;
use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\Log\LoggerAwareTrait;
use Datto\Samba\SambaManager;
use Psr\Log\LoggerAwareInterface;

/**
 * Checks /etc/samba/datto-smb.conf to ensure the minimum version is 2 unless
 * shadowsnap agents exist or user explicitly set the version to 1. This will
 * also reset the version to 1 after an IBU upgrade if either of the above
 * conditions are true.
 *
 * @author Adam Marcionek <amarcionek@datto.com>
 */
class SambaSMBMinimumVersionCheckTask implements ConfigRepairTaskInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private DeviceConfig $deviceConfig;
    private SambaManager $sambaManager;
    private AgentService $agentService;

    public function __construct(
        DeviceConfig $deviceConfig,
        SambaManager $sambaManager,
        AgentService $agentService
    ) {
        $this->deviceConfig = $deviceConfig;
        $this->sambaManager = $sambaManager;
        $this->agentService = $agentService;
    }

    public function run(): bool
    {
        $intendedSmbVersion = 2;
        // Check if a user explicitly set the version to 1 so as not override them
        if ($this->deviceConfig->has(DeviceConfig::KEY_ENABLE_SMB_MINIMUM_VERSION_ONE)) {
            $intendedSmbVersion = 1;
        }

        if ($intendedSmbVersion === 2) {
            // Also check for any active shadowsnap agents which all require SMB1
            $agents = $this->agentService->getAllActiveLocal();
            foreach ($agents as $agent) {
                if ($agent->getPlatform() === AgentPlatform::SHADOWSNAP()) {
                    $intendedSmbVersion = 1;
                    break;
                }
            }
        }

        $currentMinProtocolVersion = $this->sambaManager->getServerProtocolMinimumVersion();
        if ($currentMinProtocolVersion !== $intendedSmbVersion) {
            $this->logger->debug('CFG0050 Setting SMB protocol minimum version', [
                'currentMinProtocolVersion' => $currentMinProtocolVersion,
                'intendedSmbVersion' => $intendedSmbVersion
            ]);
            if ($this->sambaManager->setServerProtocolMinimumVersion($intendedSmbVersion)) {
                $this->sambaManager->sync();
                return true;
            }
        }
        return false;
    }
}
