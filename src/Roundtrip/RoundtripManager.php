<?php

namespace Datto\Roundtrip;

use Datto\System\MountManager;
use Datto\Utility\Roundtrip\Enclosure;
use Datto\Utility\Roundtrip\NasTarget;
use Datto\Utility\Roundtrip\NetworkInterface;
use Datto\Utility\Roundtrip\Roundtrip;
use Datto\Utility\Roundtrip\RoundtripStatus;

/**
 * Class to deal with functionality related to Roundtrip devices and process
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 * @author Stephen Allan <sallan@datto.com>
 */
class RoundtripManager
{
    /* @var MountManager */
    private $mountManager;

    /** @var Roundtrip */
    private $roundtripUtility;

    /**
     * @param MountManager $mountManager
     * @param Roundtrip $roundtripUtility
     */
    public function __construct(MountManager $mountManager, Roundtrip $roundtripUtility)
    {
        $this->mountManager = $mountManager;
        $this->roundtripUtility = $roundtripUtility;
    }

    /**
     * Checks whether or not the device has an active Roundtrip
     *
     * @return string|bool The mount point if it exists, False if it was not found
     */
    public function hasActiveRoundtrip()
    {
        return $this->mountManager->getDeviceMountPoint("/mnt/roundtrip");
    }

    /**
     * Start a USB roundtrip.
     *
     * @param array $agents Snapshots of agents to sync. See below for expected format
     * @param array $shares Snapshots of shares to sync. See below for expected format
     * @param string[] $enclosures Devices to create the roundtrip pool on
     * @param string $confirmationEmail Address to send emails to on completion
     * @param bool $encrypt True to encrypt the roundtrip
     * @param bool $inhibitBackups True to stop backups occurring during the roundtrip process
     * @param bool $cloudSync Mark volumes for offsite syncing via speedsync
     *
     * Expected array format:
     * "agents|shares" => [
     *     "<uuid>" => [                    // Indexes 'from' and 'to' can optionally be used to define a snapshot range.
     *         "from" => "<snapshotEpoch>", // Both must exist or be missing. One cannot be defined without the other.
     *         "to" => "<snapshotEpoch>"    // If both are missing or NULL then all snapshots are synced.
     *     ],
     *     ...
     * ]
     */
    public function startUsb(
        array $agents,
        array $shares,
        array $enclosures,
        string $confirmationEmail,
        bool $encrypt,
        bool $inhibitBackups,
        bool $cloudSync
    ) {
        $this->roundtripUtility->startUsb(
            $agents,
            $shares,
            $enclosures,
            $confirmationEmail,
            $encrypt,
            $inhibitBackups,
            $cloudSync
        );
    }

    /**
     * Start a NAS roundtrip.
     *
     * @param array $agents Snapshots of agents to sync. See below for expected format
     * @param array $shares Snapshots of shares to sync. See below for expected format
     * @param string $nic The network interface the NAS exists on
     * @param string $nas NAS to create the roundtrip pool on
     * @param string $confirmationEmail Address to send emails to on completion
     * @param bool $inhibitBackups True to stop backups occurring during the roundtrip process
     *
     * Expected array format:
     * "agents|shares" => [
     *     "<uuid>" => [],  // Value of the nested array is not used, but key name must be the uuid
     *     ...
     * ]
     */
    public function startNas(
        array $agents,
        array $shares,
        string $nic,
        string $nas,
        string $confirmationEmail,
        bool $inhibitBackups
    ) {
        $this->roundtripUtility->startNas(
            $agents,
            $shares,
            $nic,
            $nas,
            $confirmationEmail,
            $inhibitBackups
        );
    }

    /**
     * Cancel a running USB roundtrip.
     */
    public function cancelUsb()
    {
        $this->roundtripUtility->cancelUsb();
    }

    /**
     * Cancel a running NAS roundtrip.
     */
    public function cancelNas()
    {
        $this->roundtripUtility->cancelNas();
    }

    /**
     * Retrieve a list of local NAS targets.
     *
     * @param string $nic Name of the network interface to check for targets
     * @return NasTarget[] List of available NAS targets
     */
    public function getTargets(string $nic): array
    {
        return $this->roundtripUtility->getTargets($nic);
    }

    /**
     * Retrieve a list of active network interfaces.
     *
     * @return NetworkInterface[] List of active NICs
     */
    public function getNics(): array
    {
        return $this->roundtripUtility->getNics();
    }

    /**
     * Get an object encapsulating the status of the USB Roundtrip.
     *
     * @return RoundtripStatus Current USB Roundtrip status.
     */
    public function getUsbStatus(): RoundtripStatus
    {
        return $this->roundtripUtility->getUsbStatus();
    }

    /**
     * Get an object encapsulating the status of the NAS Roundtrip.
     *
     * @return RoundtripStatus Current NAS Roundtrip status.
     */
    public function getNasStatus(): RoundtripStatus
    {
        return $this->roundtripUtility->getNasStatus();
    }

    /**
     * Retrieve a list of connected enclosures.
     *
     * @return Enclosure[] List of enclosures
     */
    public function getEnclosures(): array
    {
        return $this->roundtripUtility->getEnclosures();
    }
}
