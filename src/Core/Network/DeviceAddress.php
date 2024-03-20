<?php

namespace Datto\Core\Network;

use Datto\Config\DeviceConfig;
use Datto\Config\ShmConfig;
use Datto\Log\LoggerAwareTrait;
use Datto\Utility\Azure\InstanceMetadata;
use Datto\Utility\Network\DnsLookup;
use Datto\Utility\Network\IpAddress;
use Datto\Utility\Network\IpHelper;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Provides mechanisms to retrieve the IP address(es) in use by the device. Note that this access is intentionally
 * "read-only". Any modification of the device network configuration should be done elsewhere.
 */
class DeviceAddress implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const AZURE_CACHED_IP = 'azureIp';

    private DeviceConfig $config;
    private ShmConfig $shmConfig;
    private InstanceMetadata $instanceMetadata;
    private IpHelper $ipHelper;
    private DnsLookup $dnsLookup;

    public function __construct(
        DeviceConfig $config,
        ShmConfig $shmConfig,
        InstanceMetadata $instanceMetadata,
        IpHelper $ipHelper,
        DnsLookup $dnsLookup
    ) {
        $this->config = $config;
        $this->shmConfig = $shmConfig;
        $this->instanceMetadata = $instanceMetadata;
        $this->ipHelper = $ipHelper;
        $this->dnsLookup = $dnsLookup;
    }

    /**
     * Gets this device's IP address. Since devices can have multiple IP addresses, this function takes an
     * optional parameter, which is the hostname or ip of a remote host. If provided, this will return the device
     * IP that is most likely to be routable by that host. If omitted, this will return the IP address of the
     * interface with the lowest route metric, or the Azure WAN IP if this is an Azure device. In the event that
     * none of these mechanisms turn up a valid IP, this will return an empty string.
     *
     * @param string $forHost The hostname or IP address of a remote host.
     * @return string The IP Address of this device,
     */
    public function getLocalIp(string $forHost = ''): string
    {
        $ip = $this->getDeviceIpForHost($forHost) ??
            $this->getAzurePublicIp() ??
            $this->getDefaultRouteIp();

        return $ip ? $ip->getAddr() : '';
    }

    /**
     * Returns the IP addresses this device is accessible from.
     *
     * @return string[]
     */
    public function getActiveIpAddresses(): array
    {
        $ips = [];
        foreach ($this->ipHelper->getAllIps() as $ip) {
            if (!preg_match('/^lo$|^virbr\d+$/', $ip->getLabel())) {
                $ips[] = $ip;
            }
        }
        return array_map(fn($ip) => $ip->getAddr(), $ips);
    }

    /**
     * Returns an IP address for this device that is likely to be routable by the remote host. This uses the source
     * interface for our route to a particular host, with the assumption that the route is symmetric, and the host
     * will be able to respond back to us with that interface's IP.
     *
     * @param string $host The remote host
     * @return IpAddress|null The IP Address of the device from that host's perspective
     */
    private function getDeviceIpForHost(string $host): ?IpAddress
    {
        if ($host) {
            // If the host isn't an IP, try a DNS lookup of it
            $remoteIp = IpAddress::fromAddr($host) ?? $this->dnsLookup->lookup($host);

            // If we have a valid IP, query the system routing table for the route to it
            if ($remoteIp) {
                $route = $this->ipHelper->getRouteTo($remoteIp);
                if ($route && $route->getSource()) {
                    return $route->getSource();
                }
            }
        }

        return null;
    }

    /**
     * Returns the public-facing Azure IP Address from IMDS. We can cache this for quicker lookup, since it should not
     * change while the device is up and running, only on reboot. If this is not an Azure device, or if the IMDS
     * does not contain an IPv4 address, this will return null.
     *
     * @return IpAddress|null The Azure public IP from IMDS
     */
    private function getAzurePublicIp(): ?IpAddress
    {
        if ($this->config->isAzureDevice()) {
            try {
                // Attempt to read and return the IP from the cache, rather than curling out to the IMDS service
                if ($this->shmConfig->has(self::AZURE_CACHED_IP)) {
                    return IpAddress::fromAddr($this->shmConfig->get(self::AZURE_CACHED_IP, ''));
                }

                // Read the interfaces from the IMDS endpoint, and store the result in a locally-cached file.
                $interfaces = $this->instanceMetadata->getInterfaces();
                $publicIp = $interfaces[0]['ipv4']['ipAddress'][0]['publicIpAddress'] ?? '';
                $ip = IpAddress::fromAddr($publicIp);
                $this->shmConfig->set(self::AZURE_CACHED_IP, $ip ? $ip->getAddr() : 'none');

                // Return the IP address, or null if we didn't pull one successfully from IMDS
                return $ip;
            } catch (Throwable $e) {
                // An empty IP will not generate an exception, but an IMDS failure will.
                $this->logger->warning('DIS0001 Error reading public IP from IMDS', ['exception' => $e]);
            }
        }
        return null;
    }

    /**
     * Returns the IP address of the default route (the route with the lowest route metric to an external IP address).
     * In the event that none of the device interfaces have a global/default route, this will return null.
     *
     * @return IpAddress|null The IP address of the interface with the best route metric to an external host
     */
    private function getDefaultRouteIp(): ?IpAddress
    {
        $route = $this->ipHelper->getRouteTo(IpAddress::fromAddr('1.0.0.0'));
        if ($route && $route->getSource()) {
            return $route->getSource();
        }
        return null;
    }
}
