<?php

namespace Datto\System\Smart;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Utility\Disk\SmartData;
use Datto\Utility\Storage\Zpool;
use Datto\Utility\Storage\ZpoolStatusParser;
use Datto\ZFS\ZpoolService;

/**
 * This is a snapctl translation of the getSMARTSummary.sh script that tech
 * support uses. This digests smartctl output and and presents warnings and
 * alerts. Original script credit to Brian Duff.
 *
 * @author Justin Giacobbi <justin@datto.com>
 * @author Brian Duff <bduff@datto.com>
 */
class SmartService
{
    // Based on https://kb.datto.com/hc/en-us/articles/204427040 and set for US values, not UK
    const THRESHOLDS = [
        "Raw_Read_Error_Rate" => 200,
        "Reallocated_Sector_Ct" => 5,
        "Seek_Error_Rate" => 5,
        "Temperature_Celsius" => 100,
        "Reallocated_Event_Count" => 50,
        "Current_Pending_Sector" => 5,
        "Offline_Uncorrectable" => 5,
        "UDMA_CRC_Error_Count" => 0,
        "Multi_Zone_Error_Rate" => 150
    ];

    /** @var Filesystem */
    private $filesystem;

    /** @var SmartData */
    private $smartData;

    /** @var Zpool */
    private $zpool;

    /**
     * @param Filesystem|null $filesystem
     * @param SmartData|null $smartData
     * @param Zpool|null $zpool
     */
    public function __construct(
        Filesystem $filesystem = null,
        SmartData $smartData = null,
        Zpool $zpool = null
    ) {
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->smartData = $smartData ?: new SmartData();
        $this->zpool = $zpool ?: new Zpool(new ProcessFactory(), new ZpoolStatusParser(new ProcessFactory()));
    }

    /**
     * Generates the full smart analysis
     *
     * @return array
     */
    public function getAllDiskData(): array
    {
        $report = [];

        foreach ($this->smartData->getDisks() as $disk) {
            $report["disks"][$disk] = $this->getDiskData($disk);
        }

        return $report;
    }

    /**
     * Gets SMART data for a specified disk
     *
     * @param string $disk e.g. /dev/sda
     * @return array
     */
    public function getDiskData(string $disk): array
    {
        $data = [];
        $data["disk"] = $disk;
        $data["id"] = $this->getDiskById($disk);
        $data["ssd"] = $this->isSsd(basename($disk));
        $data["info"] = $this->smartData->getInfo($disk);
        $data["attributes"] = $this->smartData->getAttributes($disk);
        if (!$data["ssd"]) {
            $data["testable"] = $this->filterUntestableAttributes($data["attributes"]);
            $data["warnings"] = $this->getThresholdReport($data["attributes"]);
        }

        return $data;
    }

    /**
     * Strips out keys that we do not check thresholds for
     *
     * @param array $attributes
     * @return array
     */
    private function filterUntestableAttributes(array $attributes): array
    {
        return array_intersect_key($attributes, self::THRESHOLDS);
    }

    /**
     * Generates a smart report for a disk. Takes an array containing the path,
     * whether or not the disk is an SSD, the info section and the attribute section
     *
     * @param array $data
     * @return array
     */
    public function getThresholdReport(array $data): array
    {
        $warnings = [];

        $testable = $this->filterUntestableAttributes($data);

        foreach ($testable as $attribute => $value) {
            if (ctype_digit($value)) {
                if ($value > self::THRESHOLDS[$attribute]) {
                    $warnings[$attribute] = [
                        "threshold" => self::THRESHOLDS[$attribute],
                        "value" => $value
                    ];
                }
            } else {
                $warnings[$attribute] = "error";
            }
        }

        return $warnings;
    }

    /**
     * Get a smart report on a disk
     *
     * @param string $disk e.g. /dev/sda
     * @return array
     */
    public function getDiskReport($disk): array
    {
        $disks["disks"] = [];
        $disks["arrayWarning"] = false;

        if (strpos($disk, "/dev/md") !== false) {
            $slaves = $this->filesystem->scandir("/sys/block/" . basename($disk) . "/slaves");
            $slaves = array_diff($slaves, ['.', '..']);
            foreach ($slaves as $basePartition) {
                $slave = $this->stripPartition("/dev/" . $basePartition);
                $slaveInfo = $this->smartData->getInfo($slave);
                $disks["disks"][$slave] = $slaveInfo["Serial Number"];
            }

            if (preg_match("#U_|_U#", $this->filesystem->fileGetContents("/proc/mdstat"))) {
                $disks["arrayWarning"] = true;
            }
        } else {
            $osDiskInfo = $this->smartData->getInfo($disk);
            $disks["disks"][$disk] = $osDiskInfo["Serial Number"];
        }

        return $disks;
    }

    /**
     * Get the zpool status and individual member disk list and status
     *
     * @return array
     */
    public function getZpoolReport(): array
    {
        $diskType = "array";
        $zpoolInfo = ["alto2" => false, "array" => [], "cache" => []];
        if ($this->filesystem->fileGetContents("/datto/config/model") === "ALTO2") {
            $zpoolInfo["alto2"] = true;
        } else {
            $zpoolStatus = $this->getZpoolStatus();

            preg_match("#state: ([a-zA-Z]+)#", $zpoolStatus, $state);
            $zpoolInfo["state"] = $state[1];

            $zpoolLines = array_filter(explode("\n", $zpoolStatus));

            foreach ($zpoolLines as $line) {
                $parts = array_values(array_filter(preg_split("#\s+#", $line)));

                if ($parts[0] === "cache") {
                    $diskType = "cache";
                } elseif (preg_match("#ata-|md-|scsi-#", $parts[0])) {
                    $zpoolInfo[$diskType][$parts[0]] = [
                        "diskPath" => $this->filesystem->realpath("/dev/disk/by-id/{$parts[0]}"),
                        "status" => $parts[1]
                    ];
                }
            }
        }

        return $zpoolInfo;
    }

    /**
     * Checks if a disk is an SSD
     *
     * @param string $short The short name e.g. (sda)
     * @return bool
     */
    public function isSsd($short): bool
    {
        $path = "/sys/block/$short/queue/rotational";
        if ($this->filesystem->exists($path)) {
            return intval($this->filesystem->fileGetContents($path)) === 0;
        }
        return false;
    }

    /**
     * Gets a disk by-id path
     *
     * @param string $disk The disk (e.g. /dev/sda)
     * @return string
     */
    private function getDiskById($disk): string
    {
        $diskIDPath = "";

        foreach ($this->filesystem->glob("/dev/disk/by-id/*") as $idPath) {
            if ($this->filesystem->realpath($idPath) === $disk) {
                $diskIDPath = $idPath;
                break;
            }
        }

        return $diskIDPath;
    }

    /**
     * Gets the zpool status
     *
     * @return string
     */
    private function getZpoolStatus(): string
    {
        return $this->zpool->getStatus(ZpoolService::HOMEPOOL, Zpool::USE_DEFAULT_PATH);
    }

    /**
     * Strips the partition off and leaves you with the parent device (e.g. /dev/sda)
     *
     * @param string $partition
     * @return string
     */
    private function stripPartition($partition): string
    {
        if (preg_match("|(\/dev\/md[0-9]*)p.*|", $partition, $matches)) {
            if (isset($matches[1])) {
                return $matches[1];
            }
        } else {
            foreach ($this->smartData->getDisks() as $disk) {
                if (strpos($partition, $disk) !== false) {
                    return $disk;
                }
            }
        }

        throw new \Exception("Unable to get parent device of " . $partition);
    }
}
