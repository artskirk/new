<?php

namespace Datto\Asset\Agent;

/**
 * This class contains settings stored in agentInfo file that pertain to ShadowSnap / DLA / DMA.
 *
 * @author Bartosz Neuman <bneuman@datto.com>
 */
class DriverSettings
{
    /** @var string API version used by the agent, e.g. 3.3.0 */
    private $apiVersion;

    /** @var string Agent version, e.g. 5.0.1.23057 */
    private $agentVersion;

    /** @var string Driver version, e.g. 0.1.17 (see MacAgent) */
    private $driverVersion;

    /** @var string Agent serial number, e.g. 3AF6-9D39-50B0-9031 */
    private $serialNumber;

    /** @var bool Flag for telling whether agent is activated */
    private $activated;

    /** @var bool Flag for telling whether system was restarted after adding an agent */
    private $stcDriverLoaded;

    /**
     * @param string $apiVersion
     * @param string $agentVersion
     * @param $driverVersion
     * @param string $agentSerialNumber
     * @param bool $agentActivated
     * @param bool $stcDriverLoaded
     */
    public function __construct(
        $apiVersion,
        $agentVersion,
        $driverVersion,
        $agentSerialNumber,
        $agentActivated,
        $stcDriverLoaded
    ) {
        $this->apiVersion = $apiVersion;
        $this->agentVersion = $agentVersion;
        $this->driverVersion = $driverVersion;
        $this->serialNumber = $agentSerialNumber;
        $this->activated = $agentActivated;
        $this->stcDriverLoaded = $stcDriverLoaded;
    }

    /**
     * @return string
     */
    public function getApiVersion()
    {
        return $this->apiVersion;
    }

    /**
     * @return string
     */
    public function getAgentVersion()
    {
        return $this->agentVersion;
    }

    /**
     * @param $agentVersion
     */
    public function setAgentVersion($agentVersion): void
    {
        $this->agentVersion = $agentVersion;
    }

    /**
     * @return string
     */
    public function getDriverVersion()
    {
        return $this->driverVersion;
    }

    /**
     * @return string
     */
    public function getSerialNumber()
    {
        return $this->serialNumber;
    }

    /**
     * @return boolean
     */
    public function isActivated()
    {
        return $this->activated;
    }

    /**
     * @return boolean
     */
    public function isStcDriverLoaded()
    {
        return $this->stcDriverLoaded;
    }
}
