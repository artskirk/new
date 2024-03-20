<?php

namespace Datto\Websockify;

use Datto\Restore\RestoreService;
use Datto\Restore\RestoreType;
use Datto\Restore\Virtualization\AgentVmManager;
use Exception;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * Translates a VNC port to a websockify token that proxies to it.
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class WebsockifyPortToTokenService
{
    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var RestoreService */
    private $restoreService;

    /** @var AgentVmManager */
    private $agentVmManager;

    /**
     * @param DeviceLoggerInterface $logger
     * @param RestoreService $restoreService
     * @param AgentVmManager $agentVmManager
     */
    public function __construct(
        DeviceLoggerInterface $logger,
        RestoreService $restoreService,
        AgentVmManager $agentVmManager
    ) {
        $this->logger = $logger;
        $this->restoreService = $restoreService;
        $this->agentVmManager = $agentVmManager;
    }

    /**
     * Returns the websockify token bound to the VNC .
     *
     * @param int $port
     * @return string
     */
    public function getToken(int $port): string
    {
        $virtualMachineSuffixes = [RestoreType::ACTIVE_VIRT, RestoreType::RESCUE];

        // Search for virt with VNC on this port
        foreach ($this->restoreService->getAll() as $restore) {
            try {
                if (in_array($restore->getSuffix(), $virtualMachineSuffixes, true)) {
                    $assetKey = $restore->getAssetKey();

                    // Short circuit so that we don't attempt to check vnc details if vm is off
                    $isRunning = $this->agentVmManager->isVmRunning($assetKey);
                    if ($isRunning && $this->agentVmManager->getVncConnectionDetails($assetKey)['port'] === $port) {
                        // Create the target file if it isn't already there
                        return $this->agentVmManager->createRemoteConsoleTarget($assetKey)->getValues()["token"];
                    }
                }
            } catch (Throwable $t) {
                $this->logger->error(
                    'WPT0001 Error retrieving token through libvirt',
                    ['restoreCloneName' => $restore->getCloneName(), 'exception' => $t]
                );
            }
        }

        throw new Exception('No virtual machine with VNC connection on that port.');
    }
}
