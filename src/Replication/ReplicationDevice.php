<?php

namespace Datto\Replication;

use Exception;

/**
 * Represents a device that we can offsite to (outbound) or a device that offsites to us (inbound)
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class ReplicationDevice
{
    /** @var int */
    private $deviceId;

    /** @var int */
    private $resellerId;

    /** @var string */
    private $hostname;

    /** @var string */
    private $ddnsDomain;

    /**
     * @param int $deviceId
     * @param int $resellerId
     * @param string $hostname
     * @param string $ddnsDomain
     */
    public function __construct(int $deviceId, int $resellerId, string $hostname, string $ddnsDomain)
    {
        $this->deviceId = $deviceId;
        $this->resellerId = $resellerId;
        $this->hostname = $hostname;
        $this->ddnsDomain = $ddnsDomain;
    }

    /**
     * @param array $device
     * @return ReplicationDevice
     */
    public static function createFromArray(array $device): ReplicationDevice
    {
        if (!isset($device['deviceID'], $device['resellerID'], $device['hostname'], $device['ddnsDomain'])) {
            throw new Exception('Missing keys, cannot instantiate ReplicationDevice: ' . json_encode($device));
        }

        return new self(
            (int)$device['deviceID'],
            (int)$device['resellerID'],
            $device['hostname'],
            $device['ddnsDomain']
        );
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        return [
            'deviceID' => $this->deviceId,
            'resellerID' => $this->resellerId,
            'hostname' => $this->hostname,
            'ddnsDomain' => $this->ddnsDomain
        ];
    }

    /**
     * @return int
     */
    public function getDeviceId(): int
    {
        return $this->deviceId;
    }

    /**
     * @return int
     */
    public function getResellerId(): int
    {
        return $this->resellerId;
    }

    /**
     * @return string
     */
    public function getHostname(): string
    {
        return $this->hostname;
    }

    /**
     * @return string
     */
    public function getDdnsDomain(): string
    {
        return $this->ddnsDomain;
    }
}
