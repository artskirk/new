<?php

namespace Datto\Replication;

use Datto\Config\JsonConfigRecord;

/**
 * Represents the contents of the inboundDevices or outboundDevices file.
 * These store information about the devices that can offsite to this device (inbound) or
 * information about the devices that this device can offsite to (outbound).
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class ReplicationDevices extends JsonConfigRecord
{
    const INBOUND_FILE = 'inboundDevices';
    const OUTBOUND_FILE = 'outboundDevices';

    /** @var string */
    private $filename;

    /** @var ReplicationDevice[] */
    private $devices;

    /**
     * @return ReplicationDevices
     */
    public static function createInboundReplicationDevices(): ReplicationDevices
    {
        return new ReplicationDevices(self::INBOUND_FILE);
    }

    /**
     * @return ReplicationDevices
     */
    public static function createOutboundReplicationDevices(): ReplicationDevices
    {
        return new ReplicationDevices(self::OUTBOUND_FILE);
    }

    /**
     * @param string $filename
     */
    private function __construct(string $filename)
    {
        $this->filename = $filename;
    }

    /**
     * @return string name of key file that this config record will be stored to
     */
    public function getKeyName(): string
    {
        return $this->filename;
    }

    /**
     * @param string $deviceId
     * @return ReplicationDevice|null
     */
    public function getDevice(string $deviceId)
    {
        return $this->devices[$deviceId] ?? null;
    }

    /**
     * @return array
     */
    public function getDevices(): array
    {
        return $this->devices;
    }

    /**
     * @param array $devices
     */
    public function setDevices(array $devices)
    {
        $this->load($devices);
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        foreach ($this->devices as $device) {
            $serializedDevices[] = $device->toArray();
        }
        return $serializedDevices ?? [];
    }

    /**
     * Load the config record instance using values from associative array.
     *
     * @param array $vals
     */
    protected function load(array $vals)
    {
        $this->devices = [];
        foreach ($vals as $device) {
            $device = ReplicationDevice::createFromArray($device);
            $this->devices[$device->getDeviceId()] = $device;
        }
    }
}
