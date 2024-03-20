<?php

namespace Datto\Utility\Network\NetworkManager;

use Datto\Common\Utility\Filesystem;
use Datto\Utility\Network\CachingNmcli;
use Throwable;

/**
 * This class represents a NetworkManager "Device", which is itself essentially just a standard network interface.
 * NetworkManager uses the two terms more-or-less interchangeably.
 *
 * Devices follow the same basic syntax as connections; however, since there is no way to configure a device directly,
 * there are no lower-case parameters, and nothing to "set", so this class is much smaller in scope than
 * NetworkConnection.
 *
 * @see https://networkmanager.dev/docs/api/latest/ref-dbus-devices.html
 *  - The documentation above is for the DBUS API, but it's fairly straightforward to correlate this with nmcli output
 *
 * @author Geoff Amey <gamey@datto.com>
 */
class NetworkDevice
{
    /** @var string $interface The underlying linux interface for this device */
    private string $interface;

    private NetworkConnectionFactory $connectionFactory;
    private CachingNmcli $nmcli;
    private Filesystem $filesystem;

    public function __construct(
        string $interface,
        CachingNmcli $nmcli,
        NetworkConnectionFactory $connectionFactory,
        Filesystem $filesystem
    ) {
        $this->interface = $interface;
        $this->nmcli = $nmcli;
        $this->connectionFactory = $connectionFactory;
        $this->filesystem = $filesystem;
    }

    //****************************************************************
    // Getters - Connection Configuration
    //****************************************************************

    /**
     * Get the name of the underlying linux interface device
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->getField('GENERAL.DEVICE');
    }

    /**
     * Get the device type (ethernet, bridge, bond, vlan)
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->getField('GENERAL.TYPE');
    }

    /**
     * Get the MAC Address of this device
     *
     * @return string
     */
    public function getMacAddr(): string
    {
        return $this->getField('GENERAL.HWADDR');
    }

    /**
     * Get the MTU for this device
     *
     * @return int
     */
    public function getMtu(): int
    {
        return $this->getField('GENERAL.MTU') ?: 0;
    }

    /**
     * Get the link speed for this device. Some device types (bridges, vlans, etc...) do not support link speed, and
     * will report unknown. In these cases, we will try querying the sysfs before reporting a default, "unknown"
     * value of 0
     *
     * @return int The speed in Mbps, or 0 if the speed could not be determined
     */
    public function getSpeed(): int
    {
        $speed = intval(preg_replace('/\D/', '', $this->getField('CAPABILITIES.SPEED')));
        if ($speed <= 0) {
            $speed = $this->getSpeedFromSysfs();
        }
        return $speed;
    }

    /**
     * Get the carrier state for this device. This generally refers to whether it's actually plugged in to an
     * active switch.
     *
     * @return bool true if the device has an active carrier signal
     */
    public function getCarrier(): bool
    {
        return $this->getField('INTERFACE-FLAGS.CARRIER') === 'yes';
    }

    /**
     * Gets the UUID of the currently-active connection for this device
     *
     * @return string
     */
    public function getActiveConnectionUuid(): string
    {
        return $this->getField('GENERAL.CON-UUID');
    }

    /**
     * Gets the currently-active connection that is managing this device, if one exists
     *
     * @return NetworkConnection|null
     */
    public function getActiveConnection(): ?NetworkConnection
    {
        return $this->connectionFactory->getConnection($this->getActiveConnectionUuid());
    }

    /**
     * Gets all the connections that are currently configured to manage this device
     */
    public function getAvailableConnectionUuids()
    {
        $available = explode('&', $this->getField('CONNECTIONS.AVAILABLE-CONNECTIONS'));
        return array_filter(array_map(fn($str) => substr($str, 0, strpos($str, ' | ')), $available));
    }

    //****************************************************************
    // Internal helper functions
    //****************************************************************

    /**
     * Get a single field from this connection, without escaping special characters (':' and '\')
     * @see https://networkmanager.dev/docs/api/latest/nm-settings-nmcli.html
     *
     * @param string $field The field to retrieve
     * @return string The value of the field or empty string if the field is empty
     */
    private function getField(string $field): string
    {
        $devices = $this->nmcli->deviceShowDetails();

        foreach ($devices as $deviceFields) {
            $name = $deviceFields['GENERAL.DEVICE'] ?? null;

            if ($name === $this->interface) {
                return $deviceFields[$field] ?? '';
            }
        }

        return '';
    }

    /**
     * NetworkManager will frequently not report any speed for devices like bonds and vlans, and will instead report
     * "unknown". In this case, we want to query the sysfs for the speed, which seems to be much more reliable for
     * non-ethernet devices.
     *
     * @return int
     */
    private function getSpeedFromSysfs(): int
    {
        $speed = 0;
        try {
            $speedFile = sprintf('/sys/class/net/%s/speed', $this->getName());
            $speed = intval(@$this->filesystem->fileGetContents($speedFile));
        } catch (Throwable $throwable) {
            // Do nothing in here, just prevent the error from propagating, and we'll return the default
        }

        // On disconnected interfaces, speed will read as -1, so cap the min at 0
        return max($speed, 0);
    }
}
