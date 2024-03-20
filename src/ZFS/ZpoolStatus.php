<?php

namespace Datto\ZFS;

/**
 * ZFS Pool Status information
 *
 * @author Mario Rial <mrial@datto.com>
 */
class ZpoolStatus implements \JsonSerializable
{
    /** @var string */
    private $rawStatus;

    /** @var string */
    private $pool;

    /** @var string */
    private $state;

    /** @var string */
    private $status;

    /** @var string */
    private $scan;

    /** @var array */
    private $config;

    /** @var array */
    private $errors;

    /**
     * ZpoolStatus constructor.
     *
     * @param string $rawStatus
     * @param string $pool
     * @param string $state
     * @param string|null $status
     * @param string $scan
     * @param array $config
     * @param array|null $errors
     */
    public function __construct(string $rawStatus, string $pool, string $state, $status, string $scan, $config, $errors)
    {
        $this->rawStatus = $rawStatus;
        $this->pool = $pool;
        $this->state = $state;
        $this->status = $status;
        $this->scan = $scan;
        $this->config = $config;
        $this->errors = $errors;
    }

    /**
     * Gets the original output of the zpool status command
     *
     * @return string
     */
    public function getRaw(): string
    {
        return $this->rawStatus;
    }

    /**
     * Gets the name of the ZFS Pool
     *
     * @return string
     */
    public function getPool(): string
    {
        return $this->pool;
    }

    /**
     * Gets the state of the ZFS Pool
     *
     * @return string
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * Gets the status of the ZFS Pool
     *
     * @return string|null
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * Gets the scan section of zpool status output.
     *
     * @return string
     */
    public function getScan(): string
    {
        return $this->scan;
    }

    /**
     * Gets the config section of zpool status output, parsed as an array.
     *
     * @return array
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Gets the errors section of zpool status output, parsed as an array.
     *
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Returns true if any drive in the ZFS Pool is resilvering, false otherwise.
     *
     * @return bool
     */
    public function isZpoolResilvering(): bool
    {
        return !empty($this->getResilveringGroup());
    }

    /**
     * Returns whether a given drive specified by id is resilvering or not.
     *
     * @param string $driveId
     * @return bool
     */
    public function isDriveResilvering(string $driveId): bool
    {
        $drive = $this->findDriveGroupRecursive($this->getPoolDevices(), $driveId)[$driveId] ?? [];

        return isset($drive['note']) && ($drive['note'] === '(resilvering)');
    }

    /**
     * Returns whether a given drive specified by id is available, indicating if the drive is resilvering or offline
     *
     * @param string $driveId
     * @return bool
     */
    public function isDriveAvailable(string $driveId): bool
    {
        $drive = $this->findDriveGroupRecursive($this->getPoolDevices(), $driveId)[$driveId] ?? [];
        $notResilvering = isset($drive['note']) && ($drive['note'] !== '(resilvering)');
        $online = isset($drive['state']) && ($drive['state'] === 'ONLINE');

        return $notResilvering && $online;
    }

    /**
     * Returns the group of drives that are resilvering
     *
     * @return array
     */
    public function getResilveringGroup(): array
    {
        return $this->findDriveGroupRecursive($this->getPoolDevices(), 'resilvering');
    }

    /**
     * Return the group of drives that are considered unavailable
     *
     * @return array
     */
    public function getUnavailableGroup(): array
    {
        $devices = $this->getPoolDevices();

        $resilvering = $this->getResilveringGroup();
        $corrupted = $this->findDriveGroupRecursive($devices, 'corrupted');
        $repairing = $this->findDriveGroupRecursive($devices, 'repairing');
        $unavailable = $this->findDriveGroupRecursive($devices, 'UNAVAIL');

        $unavailableDrives = array_merge($resilvering, $corrupted, $repairing, $unavailable);

        return $unavailableDrives;
    }

    /**
     * Returns the group of drives that is in the replacement group
     *
     * @return array
     */
    public function getReplacementGroup()
    {
        return $this->findDriveGroupRecursive($this->getPoolDevices(), "replacing");
    }

    /**
     * Returns the IDs of all drives in the zpool.
     *
     * @return array
     */
    public function getPoolDriveIds(): array
    {
        return array_values($this->getPoolDriveIdsRecursive($this->getPoolDevices()));
    }

