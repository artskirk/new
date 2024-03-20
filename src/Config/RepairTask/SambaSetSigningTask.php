<?php

namespace Datto\Config\RepairTask;

use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\AgentService;
use Datto\Config\DeviceConfig;
use Datto\CookieCutter\Security\User\EndUser\Device;
use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\Log\LoggerAwareTrait;
use Datto\Samba\SambaManager;
use Psr\Log\LoggerAwareInterface;

/**
 * Sets whether SMB signing should be required.
 */
class SambaSetSigningTask implements ConfigRepairTaskInterface, LoggerAwareInterface
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

    /**
     * @inheritDoc
     */
    public function run(): bool
    {
        $setSigningRequired = true;
        if ($this->deviceConfig->has(DeviceConfig::KEY_SMB_SIGNING_REQUIRED)) {
            if ($this->deviceConfig->getRaw(DeviceConfig::KEY_SMB_SIGNING_REQUIRED) !== "1") {
                // The partner has explicitly set signing as not required.
                $setSigningRequired = false;
            }
        } else {
            // If the partner has not explicitly specified this setting, we will set it to required as long as there
            // aren't any ShadowSnap agents. Requiring SMB signing can cause issues with ShadowSnap.
            $agents = $this->agentService->getAllActiveLocal();
            foreach ($agents as $agent) {
                if ($agent->getPlatform() === AgentPlatform::SHADOWSNAP()) {
                    $setSigningRequired = false;
                    break;
                }
            }
        }

        $wasPreviouslyRequired = $this->sambaManager->isSigningRequired();
        if ($wasPreviouslyRequired !== $setSigningRequired) {
            $this->sambaManager->updateSigningRequired($setSigningRequired);
            if ($this->sambaManager->sync()) {
                $this->logger->info('CFG0060 Set SMB signing level', [
                    'wasPreviouslyRequired' => $wasPreviouslyRequired,
                    'isNowRequired' => $setSigningRequired
                ]);
                return true;
            }
        }
        return false;
    }
}
