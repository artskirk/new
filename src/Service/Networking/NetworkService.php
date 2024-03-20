<?php

namespace Datto\Service\Networking;

use Datto\Config\LocalConfig;
use Datto\Core\Network\WindowsDomain;
use Datto\Log\LoggerAwareTrait;
use Datto\Common\Utility\Filesystem;
use Datto\Utility\Network\Hostname;
use Datto\Utility\Network\IpAddress;
use Datto\Utility\Network\IpHelper;
use Datto\Utility\Network\Nmap;
use Datto\Utility\Systemd\Resolvectl;
use Exception;
use RuntimeException;
use Psr\Log\LoggerAwareInterface;

/**
 * The primary API for system network configuration and status. Provides an abstraction layer for the rest of the
 * system, such that complex networking operations requiring interaction between multiple services, files, and
 * applications can be performed from a single place.
 */
class NetworkService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const MASK_NET_SPEED = '/sys/class/net/%s/speed';

    private LocalConfig $localConfig;
    private Filesystem $filesystem;
    private IpHelper $ipHelper;
    private WindowsDomain $windowsDomain;
    private Hostname $hostname;
    private Nmap $nmap;
    private Resolvectl $resolvectl;
    private ConnectivityService $connectivityService;

    public function __construct(
        LocalConfig $localConfig,
        Filesystem $filesystem,
        IpHelper $ipHelper,
        WindowsDomain $windowsDomain,
        Hostname $hostname,
        Nmap $nmap,
        Resolvectl $resolvectl,
        ConnectivityService $connectivityService
    ) {
        $this->localConfig = $localConfig;
        $this->filesystem = $filesystem;
        $this->ipHelper = $ipHelper;
        $this->windowsDomain = $windowsDomain;
        $this->hostname = $hostname;
        $this->nmap = $nmap;
        $this->resolvectl = $resolvectl;
        $this->connectivityService = $connectivityService;
    }

    /**
     * Returns the names of physical network interfaces reported by the device (e.g. Siris).
     * @todo This is only used by the BandwidthLimitService. Calls to `tc` should be moved into NetworkService instead
     *       of having a separate class responsible for iterating over the interfaces, so we can limit the bandwidth
     *       of logical Links rather than low-level linux interfaces.
     *
     * @return string[] Network Interface names of physical ethernet ports on the device
     */
    public function getPhysicalNetworkInterfaces(): array
    {
        $physicalInterfaces = [];
        $interfaceNames = $this->ipHelper->getInterfaceNames();

        foreach ($interfaceNames as $name) {
            $if = $this->ipHelper->getInterface($name);

            if ($if !== null && $if->isUp() && $if->isPhysical()) {
                $physicalInterfaces[] = $if->getName();
            }
        }

        return $physicalInterfaces;
    }

    /**
     * Updates the global nameservers for this device.
     *
     * @param string[] $nameservers
     * @param string[] $searchDomains
     */
    public function updateGlobalDns(array $nameservers, array $searchDomains): void
    {
        try {
            $this->resolvectl->setGlobalDns(
                array_filter(array_map(fn($nameserver) => IpAddress::fromAddr($nameserver), $nameservers)),
                $searchDomains
            );
        } catch (Exception $ex) {
            $this->logger->error('NSV1101 Could not update global DNS', ['exception' => $ex]);
            throw $ex;
        }
    }

    /**
     * @return array dns information with array of nameservers and search domain
     */
    public function getGlobalDns(): array
    {
        return [
            'nameservers' => array_map(fn($ip) => $ip->getAddr(), $this->resolvectl->getGlobalDnsServers()),
            'search' => $this->resolvectl->getGlobalSearchDomains()
        ];
    }

    /**
     * Gets the system hostname, as stored in /etc/hostname
     *
     * @return string
     */
    public function getHostname(): string
    {
        return $this->hostname->get();
    }

    /**
     * Gets the system short hostname (sans domain)
     *
     * @return string
     */
    public function getShortHostname(): string
    {
        return $this->hostname->getShort();
    }

    /**
     * Sets the system hostname
     *
     * @param string $hostname
     */
    public function setHostname(string $hostname): void
    {
        if ($this->windowsDomain->inDomain()) {
            throw new RuntimeException('Cannot change hostname while joined to a domain');
        }

        $this->hostname->set($hostname);
    }

    /**
     * Check if an IP Address is available to be used when configuring a static IP
     *
     * @param IpAddress $address The address to check
     * @return bool True if the IP Address is available for use
     */
    public function isIpAvailable(IpAddress $address): bool
    {
        // If the IP is already in use by this device, mark it as available. This is commonly the case
        // when switching from DHCP to static. If we mark the DHCP IP as unavailable, there's no way to convert a
        // link from dhcp->static and keep the same IP.
        foreach ($this->ipHelper->getAllIps() as $inUseIp) {
            // If the address is found on this device, mark it as being in-use
            if ($address->getAddr() === $inUseIp->getAddr()) {
                return true;
            }
        }

        // Do an nmap scan of the IP. If it shows up, it's already in use on the network and not available for us
        if ($this->nmap->pingScan($address->getAddr())) {
            return false;
        }

        // It doesn't appear that the IP Address is currently being used by anyone nearby, so mark it as available
        return true;
    }

    public function getConnectivityStatus(): array
    {
        $results = $this->connectivityService->getConnectivityState(null, true);
        $created = $results['created'];
        unset($results['created']);
        unset($results['nextCheckTime']);

        return [
            'created' => $created,
            'data' => $results
        ];
    }

    /**
     * Determine the link speed for the given interface.
     *
     * @todo This is used by the home controller to display interface speed on the device homepage, and also used
     *       by the BandwidthLimitService. Since link speeds are going to be displayed on the Network Status page, this
     *       can be removed from the homepage, and once the network bandwidth limiting with `tc` is moved into
     *       the NetworkService, there will be no need for this to exist.
     *
     * @param string $interfaceName Network interface
     * @return int Link speed in Mbit/s
     */
    public function getInterfaceSpeed(string $interfaceName): int
    {
        $speedFile = sprintf(self::MASK_NET_SPEED, $interfaceName);
        $speed = intval(@$this->filesystem->fileGetContents($speedFile));
        return ($speed > 0 ? $speed : -1);
    }
}
