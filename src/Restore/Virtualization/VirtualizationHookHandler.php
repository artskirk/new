<?php

namespace Datto\Restore\Virtualization;

use Datto\Log\LoggerAwareTrait;
use Datto\Restore\RestoreService;
use Datto\Restore\RestoreType;
use Datto\Utility\Systemd\Systemctl;
use Datto\Utility\Systemd\SystemdRunningStatus;
use Psr\Log\LoggerAwareInterface;

/**
 * Service to handle libvirt hook calls.
 *
 * @author Santiago Norena <santiago.norena@kaseya.com>
 */
class VirtualizationHookHandler implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private RestoreService $restoreService;
    private Systemctl $systemctl;
    private VirtualizationRestoreTool $virtRestoreTool;

    public function __construct(
        RestoreService $restoreService,
        Systemctl $systemctl,
        VirtualizationRestoreTool $virtRestoreTool
    ) {
        $this->restoreService = $restoreService;
        $this->systemctl = $systemctl;
        $this->virtRestoreTool = $virtRestoreTool;
    }

    public function onHookReceive(string $guestName, string $vmState): void
    {
        $guestNameComponents = explode('-', $guestName);
        $suffix = array_pop($guestNameComponents);
        $agentKey = array_pop($guestNameComponents);

        if ($vmState !== 'stopped') {
            return;
        }

        if (!in_array($suffix, [RestoreType::ACTIVE_VIRT, RestoreType::RESCUE])) {
            return;
        }

        if (!$this->isDeviceRunning() || !$this->isVmRunning($agentKey, $suffix)) {
            return;
        }

        $this->logger->debug('VHH0001 Virtualization Shutdown Observed', ['agentKey' => $agentKey]);
        $this->virtRestoreTool->updateRestorePowerState($agentKey, $suffix, false);
    }
    
    private function isDeviceRunning(): bool
    {
        $currentSystemState = $this->systemctl->isSystemRunning();
        return $currentSystemState === SystemdRunningStatus::RUNNING() ||
            $currentSystemState === SystemdRunningStatus::DEGRADED();
    }

    private function isVmRunning(string $assetKey, string $suffix): bool
    {
        $restore = $this->restoreService->findMostRecent($assetKey, $suffix);
        if ($restore && $restore->virtualizationIsRunning()) {
            return true;
        }
        return false;
    }
}
