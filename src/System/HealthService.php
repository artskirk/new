<?php

namespace Datto\System;

use Datto\System\Smart\SmartService;
use Datto\ZFS\ZpoolService;
use Datto\Log\DeviceLoggerInterface;

/**
 * Class to provide basic system healthchecks in a normalized fashion
 *
 * @author Marcus Recck <mr@datto.com>
 */
class HealthService
{
    /**
     * When in RAID a write operation can occur multiple times.
     * This is interpreted as "for every one write with no RAID there will be X writes in RAID Y."
     *
     * We use this value as a multiple to the percentage of writes vs. reads by the disks' raw IOPS
     */
    const RAID_WRITE_PENALTIES = [
        ZpoolService::RAID_NONE => 1,
        ZpoolService::RAID_MIRROR => 2,
        ZpoolService::RAID_5 => 4,
        ZpoolService::RAID_6 => 6,
        ZpoolService::RAID_MULTIPLE => 4
    ];

    /**
     * A single drive is expected to be able to perform this many IO actions in one second.
     * SSDs operate very quickly, while spinner drives can actually vary.
     *
     * Spinner drive breakdown by RPM speed:
     *  - A disk that spins at 5400 RPM is expected to perform 50 IOPS
     *  - A disk that spins at 7200 RPM is expected to perform 75 IOPS
     *  - A disk that spins at 10000 RPM is expected to perform 125 IOPS
     *  - A disk that spins at 15000 RPM is expected to perform 175 IOPS
     *
     * Since we tend to use enterprise-grade 7200 RPM drives we went with a happy medium between 75 and 125 IOPS
     */
    const RAW_DISK_IOPS = [
        'SSD' => 100000,
        'Spinner' => 100
    ];

    const READ_PCT = 0.6;
    const WRITE_PCT = 0.4;

    const RESILVERING_DRIVES_IN_RAID_DOWN_THRESHOLDS = [
        ZpoolService::RAID_NONE => [
            'drives' => 1,
            'prefix' => null
        ],
        ZpoolService::RAID_MIRROR => [
            'drives' => 2,
            'prefix' => 'mirror-'
        ],
        ZpoolService::RAID_5 => [
            'drives' => 2,
            'prefix' => 'raidz1-'
        ],
        ZpoolService::RAID_6 => [
            'drives' => 3,
            'prefix' => 'raidz2-'
        ]
    ];

    const UNAVAILABLE_MINIMUM_DEGRADATION = 1;
    const ACCEPTABLE_USED_CAPACITY = 75;
    const ACCEPTABLE_AVAILABLE_RAM = 0.05;
    const MAX_ACCEPTABLE_LOADAVG_MULTIPLE = 2;

    /** @var ZpoolService */
    private $zpoolService;

    /** @var ResourceMonitor */
    private $resourceMonitor;

    /** @var Hardware */
    private $hardware;

    /** @var SmartService */
    private $smartService;

    /** @var DeviceLoggerInterface */
    private $logger;

    /**
     * @param ZpoolService $zpoolService
     * @param ResourceMonitor $resourceMonitor
     * @param Hardware $hardware
     * @param SmartService $smartService
     * @param DeviceLoggerInterface $logger
     */
    public function __construct(
        ZpoolService $zpoolService,
        ResourceMonitor $resourceMonitor,
        Hardware $hardware,
        SmartService $smartService,
        DeviceLoggerInterface $logger
    ) {
        $this->zpoolService = $zpoolService;
        $this->resourceMonitor = $resourceMonitor;
        $this->hardware = $hardware;
        $this->smartService = $smartService;
        $this->logger = $logger;
    }

    /**
     * Return a normalized array of healthchecks
     *
     * @return Health
     */
    public function calculateHealthScores(): Health
    {
        return new Health(
            $this->calculateZpoolHealthScore(),
            $this->calculateMemoryHealthScore(),
            $this->calculateCpuHealthScore(),
            $this->calculateIopsHealthScore()
        );
    }

