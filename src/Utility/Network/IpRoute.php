<?php

namespace Datto\Utility\Network;

/**
 * A wrapper around a single network route, providing access to source, destination, gateway, etc...
 *
 * @author Geoff Amey <gamey@datto.com>
 */
class IpRoute
{
    /** @var array Route information, as returned by a call to `ip -json route get` or `ip -json route show` */
    private array $route;

    public function __construct(array $route)
    {
        $this->route = $route;
    }

    /**
     * Get the destination IP address for this route
     * @return IpAddress
     */
    public function getDestination(): IpAddress
    {
        if ($this->isDefault()) {
            return IpAddress::fromCidr('0.0.0.0/0');
        }
        return IpAddress::fromCidr($this->route['dst']);
    }

    /**
     * Determine whether or not this is a local route to an on-system destination
     * @return bool
     */
    public function isLocal(): bool
    {
        return ($this->route['type'] ?? '') === 'local';
    }

    /**
     * Determine whether this route is a default route
     * @return bool
     */
    public function isDefault(): bool
    {
        return ($this->route['dst'] === 'default');
    }

    /**
     * Get the name of the interface/device that this route will use
     * @return string
     */
    public function getInterface(): string
    {
        return $this->route['dev'];
    }

    /**
     * Return the gateway (next hop) for this route, if this is not a direct route (null if it is)
     * @return IpAddress|null
     */
    public function getGateway(): ?IpAddress
    {
        return IpAddress::fromAddr($this->route['gateway'] ?? '');
    }

    /**
     * Get the source IP for this route, if one exists
     * @return IpAddress|null
     */
    public function getSource(): ?IpAddress
    {
        return IpAddress::fromAddr($this->route['prefsrc'] ?? '');
    }

    /**
     * Get the route metric for this route, if one exists
     * @return int|null
     */
    public function getMetric(): ?int
    {
        return $this->route['metric'] ?? null;
    }
}
