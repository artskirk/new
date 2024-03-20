<?php
namespace Datto\Backup;

use Datto\Common\Resource\ProcessFactory;
use Datto\Config\DeviceConfig;
use Exception;

/**
 * This class handles toggling of secondary replication.
 *
 * Secondary replication involves replicating backup data from
 * the primary Datto cloud data center to a secondary Datto
 * location. This class uses SpeedSync to get secondary
 * replication availability, to determine whether it's
 * enabled/disabled, and to actually toggle it.
 *
 * @author Pankaj Gupta <pgupta@datto.com>
 */
class SecondaryReplicationService
{
    private ProcessFactory $processFactory;

    /** @var DeviceConfig */
    private $deviceConfig;

    public function __construct(
        ProcessFactory $processFactory = null,
        DeviceConfig $deviceConfig = null
    ) {
        $this->processFactory = $processFactory ?: new ProcessFactory();
        $this->deviceConfig = $deviceConfig ?: new DeviceConfig();
    }

    /**
     * Enables secondary replication on the device through SpeedSync.
     */
    public function enable()
    {
        /* If secondary replication is not applicable for this device
           location, throw exception */
        if (!$this->isAvailable()) {
            $message = "Error enabling secondary replication on a device in " .
                "a location for which secondary replication is not available.";
            throw new Exception($message);
        }

        $process = $this->processFactory->get(['speedsync', 'set', 'allowSecondaryReplication', '1']);
        $process->mustRun();
    }

    /**
     * Disables secondary replication on the device through SpeedSync.
     *
     */
    public function disable()
    {
        /* If secondary replication is not applicable for this device
           location, throw exception */
        if (!$this->isAvailable()) {
            $message = "Error disabling secondary replication on a device in " .
                "a location for which secondary replication is not available.";
            throw new Exception($message);
        }

        $process = $this->processFactory->get(['speedsync', 'set', 'allowSecondaryReplication', '0']);
        $process->mustRun();
    }

    /**
     * Checks whether secondary replication is enabled on this device.
     *
     * @return bool Returns true if secondary replication is enabled,
     * false otherwise.
     */
    public function isEnabled()
    {
        /* If secondary replication is not applicable for this device
           location, throw exception */
        if (!$this->isAvailable()) {
            $message = "Error getting enabled status because secondary " .
                "replication is not applicable to this device location.";
            throw new Exception($message);
        }

        /* Otherwise, get the status code through SpeedSync */
        $process = $this->processFactory->get(['speedsync', 'get', 'allowSecondaryReplication']);
        $process->mustRun();

        return $process->getOutput() == 1;
    }

    /**
     * Checks whether secondary replication is available for this device.
     *
     * @return bool Returns true if secondary replication is available
     * for this device, false otherwise.
     */
    public function isAvailable()
    {
        if ($this->deviceConfig->isAlto()) {
            return false;
        }
        $process = $this->processFactory->get(['speedsync', 'get', 'secondaryOffsiteLocation']);
        $process->mustRun();

        return $process->getOutput() != "none";
    }
}
