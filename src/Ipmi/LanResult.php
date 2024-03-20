<?php

namespace Datto\Ipmi;

/**
 * Data class that encapsulates the result of 'ipmitool lan print x'.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class LanResult
{
    /** @var string */
    private $ipAddress;

    /** @var string */
    private $macAddress;

    /**
     * @param string|null $ipAddress
     * @param string|null $macAddress
     */
    public function __construct(string $ipAddress = null, string $macAddress = null)
    {
        $this->ipAddress = $ipAddress;
        $this->macAddress = $macAddress;
    }

    /**
     * @return bool
     */
    public function hasIpAddress(): bool
    {
        return $this->ipAddress !== null;
    }

    /**
     * @return bool
     */
    public function hasMacAddress(): bool
    {
        return $this->macAddress !== null;
    }

    /**
     * @return string
     */
    public function getIpAddress()
    {
        return $this->ipAddress;
    }

    /**
     * @return string
     */
    public function getMacAddress()
    {
        return $this->macAddress;
    }

    /**
     * @return string
     */
    public function getNormalizedMacAddress()
    {
        return str_replace(':', '', $this->macAddress);
    }
}
