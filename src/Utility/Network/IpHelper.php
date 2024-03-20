<?php

namespace Datto\Utility\Network;

use Datto\Common\Resource\ProcessFactory;
use Throwable;

/**
 * Primary entry point for interacting with the `ip` utility, allowing a dependency-injectable class for
 * interacting with network interfaces, routes, neighbors, bridges, and more.
 *
 * @package Datto\Utility\Network
 */
class IpHelper
{
    private ProcessFactory $processFactory;

    public function __construct(ProcessFactory $processFactory)
    {
        $this->processFactory = $processFactory;
    }

    /**
     * Get every IP Address currently in use by the system
     *
     * @return IpAddress[]
     */
    public function getAllIps(): array
    {
        $output = $this->processFactory
            ->get(['ip', '-json', 'address', 'show'])
            ->mustRun()
            ->getOutput();

        $ips = [];
        foreach (json_decode($output, true) as $iface) {
            foreach ($iface['addr_info'] as $info) {
                $ip = IpAddress::fromAddr($info['local'], $info['prefixlen'], $info['label'] ?? $iface['ifname']);
                if ($ip) {
                    $ips[] = $ip;
                }
            }
        }
        return $ips;
    }

    /**
     * Get the names of all the interfaces currently on the system
     *
     * @return array
     */
    public function getInterfaceNames(): array
    {
        $output = $this->processFactory
            ->get(['ip', '-brief', '-json', '-all', 'address', 'show'])
            ->mustRun()
            ->getOutput();

        return array_map(fn($j) => $j['ifname'], json_decode($output, true));
    }

    /**
     * Get a single network interface object from its name
     *
     * @param string $interfaceName
     * @return IpInterface|null
     */
    public function getInterface(string $interfaceName): ?IpInterface
    {
        try {
            return new IpInterface($interfaceName, $this->processFactory);
        } catch (Throwable $t) {
            return null;
        }
    }

    /**
     * Get all the routes on the system
     *
     * @return IpRoute[]
     */
    public function getRoutes(): array
    {
        $routes = [];
        try {
            $output = $this->processFactory
                ->get(['ip', '-json', 'route', 'list'])
                ->mustRun()
                ->getOutput();

            $jsonRoutes = json_decode($output, true);
            foreach ($jsonRoutes as $jsonRoute) {
                $routes[] = new IpRoute($jsonRoute);
            }
        } catch (Throwable $throwable) {
            return [];
        }
        return $routes;
    }

    /**
     * Return the route that the system will use to get to a specific address
     *
     * @param IpAddress $ip IPv4 Address to determine the route to
     * @return IpRoute|null
     */
    public function getRouteTo(IpAddress $ip): ?IpRoute
    {
        try {
            $output = $this->processFactory
                ->get(['ip', '-json', 'route', 'get', $ip->getAddr()])
                ->mustRun()
                ->getOutput();

            $jsonRoutes = json_decode($output, true);
            if (count($jsonRoutes) !== 1) {
                return null;
            }
            return new IpRoute(array_shift($jsonRoutes));
        } catch (Throwable $throwable) {
            return null;
        }
    }
}
