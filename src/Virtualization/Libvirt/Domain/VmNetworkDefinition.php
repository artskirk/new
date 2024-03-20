<?php
namespace Datto\Virtualization\Libvirt\Domain;

/**
 * Represents network interface XML configuration for the VM (domain).
 *
 * {@link http://libvirt.org/formatdomain.html#elementsNICS}
 */
class VmNetworkDefinition
{
    const ISOLATED_NETWORK_NAME = 'private';
    const DEFAULT_NETWORK_NAME = 'default';
    const DEFAULT_NETWORKFILTER_NAME = 'private-local-traffic-block';

    const INTERFACE_TYPE_NETWORK = 'network';
    const INTERFACE_TYPE_BRIDGE = 'bridge';
    const INTERFACE_TYPE_ETHERNET = 'ethernet';
    const INTERFACE_TYPE_INTERNAL = 'internal';
    const INTERFACE_TYPE_USER = 'user';
    const INTERFACE_TYPE_DIRECT = 'direct';
    const INTERFACE_TYPE_PASSTHROUGH = 'hostdev';
    const INTERFACE_TYPE_MULTICAST = 'mcast';
    const INTERFACE_TYPE_SERVER = 'server';
    const INTERFACE_TYPE_CLIENT = 'client';

    const LINK_STATE_UP = 'up';
    const LINK_STATE_DOWN = 'down';

    protected $interfaceType;
    protected $sourceNetwork;
    protected $sourceBridge;
    protected $sourceDevice;
    protected $sourceMode;
    protected $sourceAddress;
    protected $sourcePort;
    protected $sourceName;
    protected $macAddress;
    protected $interfaceModel;
    protected $targetInterface;
    protected $scriptPath;
    protected $linkState = self::LINK_STATE_UP;

    /** @var string[] */
    private $filters = array();

    /**
     * Gets the type of virtual network interface.
     *
     * @return string
     *  One of the INTERFACE_TYPE_* string constants.
     */
    public function getInterfaceType()
    {
        return $this->interfaceType;
    }

    /**
     * Gets the type of virtual network interface.
     *
     * @param string $interfaceType
     *  One of the INTERFACE_TYPE_* string constants.
     * @return self
     */
    public function setInterfaceType($interfaceType)
    {
        $this->interfaceType = $interfaceType;
        return $this;
    }

    /**
     * Gets the network name used as a source for the interface.
     *
     * Used when interface type is 'network'
     *
     * @return string|null
     */
    public function getSourceNetwork()
    {
        return $this->sourceNetwork;
    }

    /**
     * Sets the network name used as a source for the interface.
     *
     * Used when interface type is 'network'
     *
     * @param string $networkName
     * @return self
     */
    public function setSourceNetwork($networkName)
    {
        $this->sourceNetwork = $networkName;
        return $this;
    }

    /**
     * Gets the name of the bridge interface used as a source for the interface.
     *
     * Used when the network interface type is 'bridge'.
     *
     * @return string|null
     */
    public function getSourceBridge()
    {
        return $this->sourceBridge;
    }

    /**
     * Sets the name of the bridge interface used as a source for the interface.
     *
     * Used when the network interface type is 'bridge'.
     *
     * @param string $sourceBridge
     * @return self
     */
    public function setSourceBridge($sourceBridge)
    {
        $this->sourceBridge = $sourceBridge;
        return $this;
    }

    public function getSourceDevice()
    {
        return $this->sourceDevice;
    }

    public function setSourceDevice($sourceDevice)
    {
        $this->sourceDevice = $sourceDevice;
        return $this;
    }

    public function getSourceMode()
    {
        return $this->sourceMode;
    }

    public function setSourceMode($sourceMode)
    {
        $this->sourceMode = $sourceMode;
        return $this;
    }

    /**
     * Gets the IP address for interface source.
     *
     * Used when interface type is 'mcast', 'server' or 'client'
     *
     * @return string|null
     */
    public function getSourceAddress()
    {
        return $this->sourceAddress;
    }

    /**
     * Sets the IP address for interface source.
     *
     * Used when interface type is 'mcast', 'server' or 'client'
     *
     * @param string $sourceAddress
     * @return self
     */
    public function setSourceAddress($sourceAddress)
    {
        $this->sourceAddress = $sourceAddress;
        return $this;
    }

    /**
     * Gets the port for interface source.
     *
     * Used when interface type is 'mcast', 'server' or 'client'
     *
     * @return string|null
     */
    public function getSourcePort()
    {
        return $this->sourcePort;
    }

    /**
     * Gets the port for interface source.
     *
     * Used when interface type is 'mcast', 'server' or 'client'
     *
     * @param string $sourcePort
     * @return self
     */
    public function setSourcePort($sourcePort)
    {
        $this->sourcePort = $sourcePort;
        return $this;
    }

    /**
     * Get the source name.
     *
     * Currently used only for INTERNAL network type.
     *
     * @return string
     */
    public function getSourceName()
    {
        return $this->sourceName;
    }

    /**
     * Sets source name.
     *
     * Currently used only for INTERNAL network type.
     *
     * @param string $name
     *  The name of the source.
     * @return self
     */
    public function setSourceName($name)
    {
        $this->sourceName = $name;
        return $this;
    }

    /**
     * Gets the MAC address of the NIC.
     *
     * @return string
     */
    public function getMacAddress()
    {
        return $this->macAddress;
    }

