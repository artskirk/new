<?php
namespace Datto\Ipmi;

/**
 * Class IpmiSettings
 *
 * @author Christopher R. Wicks <cwicks@datto.com>
 */
class IpmiSettings
{
    /** @var  bool */
    private $enabled;

    /** @var  bool */
    private $isStatic;

    /** @var  string */
    private $ipAddress;

    /** @var  string */
    private $subnetMask;

    /** @var  string */
    private $gateway;

    /** @var  array[IpmiUser] */
    private $adminUsers;

    /**
     * IpmiSettings constructor.
     * @param $enabled
     * @param $isStatic
     * @param $ipAddress
     * @param $subnetMask
     * @param $gateway
     * @param $adminUsers
     */
    public function __construct(
        $enabled,
        $isStatic,
        $ipAddress,
        $subnetMask,
        $gateway,
        $adminUsers
    ) {
        $this->enabled = $enabled;
        $this->isStatic = $isStatic;
        $this->ipAddress = $ipAddress;
        $this->subnetMask = $subnetMask;
        $this->gateway = $gateway;
        $this->adminUsers = $adminUsers;
    }

    /**
     * Whether or not IPMI is enabled
     *
     * @return bool
     */
    public function isEnabled()
    {
        return $this->enabled;
    }

    /**
     * Whether or not the IPMI settings use a static IP configuration (if not, it is using DHCP)
     *
     * @return bool
     */
    public function isStatic()
    {
        return $this->isStatic;
    }

    /**
     * Get the IP Address for the IPMI LAN interface
     *
     * @return string
     */
    public function getIpAddress()
    {
        return $this->ipAddress;
    }

    /**
     * Get the subnet mask for the IPMI LAN interface
     *
     * @return string
     */
    public function getSubnetMask()
    {
        return $this->subnetMask;
    }

    /**
     * Get the gateway address for the IPMI LAN interface
     *
     * @return string
     */
    public function getGateway()
    {
        return $this->gateway;
    }

    /**
     * Get the admin users
     * @return IpmiUser[]
     */
    public function getAdminUsers()
    {
        return $this->adminUsers;
    }
}
