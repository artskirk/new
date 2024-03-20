<?php

namespace Datto\Utility\Roundtrip;

use Datto\Common\Resource\ProcessFactory;
use Datto\Feature\FeatureService;

/**
 * Interface with roundtrip-ng package's CLI
 *
 * @author Stephen Allan <sallan@datto.com>
 * @author Afeique Sheikh <asheikh@datto.com>
 */
class Roundtrip
{
    // Typical format of CLI command constants is <NAME>_COMMAND
    const ROUNDTRIP_COMMAND = 'rtctl';
    // Call is rtctl keyword for running a Roundtrip command
    const ROUNDTRIP_CALL = 'Call';
    // --json flag tells rtctl to encode response as JSON
    const ROUNDTRIP_JSON = '--json';
    // Below are the actual rtctl commands
    // calls to rtctl have the format:
    // $ rtctl Call <COMMAND> [--json]
    const ROUNDTRIP_NICS = 'GetNics';
    const ROUNDTRIP_START_USB = 'MakeRoundtripLauncher';
    const ROUNDTRIP_START_NAS = 'MakeRoundtripNASLauncher';
    const ROUNDTRIP_CANCEL_USB = 'CancelRoundtripUSB';
    const ROUNDTRIP_CANCEL_NAS = 'CancelRoundtripNAS';
    const ROUNDTRIP_USB_STATUS = 'GetCurrentStatus';
    const ROUNDTRIP_NAS_STATUS = 'GetCurrentNASStatus';
    const ROUNDTRIP_ENCLOSURES = 'GetEnclosures';
    const ROUNDTRIP_NAS_TARGETS = 'GetNASTargets';

    private ProcessFactory $processFactory;
    private FeatureService $featureService;

    /**
     * @param ProcessFactory $processFactory
     */
    public function __construct(ProcessFactory $processFactory, FeatureService $featureService)
    {
        $this->processFactory = $processFactory;
        $this->featureService = $featureService;
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
        $this->processFactory->get([
            self::ROUNDTRIP_COMMAND,
            self::ROUNDTRIP_CALL,
            self::ROUNDTRIP_START_USB,
            json_encode($agents),
            json_encode($shares),
            json_encode($enclosures),
            json_encode($encrypt),
            $confirmationEmail ?: 0,
            intval($inhibitBackups),
            intval($cloudSync)
        ])->mustRun();
    }

    /**
     * Start a NAS roundtrip.
     *
     * @param string[] $agents Snapshots of agents to sync. See below for expected format
     * @param string[] $shares Snapshots of shares to sync. See below for expected format
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
        $transferOverSSH = $this->featureService->isSupported(FeatureService::FEATURE_ROUNDTRIP_NAS_SSH);
        $command = [
            self::ROUNDTRIP_COMMAND,
            self::ROUNDTRIP_CALL,
            self::ROUNDTRIP_START_NAS,
            json_encode($agents),
            json_encode($shares),
            $nic,
            json_encode([$nas]),
            $confirmationEmail ?: 0,
            intval($inhibitBackups),
            intval($transferOverSSH)
        ];
        $this->processFactory->get($command)->mustRun();
    }

    /**
     * Cancel a running USB roundtrip.
     */
    public function cancelUsb()
    {
        $this->processFactory->get([
            self::ROUNDTRIP_COMMAND,
            self::ROUNDTRIP_CALL,
            self::ROUNDTRIP_CANCEL_USB
        ])->mustRun();
    }

    /**
     * Cancel a running NAS roundtrip.
     */
    public function cancelNas()
    {
        $this->processFactory->get([
            self::ROUNDTRIP_COMMAND,
            self::ROUNDTRIP_CALL,
            self::ROUNDTRIP_CANCEL_NAS
        ])->mustRun();
    }

    /**
     * Retrieve a list of local NAS targets.
     *
     * @param string $nic Name of the network interface to check for targets
     * @return NasTarget[] List of available NAS targets
     */
    public function getTargets(string $nic): array
    {
        $nasTargets = [];
        $process = $this->processFactory->get([
            self::ROUNDTRIP_COMMAND,
            self::ROUNDTRIP_CALL,
            self::ROUNDTRIP_NAS_TARGETS,
            $nic,
            self::ROUNDTRIP_JSON
        ])->mustRun();

        $output = json_decode($process->getOutput(), true);
        if (is_array($output)) {
            foreach ($output as $hostname => $target) {
                $address = $target['address'] ?? '';
                $nasName = $target['nasname'] ?? '';
                $protocolVersion = $target['protocol_version'] ?? '';
                $size = intval($target['size'] ?? 0);
                $nasTargets[] = new NasTarget($hostname, $address, $nasName, $protocolVersion, $size);
            }
        }

        return $nasTargets;
    }

