<?php

namespace Datto\Asset\Agent\Windows;

use Datto\Asset\Asset;
use Datto\Asset\AssetType;

/**
 * Handles the creation of WindowsService objects.
 *
 * @author Mario Rial <mrial@datto.com>
 * @author David Desorcie <ddesorcie@datto.com>
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class WindowsServiceFactory
{
    const NET_START_PATTERN = "/   (?P<displayName>.*)/";
    const GET_SERVICES_PATTERN = "/^\\s*Running\\s+(?<serviceName>\\S+)\\s.*$/";

    /**
     * Create a WindowsService object given only the service ID.
     *
     * @param string $serviceId
     * @param Asset $agent Agent to which this service belongs
     * @return WindowsService
     */
    public function createFromServiceId(string $serviceId, Asset $agent): WindowsService
    {
        if ($agent->isType(AssetType::WINDOWS_AGENT)) {
            // For Windows agents, display name = service ID
            return new WindowsService($serviceId, null);
        } else {
            // For agentless systems, service name = service ID
            return new WindowsService(null, $serviceId);
        }
    }

    /**
     * De-serializes net start command output to an array of WindowsService instances.
     *
     * @param string $netStartOutput
     * @return WindowsService[] List of services indexed by windows service ID
     */
    public function createFromNetStart(string $netStartOutput): array
    {
        return $this->createFromCommandLineOutput($netStartOutput, self::NET_START_PATTERN);
    }

    /**
     * De-serializes get-services command output to an array of WindowsService instances (running serives only)
     *
     * @param string $getServicesOutput
     * @return WindowsService[]
     */
    public function createFromGetServices(string $getServicesOutput): array
    {
        return $this->createFromCommandLineOutput($getServicesOutput, self::GET_SERVICES_PATTERN);
    }

    /**
     * @param string $cliOutput
     * @param string $pattern regex to use when searching for service IDs
     * @return WindowsService[]
     */
    private function createFromCommandLineOutput(string $cliOutput, string $pattern): array
    {
        $windowsServices = [];

        foreach (explode("\n", $cliOutput) as $cliLine) {
            $windowsService = $this->parseLine($cliLine, $pattern);

            if ($windowsService) {
                $windowsServices[$windowsService->getId()] = $windowsService;
            }
        }

        return $windowsServices;
    }

    /**
     * @param string $line
     * @param string $pattern
     * @return bool|WindowsService
     */
    private function parseLine(string $line, string $pattern)
    {
        $match = [];

        if (preg_match($pattern, $line, $match) !== 1) {
            return false;
        }

        $displayName = isset($match['displayName']) ? trim($match['displayName']) : null;
        $serviceName = isset($match['serviceName']) ? trim($match['serviceName']) : null;

        return new WindowsService($displayName, $serviceName);
    }
}
