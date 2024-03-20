<?php

namespace Datto\Asset\Agent\Windows;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\RemoteCommandService;
use Datto\Asset\Agent\Windows\Serializer\WindowsServicesSerializer;
use Datto\Common\Utility\Filesystem;
use Exception;

/**
 * Services that retrieves services that are running currently in the Windows Agent.
 *
 * @author Mario Rial <mrial@datto.com>
 * @author Stephen Allan <sallan@datto.com>
 */
class WindowsServiceRetriever
{
    const SERVICES_PATH_FORMAT = Agent::KEYBASE . '%s.services';

    /** @var RemoteCommandService */
    private $remoteCommandService;

    /** @var WindowsServiceFactory */
    private $windowsServiceFactory;

    /** @var WindowsServicesSerializer */
    private $windowsServiceSerializer;

    /** @var Filesystem */
    private $filesystem;

    /** @var AgentService */
    private $agentService;

    /** @var WindowsRegistryServiceRetriever */
    private $windowsRegistryServiceRetriever;

    /**
     * @param RemoteCommandService $remoteCommandService
     * @param WindowsServiceFactory $windowsServiceFactory
     * @param WindowsServicesSerializer $windowsServiceSerializer
     * @param Filesystem $filesystem
     * @param AgentService $agentService
     * @param WindowsRegistryServiceRetriever $windowsRegistryServiceRetriever
     */
    public function __construct(
        RemoteCommandService $remoteCommandService,
        WindowsServiceFactory $windowsServiceFactory,
        WindowsServicesSerializer $windowsServiceSerializer,
        Filesystem $filesystem,
        AgentService $agentService,
        WindowsRegistryServiceRetriever $windowsRegistryServiceRetriever
    ) {
        $this->remoteCommandService = $remoteCommandService;
        $this->windowsServiceFactory = $windowsServiceFactory;
        $this->windowsServiceSerializer = $windowsServiceSerializer;
        $this->filesystem = $filesystem;
        $this->agentService = $agentService;
        $this->windowsRegistryServiceRetriever = $windowsRegistryServiceRetriever;
    }

    /**
     * Determines if a backup is required before the running service list can
     * be obtained.
     *
     * @param string $agentKey
     * @return bool
     */
    public function isBackupRequired(string $agentKey): bool
    {
        $agent = $this->agentService->get($agentKey);
        return $agent->getPlatform()->isAgentless() &&
            $agent->getLocal()->getRecoveryPoints()->getLast() === null;
    }

    /**
     * Get cached running services.
     *
     * @param string $agentKey
     * @return WindowsService[] List of services indexed by windows service ID
     */
    public function getCachedRunningServices(string $agentKey): array
    {
        $servicesCacheFile = sprintf(self::SERVICES_PATH_FORMAT, $agentKey);

        if (!$this->filesystem->exists($servicesCacheFile)) {
            return [];
        }

        $rawContent = $this->filesystem->fileGetContents($servicesCacheFile);
        if ($rawContent === false) {
            return [];
        }

        $loadedServices = $this->windowsServiceSerializer->unserialize(json_decode($rawContent, true));

        // Note: the serializer does not properly preserve the array keys,
        // so we have to re-index it here.
        $indexedServices = [];
        foreach ($loadedServices as $service) {
            $indexedServices[$service->getId()] = $service;
        }

        return $indexedServices;
    }

    /**
     * Refreshes the running services cache and returns the updated running services.
     *
     * @param string $agentKey
     * @return WindowsService[] List of services indexed by windows service ID
     */
    public function refreshCachedRunningServices(string $agentKey): array
    {
        $runningServices = $this->retrieveRunningServices($agentKey);
        $this->saveWindowsServices($agentKey, $runningServices);

        return $runningServices;
    }

    /**
     * Retrieves currently running services in the agent side.
     *
     * @param string $agentKey
     * @return WindowsService[] List of services indexed by windows service ID
     */
    private function retrieveRunningServices(string $agentKey): array
    {
        $agent = $this->agentService->get($agentKey);
        if ($agent->getPlatform()->isAgentless()) {
            return $this->getServicesFromLastBackup($agent);
        } else {
            return $this->getServicesFromAgent($agent);
        }
    }

    /**
     * @param string $agentKey
     * @param WindowsService[] $windowsServices List of services indexed by windows service ID
     */
    private function saveWindowsServices(string $agentKey, array $windowsServices): void
    {
        $jsonOutput = json_encode($this->windowsServiceSerializer->serialize($windowsServices), JSON_PRETTY_PRINT);

        if ($this->filesystem->filePutContents(sprintf(self::SERVICES_PATH_FORMAT, $agentKey), $jsonOutput) === false) {
            throw new Exception("Error saving windows services for agent: $agentKey");
        }
    }

    /**
     * Get a list of running services from the remote agent.
     *
     * @param Agent $agent
     * @return WindowsService[] List of services indexed by windows service ID
     */
    private function getServicesFromAgent(Agent $agent): array
    {
        $netStartOutput = $this->remoteCommandService
            ->runCommand($agent->getKeyName(), "net", ["start"])
            ->getOutput();
        $runningServices = $this->windowsServiceFactory->createFromNetStart($netStartOutput);

        if (empty($runningServices)) {
            throw new Exception('No running services detected on the agent');
        }

        return $runningServices;
    }

    /**
     * Inspect the agent's last backup to determine a list of services.
     *
     * @param Agent $agent
     * @return WindowsService[] List of services indexed by windows service ID
     */
    private function getServicesFromLastBackup(Agent $agent): array
    {
        $runningServices = [];
        $lastRecoveryPoint = $agent->getLocal()->getRecoveryPoints()->getLast(); // Will be null if no backups exist

        if (!is_null($lastRecoveryPoint)) {
            $runningServices = $this->windowsRegistryServiceRetriever->retrieveRunningServices(
                $agent,
                $lastRecoveryPoint->getEpoch()
            );
        }

        return $runningServices;
    }
}