    /**
     * Returns the IDs of all cache drives in the zpool.
     *
     * @return array
     */
    public function getCacheDriveIds(): array
    {
        if (!isset($this->config["cache"]["devices"])) {
            return [];
        } else {
            return array_values($this->getPoolDriveIdsRecursive($this->config["cache"]["devices"]));
        }
    }

    /**
     * Returns all the devices of the zpool.
     *
     * @return array|null
     */
    public function getPoolDevices()
    {
        return $this->config[$this->getPool()]["devices"];
    }

    /**
     * Returns an human readable string representation of zpool status.
     *
     * @return string
     */
    public function getSummarizedStatus(): string
    {
        $isResilvering = $this->isZpoolResilvering();

        $output =
            "Pool: " . $this->getPool() . PHP_EOL .
            "State: " . $this->getState() . PHP_EOL .
            "Scan: " . $this->getScan() . PHP_EOL;

        if ($this->getStatus()) {
            $output .= "Status: " . $this->getStatus() . PHP_EOL;
        }

        if ($isResilvering) {
            $output .= "- The pool is Resilvering" . PHP_EOL;

            $resilveringGroup = $this->getResilveringGroup();

            foreach ($resilveringGroup as $name => $device) {
                if ($this->isDriveResilvering($name)) {
                    $output .= "- New drive being resilvered: $name" . PHP_EOL;
                } else {
                    $output .= "- Old drive being replaced:   $name" . PHP_EOL;
                }
            }
        }

        $output .= "-- Drives in Zpool at the moment:" . PHP_EOL;
        $output .= var_export($this->getPoolDriveIds(), true);

        return $output;
    }

    /**
     * Determine the raid level of the pool.
     *
     * @return string
     */
    public function getRaidLevel(): string
    {
        $devices = $this->getPoolDevices();

        $hasMirror = !empty($this->findDriveGroupRecursive($devices, "mirror-"));
        $hasRaid5 =  !empty($this->findDriveGroupRecursive($devices, "raidz1-"));
        $hasRaid6 =  !empty($this->findDriveGroupRecursive($devices, "raidz2-"));

        // If any two are true, this condition is true
        $hasMultipleRaids = $hasMirror && ($hasRaid5 || $hasRaid6) || ($hasRaid5 && $hasRaid6);

        if ($hasMultipleRaids) {
            return ZpoolService::RAID_MULTIPLE;
        }

        if ($hasMirror) {
            return ZpoolService::RAID_MIRROR;
        } elseif ($hasRaid5) {
            return ZpoolService::RAID_5;
        } elseif ($hasRaid6) {
            return ZpoolService::RAID_6;
        } else {
            return ZpoolService::RAID_NONE;
        }
    }

    /**
     * Get a list of drives in RAID
     *
     * @param string $raidPrefix
     * @return array
     */
    public function getDrivesInRaid(string $raidPrefix): array
    {
        return $this->findDriveGroupRecursive($this->getPoolDevices(), $raidPrefix);
    }

    /**
     * @param array $poolDevices
     * @return array
     */
    private function getPoolDriveIdsRecursive(array $poolDevices): array
    {
        $driveIds = [];

        foreach ($poolDevices as $device) {
            if (isset($device['devices']) && $device['devices']) {
                $driveIds = array_merge($driveIds, $this->getPoolDriveIdsRecursive($device['devices']));
            } else {
                $driveIds[] = $device['name'];
            }
        }

        return $driveIds;
    }

    /**
     * @param array $drives
     * @param string $keyPrefix
     * @return array
     */
    private function findDriveGroupRecursive(array $drives, string $keyPrefix)
    {
        $result = [];

        foreach ($drives as $name => $drive) {
            $noteMatches = isset($drive['note']) && strpos($drive['note'], $keyPrefix) !== false;
            $stateMatches = isset($drive['state']) && $drive['state'] === $keyPrefix;

            if (strpos($name, $keyPrefix) === 0 || $noteMatches || $stateMatches) {
                $result[$name] = $drive;
            } elseif (isset($drive['devices']) && $drive['devices']) {
                $result = array_merge($result, $this->findDriveGroupRecursive($drive['devices'], $keyPrefix));
            }
        }

        return $result;
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            "pool" => $this->pool,
            "state" => $this->state,
            "status" => $this->status,
            "scan" => $this->scan,
            "config" => $this->config,
            "errors" => $this->errors,
            "raw" => $this->rawStatus
        ];
    }
}