    /**
     * Retrieve a list of active network interfaces.
     *
     * @return NetworkInterface[] List of active NICs
     */
    public function getNics(): array
    {
        $networkInterfaces = [];
        $process = $this->processFactory->get([
            self::ROUNDTRIP_COMMAND,
            self::ROUNDTRIP_CALL,
            self::ROUNDTRIP_NICS,
            self::ROUNDTRIP_JSON
        ])->mustRun();

        $output = json_decode($process->getOutput(), true);
        if (is_array($output)) {
            foreach ($output as $nic) {
                $name = $nic['nic'] ?? '';
                $address = $nic['address'] ?? '';
                $mac = $nic['mac'] ?? '';
                $nicToNic = $nic['nic2nic'] ?? false;
                $carrier = $nic['carrier'] ?? false;
                $networkInterfaces[] = new NetworkInterface($name, $address, $mac, $nicToNic, $carrier);
            }
        }

        return $networkInterfaces;
    }

    /**
     * Retrieve a list of connected enclosures.
     *
     * @return Enclosure[] List of enclosures
     */
    public function getEnclosures(): array
    {
        $enclosures = [];
        $process = $this->processFactory->get([
            self::ROUNDTRIP_COMMAND,
            self::ROUNDTRIP_CALL,
            self::ROUNDTRIP_ENCLOSURES,
            self::ROUNDTRIP_JSON
        ])->mustRun();

        $output = json_decode($process->getOutput(), true);
        if (is_array($output)) {
            foreach ($output as $enclosure) {
                foreach ($enclosure as $drive) {
                    $id = $drive['enclosureId'] ?? '';
                    $physicalSize = $drive['physicalSize'] ?? 0;
                    $enclosures[] = new Enclosure($id, $physicalSize);
                }
            }
        }

        return $enclosures;
    }

    /**
     * Get the status of the USB Roundtrip.
     *
     * @return RoundtripStatus Object encapsulating USB Roundtrip status.
     */
    public function getUsbStatus(): RoundtripStatus
    {
        $process = $this->processFactory->get([
            self::ROUNDTRIP_COMMAND,
            self::ROUNDTRIP_CALL,
            self::ROUNDTRIP_USB_STATUS,
            self::ROUNDTRIP_JSON
        ])->mustRun();

        return $this->unserializeStatus($process->getOutput());
    }

    /**
     * Get the status of the NAS Roundtrip.
     *
     * @return RoundtripStatus Object encapsulating NAS Roundtrip status.
     */
    public function getNasStatus(): RoundtripStatus
    {
        $process = $this->processFactory->get([
            self::ROUNDTRIP_COMMAND,
            self::ROUNDTRIP_CALL,
            self::ROUNDTRIP_NAS_STATUS,
            self::ROUNDTRIP_JSON
        ])->mustRun();

        return $this->unserializeStatus($process->getOutput());
    }

    /**
     * Helper method to convert the Roundtrip status response from rtctl to an object.
     *
     * * When there is no running Roundtrip, the `rtctl` status response is:
     * ```php
     * [
     *  'type'          => 'USB',
     *  'last_finished' => null,
     *  'last_state'    => '',
     *  'running'       => false
     * ];
     * ```
     * Right after starting a Roundtrip, the `rtctl` status response is:
     * ```php
     * [
     *  'type'          => string,
     *  'last_finished' => int,
     *  'last_state'    => string,
     *  'current_total' => int,
     *  'current_stage' => int,
     *  'running'       => bool
     * ];
     * ```
     *
     * If there is support for `zfs send`, then additional status data is provided by `rtctl`:
     * ```php
     * [
     *  'speed':        => string,
     *  'percent':      => string, // two decimal places
     *  'timeLeft'      => float,  // double
     *  'totalSize'     => string,
     *  'totalComplete' => string
     * ];
     * ```
     *
     * @param string $json Response from rtctl containing JSON encoded Roundtrip status.
     * @return RoundtripStatus Object encapsulating Roundtrip status.
     */
    private function unserializeStatus(string $json): RoundtripStatus
    {
        $status = json_decode($json, true);
        if (!is_array($status) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RoundtripException("Failed to decode JSON Roundtrip status. Received: '$json'");
        }

        // rtctl should always return at least these entries in the JSON
        $requiredKeys = ['type','running','last_finished','last_state'];
        if (array_intersect($requiredKeys, array_keys($status)) != $requiredKeys) {
            throw new RoundtripException(
                "The following fields are required for Roundtrip status: " .
                "['type','running','last_finished','last_state']"
            );
        }

        return new RoundtripStatus(
            $status['type'] ?? '',
            $status['running'] ?? false,
            $status['last_finished'] ?? 0,
            $status['last_state'] ?? '',
            $status['current_total'] ?? null,
            $status['current_stage'] ?? null,
            $status['speed'] ?? null,
            $status['percent'] ?? null,
            $status['timeLeft'] ?? null,
            $status['totalSize'] ?? null,
            $status['totalComplete'] ?? null
        );
    }
}