    /**
     * Sets the MAC addresss for the NIC.
     *
     * @param string $macAddress
     * @return self
     */
    public function setMacAddress($macAddress)
    {
        $this->macAddress = $macAddress;
        return $this;
    }

    /**
     * Gets the NIC model.
     *
     * @return self
     */
    public function getInterfaceModel()
    {
        return $this->interfaceModel;
    }

    /**
     * Sets the NIC model.
     *
     * @param string $interfaceModel
     * @return self
     */
    public function setInterfaceModel($interfaceModel)
    {
        $this->interfaceModel = $interfaceModel;
        return $this;
    }

    /**
     * Gets the target interface for virtual NIC.
     *
     * For example, the bridge insterface to bind to virtual NIC to.
     *
     * @return string
     */
    public function getTargetInterface()
    {
        return $this->targetInterface;
    }

    /**
     * Sets the target innterface for virtual NIC.
     *
     * For example, the bridge insterface to bind to virtual NIC to.
     *
     * @param string $targetIntreface
     * @return self
     */
    public function setTargetInterface($targetInterface)
    {
        $this->targetInterface = $targetInterface;
        return $this;
    }

    /**
     * Gets the path to the script that executes to connect guest to LAN.
     *
     * Used with when interface type is 'ethernet'.
     *
     * @return string|null
     *  An absolute path to the script to execute
     */
    public function getScriptPath()
    {
        return $this->scriptPath;
    }

    /**
     * Gets the path to the script that executes to connect guest to LAN.
     *
     * Used with when interface type is 'ethernet'.
     *
     * @param string $scriptPath
     *  An absolute path to the script to execute
     * @return self
     */
    public function setScriptPath($scriptPath)
    {
        $this->scriptPath = $scriptPath;
        return $this;
    }

    /**
     * Gets the link state for the virtual interface (if specified).
     *
     * @return string|null
     *  If specified, it will be either LINK_STATE_UP or LINK_STATE_DOWN
     */
    public function getLinkState()
    {
        return $this->linkState;
    }

    /**
     * Sets the link state for the virtual interface.
     *
     * @param string $linkState
     *  Either LINK_STATE_UP or LINK_STATE_DOWN
     * @return self
     */
    public function setLinkState($linkState)
    {
        $this->linkState = $linkState;
        return $this;
    }

    /**
     * Add a new filter to the list of network filters being used on this virtual machine
     *
     * @param string $filterName Name of the filter to use.
     */
    public function addFilter($filterName)
    {
        $this->filters[] = $filterName;
    }

    /**
     * Remove a network filter from the list of filters for this virtual machine
     *
     * @param string $filterName Name of the filter to remove
     */
    public function removeFilter($filterName)
    {
        $this->filters = array_diff($this->filters, array($filterName));
    }

    public function getFilters()
    {
        return $this->filters;
    }

    public function __toString()
    {
        $root = new \SimpleXmlElement('<root></root>');

        $type = $this->getInterfaceType();

        $interface = $root->addChild('interface');
        $interface->addAttribute('type', $type);

        switch ($type) {
            case self::INTERFACE_TYPE_NETWORK:
                $source = $interface->addChild('source');
                $source->addAttribute('network', $this->getSourceNetwork());
                break;
            case self::INTERFACE_TYPE_BRIDGE:
                $source = $interface->addChild('source');
                $source->addAttribute('bridge', $this->getSourceBridge());
                break;
            case self::INTERFACE_TYPE_ETHERNET:
                $ret = $this->getScriptPath();
                if (!empty($ret)) {
                    $scriptPath = $interface->addChild('script');
                    $scriptPath->addAttribute('path', $this->getScriptPath());
                }
                break;
            case self::INTERFACE_TYPE_DIRECT:
                $source = $interface->addChild('source');
                $source->addAttribute('dev', $this->getSourceDevice());
                $source->addAttribute('mode', $this->getSourceMode());
                break;
            case self::INTERFACE_TYPE_INTERNAL:
                $source = $interface->addChild('source');
                $source->addAttribute('name', $this->getSourceName());
                break;
            case self::INTERFACE_TYPE_MULTICAST:
            case self::INTERFACE_TYPE_SERVER:
            case self::INTERFACE_TYPE_CLIENT:
                $source = $interface->addChild('source');
                $source->addAttribute('address', $this->getSourceAddress());
                $source->addAttribute('port', $this->getSourcePort());
                break;
        }

        if (!in_array($type, array(
                                self::INTERFACE_TYPE_DIRECT,
                                self::INTERFACE_TYPE_ETHERNET,
                            ))
        ) {
            $ret = $this->getMacAddress();
            if (!empty($ret)) {
                $macAddress = $interface->addChild('mac');
                $macAddress->addAttribute('address', $this->getMacAddress());
            }
        }

        $ret = $this->getInterfaceModel();
        if (!empty($ret)) {
            $model = $interface->addChild('model');
            $model->addAttribute('type', $this->getInterfaceModel());
        }

        foreach ($this->filters as $filter) {
            $filterElement = $interface->addChild('filterref');
            $filterElement->addAttribute('filter', $filter);
        }

        // up is implied, so just add when down.
        if ($this->getLinkState() === self::LINK_STATE_DOWN) {
            $linkState = $interface->addChild('link');
            $linkState->addAttribute('state', 'down');
        }

        return (string)$root->interface->asXml();
    }
}