    /**
     * Calculate IOPS health based on `iostat` and RAID penalty formula
     *  - If the IOPS of the disk is above the preferred maximum, decrease the score by 1
     *  - If the IOPS of the disk is below the preferred maximum, increase the score by 1
     *
     * Reduce the score to a value between -1 and 1
     *
     * @return int
     */
    public function calculateIopsHealthScore(): int
    {
        $health = Health::SCORE_DEGRADED;

        try {
            $driveIops = $this->resourceMonitor->getPhysicalDriveIops();
            $raidPenalty = self::RAID_WRITE_PENALTIES[$this->zpoolService->getRaidLevel(ZpoolService::HOMEPOOL)] ?? self::RAID_WRITE_PENALTIES[ZpoolService::RAID_NONE];
        } catch (\Throwable $e) {
            $this->logger->debug('HCS0001 Unable to determine current drive IOPS');

            return Health::SCORE_DEGRADED;
        }

        $preferredSsdIops = $this->calculatePreferredIops(self::RAW_DISK_IOPS['SSD'], self::RAID_WRITE_PENALTIES[ZpoolService::RAID_NONE]);
        $preferredSpinnerIops = $this->calculatePreferredIops(self::RAW_DISK_IOPS['Spinner'], $raidPenalty);

        foreach ($driveIops as $disk => $iops) {
            $isSsd = $this->smartService->isSsd($disk);
            $preferredIops = $isSsd ? $preferredSsdIops : $preferredSpinnerIops;

            if ($iops > $preferredIops) {
                $this->logger->debug('HCS0002 Disk exceeds preferred IOPS', ['disk' => $disk, 'preferredIops' => $preferredIops, 'currentIops' => $iops]);

                $health--;
            } else {
                $health++;
            }
        }

        return max(Health::SCORE_DOWN, min(Health::SCORE_OK, $health));
    }

    /**
     * Calculate the preferred IOPS value given a RAID penalty for the expected number of IOPS with no RAID penalty
     *
     * @param int $totalIops
     * @param int $penalty
     *
     * @return float
     */
    public function calculatePreferredIops(int $totalIops, int $penalty): float
    {
        $readAmount = self::READ_PCT * $totalIops;
        $writeAmount = self::WRITE_PCT * $totalIops * $penalty;

        return $readAmount + $writeAmount;
    }

    /**
     * Calculate CPU health between -1 and 1
     *  - If 1min load average is less than or equal to the number of CPU cores, return 1
     *  - If 1min load average is between 1x and 2x of the number of CPU cores, return 0
     *  - If 1min load average is greater than 2x of the number of CPU cores, return -1
     *
     * @return int
     */
    public function calculateCpuHealthScore(): int
    {
        $currentLoadAvg = $this->resourceMonitor->getCpuAvgLoad();
        $numCores = $this->hardware->getCpuCores();

        if ($currentLoadAvg <= $numCores) {
            return Health::SCORE_OK;
        }

        if ($currentLoadAvg > $numCores && $currentLoadAvg <= (self::MAX_ACCEPTABLE_LOADAVG_MULTIPLE * $numCores)) {
            $this->logger->debug('HCS0003 Current load average exceeds number of cores, but less than 2x number of cores');

            return Health::SCORE_DEGRADED;
        }

        if ($currentLoadAvg > (self::MAX_ACCEPTABLE_LOADAVG_MULTIPLE * $numCores)) {
            $this->logger->debug('HCS0004 Current load average exceeds 2x number of cores');

            return Health::SCORE_DOWN;
        }

        return Health::SCORE_DEGRADED;
    }

    /**
     * Calculate physical memory health between -1 and 1
     *  - If total physical RAM cannot be calculated, return 0
     *  - If the ratio of free RAM vs total RAM is less than 5%, return -1
     *  - Otherwise, return 1
     *
     * @return int
     */
    public function calculateMemoryHealthScore(): int
    {
        $freeRam = $this->resourceMonitor->getRamFreeMiB(false);
        $totalRam = $this->hardware->getPhysicalRamMiB();

        if ($totalRam === 0) {
            $this->logger->debug('HCS0005 Unable to determine total RAM');

            return Health::SCORE_DEGRADED;
        }

        $ramFreeRatio = $freeRam / $totalRam;

        if ($ramFreeRatio < self::ACCEPTABLE_AVAILABLE_RAM) {
            $this->logger->debug('HCS0006 Total available RAM is less than the threshold', ['freeRam' => $freeRam]);

            return Health::SCORE_DOWN;
        }

        return Health::SCORE_OK;
    }

