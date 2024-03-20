<?php

namespace Datto\Network;

use JsonSerializable;

/**
 * A datto device on the network
 *
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class AccessibleDevice implements JsonSerializable
{
    /** @var string $hostname */
    private $hostname;

    /** @var string $ip */
    private $ip;

    /** @var string $model */
    private $model;

    /** @var string $serial */
    private $serial;

    /** @var string|null $ddnsDomain */
    private $ddnsDomain;

    /** @var string|null $serviceName */
    private $serviceName;

    /**
     * @param string $hostname
     * @param string $ip
     * @param string $model
     * @param string $serial
     * @param string|null $ddnsDomain
     * @param string|null $serviceName
     */
    public function __construct(string $hostname, string $ip, string $model, string $serial, string $ddnsDomain = null, string $serviceName = null)
    {
        $this->hostname = $hostname;
        $this->ip = $ip;
        $this->model = $model;
        $this->serial = $serial;
        $this->ddnsDomain = $ddnsDomain;
        $this->serviceName = $serviceName;
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            'hostname' => $this->hostname,
            'ip' => $this->ip,
            'model' => $this->model,
            'serial' => $this->serial,
            'ddnsDomain' => $this->ddnsDomain,
            'serviceName'=> $this-> serviceName
        ];
    }

    /**
     * @return string device hostname
     */
    public function getHostname(): string
    {
        return $this->hostname;
    }

    /**
     * @return string device IP address
     */
    public function getIp(): string
    {
        return $this->ip;
    }

    /**
     * @return string device model number
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * @return string device serial number
     */
    public function getSerial(): string
    {
        return $this->serial;
    }

    /**
     * @return null|string device DDNS domain (null if none exists)
     */
    public function getDdnsDomain()
    {
        return $this->ddnsDomain;
    }

    /**
     * @return null|string device service name (null if none exists)
     */
    public function getDeviceServiceName()
    {
        return $this->serviceName;
    }
}