    /**
     * Calculate Zpool health between -1 and 1.
     *  - If pool is imported, set score to OK
     *  - If pool is imported and one or more drives are resilvering, set score to DEGRADED
     *  - If RAID thresholds exist, check the amount of drives that mark the pool as DOWN based on RAID level
     *  - If pool is imported and the amount of space available on the pool is less than 25%, set score to DOWN
     *
     * @return int
     */
    public function calculateZpoolHealthScore(): int
    {
        $health = $this->calculateZpoolDriveHealth();

        $zpoolProperties = $this->zpoolService->getZpoolProperties(ZpoolService::HOMEPOOL);

        if ($zpoolProperties !== null) {
            // if 75% or more space is used, reduce the score
            if ($zpoolProperties->getCapacity() >= self::ACCEPTABLE_USED_CAPACITY) {
                $this->logger->warning('HCS0007 Zpool capacity exceeds threshold', ['currentZpoolCapacity' => $zpoolProperties->getCapacity()]);

                $health = Health::SCORE_DOWN;
            }
        }

        return max(Health::SCORE_DOWN, $health);
    }

    /**
     * Logic to determine pool degradation based on RAID levels
     *
     * @return int
     */
    private function calculateZpoolDriveHealth(): int
    {
        $health = Health::SCORE_DOWN;

        if ($this->zpoolService->isImported(ZpoolService::HOMEPOOL)) {
            $health = Health::SCORE_OK;
        }

        try {
            $zpoolStatus = $this->zpoolService->getZpoolStatus(ZpoolService::HOMEPOOL);
            $raidLevel = $this->zpoolService->getRaidLevel(ZpoolService::HOMEPOOL);

            $unavailableDevices = $zpoolStatus->getUnavailableGroup();

            if (count($unavailableDevices) >= self::UNAVAILABLE_MINIMUM_DEGRADATION) {
                $this->logger->debug('HCS0008 Number of unavailable drives in Zpool exceeds the minimum allowed', ['totalUnavailableDrives' => count($unavailableDevices)]);

                $health = Health::SCORE_DEGRADED;

                if (array_key_exists($raidLevel, self::RESILVERING_DRIVES_IN_RAID_DOWN_THRESHOLDS)) {
                    $driveMaximum = self::RESILVERING_DRIVES_IN_RAID_DOWN_THRESHOLDS[$raidLevel]['drives'];
                    $prefix = self::RESILVERING_DRIVES_IN_RAID_DOWN_THRESHOLDS[$raidLevel]['prefix'];

                    if ($prefix === null && count($unavailableDevices) >= $driveMaximum) {
                        $this->logger->warning('HCS0009 Number of unavailable drives exceeds the threshold in RAID configuration', ['totalUnavailableDrives' => count($unavailableDevices)]);

                        $health = Health::SCORE_DOWN;
                    } else {
                        $drivesInRaid = $zpoolStatus->getDrivesInRaid($prefix);

                        foreach ($drivesInRaid as $vdevName => $vdev) {
                            if (empty($vdev['devices'])) {
                                continue;
                            }

                            $unavailableDevicesInVdev = 0;

                            foreach ($vdev['devices'] as $deviceName => $device) {
                                if (!$zpoolStatus->isDriveAvailable($deviceName)) {
                                    $unavailableDevicesInVdev++;
                                }
                            }

                            if ($unavailableDevicesInVdev >= $driveMaximum) {
                                $this->logger->warning('HCS0009 Number of unavailable drives exceeds the threshold in RAID configuration', ['totalUnavailableDrives' => $unavailableDevicesInVdev]);

                                $health = Health::SCORE_DOWN;
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('HCS0010 Unable to determine Zpool status');

            $health = Health::SCORE_DEGRADED;
        }

        return $health;
    }
}
