<?php

namespace Datto\App\Console\Command\System;

use Datto\System\Smart\SmartService;
use Datto\System\Storage\StorageService;
use Datto\Utility\Disk\SmartData;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;

/**
 * Print the smart status summary
 *
 * @author Justin Giacobbi <justin@datto.com>
 */
class SmartStatusCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'system:smart';

    const RED = "\033[31m";
    const GREEN = "\033[32m";
    const YELLOW = "\033[33m";
    const BLUE = "\033[34m";
    const MAGENTA = "\033[35m";
    const CYAN = "\033[36m";

    const CLOSE = "\033[0m";

    const BOLD = "\033[1m";
    const UNDERLINE = "\033[4m";

    const SEPARATOR = "=========================";

    /**
     * Custom status value to indicate catastrophic failure, i.e. smartctl couldn't even complete.
     *
     * The smartctl exit statuses are a bitmask on an 8-bit value.  This value was chosen so as not
     * to overlap with the "real" statuses.
     */
    const STATUS_CHECK_FAILED = 256;

    /**
     * A summary of critical issues
     */
    private $summary = "";

    /** @var SmartData */
    private $smartData;

    /** @var SmartService */
    private $smartService;

    /** @var StorageService */
    private $storageService;

    /**
     * @param SmartData $smartData
     * @param SmartService $smartService
     * @param StorageService $storageService
     */
    public function __construct(
        SmartData $smartData,
        SmartService $smartService,
        StorageService $storageService
    ) {
        parent::__construct();

        $this->smartData = $smartData;
        $this->smartService = $smartService;
        $this->storageService = $storageService;
    }
    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Print the disk health summary report')
            ->addOption('checkin-format', null, InputOption::VALUE_NONE, 'Format report for portal update');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('checkin-format')) {
            $output->writeln($this->generatePortalUpdate());
        } else {
            $output->writeln($this->generateFullReport());
        }
        return 0;
    }

    /**
     * Generate SMART report in a machine-readable format that can be passed to checkin.
     * This outputs the same format that was generated by the old checkin code,
     * i.e. a query string with trailing ampersand.
     *
     * @return string
     */
    private function generatePortalUpdate(): string
    {
        try {
            $disks = $this->smartData->getDisks();
        } catch (Throwable $throwable) {
            return '';
        }
        $properties = [];

        foreach ($disks as $disk) {
            // This is called by checkin, so we don't want to die even if the attribute or info calls fail.
            try {
                $status = $this->smartData->enable($disk);
            } catch (Throwable $throwable) {
                $status = self::STATUS_CHECK_FAILED;
            }
            try {
                $attributes = $this->smartData->getAttributes($disk);
            } catch (Throwable $throwable) {
                $attributes = [];
            }
            try {
                $info = $this->smartData->getInfo($disk);
            } catch (Throwable $throwable) {
                $info = [];
            }

            $drive = basename($disk);
            $keyPrefix = sprintf("smart_%s_", $drive);

            foreach ($attributes as $key => $value) {
                $properties[$keyPrefix . $key] = $value;
            }

            $properties['smartStatus_' . $drive] = $status;
            $properties['smartModel_' . $drive] = preg_replace('/\s+/', '_', $info['Device Model'] ?? '');
            $properties['smartSerial_' . $drive] = preg_replace('/\s+/', '_', $info['Serial Number'] ?? '');
            $driveInfo = $this->storageService->getPhysicalDeviceByPath($disk);
            $driveType = '';
            if (preg_match("#/dev/nvme#", $disk)) {
                $driveType = 'NVME';
            } elseif (!empty($driveInfo) && $driveInfo->isRotational() !== null) {
                $driveType = $driveInfo->isRotational() ? 'HDD' : 'SSD';
            } else {
                $this->logger->info(
                    "SSC0001 Cannot determine drive type (SSD/HDD) for device drive",
                    ['drive-serial' => $properties['smartSerial_' . $drive], 'disk' => $disk]
                );
            }
            $properties['smartDriveType_' . $drive] = $driveType;
        }

        return http_build_query($properties) . '&';
    }

    /**
     * Generate a printable disk health report
     *
     * @return string
     */
    private function generateFullReport() : string
    {
        $data = $this->smartService->getAllDiskData();
        $osDisk = $this->storageService->getOsDrive()->getName();

        $out = self::GREEN .
            str_pad(" Start SMART Status Script ", 55, "*", STR_PAD_BOTH) . "\n\n" .
            self::CLOSE;

        // disk section
        foreach ($data["disks"] as $disk) {
            $out .= $this->generateDiskReport($disk);
        }

        $out .= self::BLUE . self::SEPARATOR . self::CLOSE . "\n\n";
        $out .= $this->generateOSReport($this->smartService->getDiskReport($osDisk));
        try {
            $out .= $this->generateZpoolReport($this->smartService->getZpoolReport());
        } catch (\Exception $e) {
            $out .= self::RED . "Failed to get zpool status\n" . self::CLOSE;
        }

        if (!empty($this->summary)) {
            $out .= self::BLUE . self::SEPARATOR . self::CLOSE . "\n\n";

            $out .= self::BOLD .
                "Summary of all SMART attributes that meet replacement:\n" .
                self::CLOSE .
                self::RED .
                $this->summary . "\n" .
                self::CLOSE;
        }

        $out .= self::GREEN .
            str_pad(" End SMART Status Script ", 55, "*", STR_PAD_BOTH) . "\n" .
            self::CLOSE;

        return $out;
    }

    /**
     * Generate a printable report for a single disk
     *
     * @param array $data array of disk information
     * @return string
     */
    private function generateDiskReport(array $data) : string
    {
        $out  = self::YELLOW . "*** " . $data["disk"] . self::CLOSE . "\n";
        $out .= "Drive capacity: " .
            ($data["info"]["User Capacity"] ?:
            self::YELLOW . "not reporting size" . self::CLOSE) . "\n";
        $out .= "Is SSD: " .
            ($data["ssd"]
             ? self::RED . "*****YES*****"
             : self::GREEN . "No")
            . self::CLOSE . "\n";
        $out .= "Power on hours: " . $this->getPowerHours($data["attributes"]) . "\n";
        $out .= "Serial Number: " . $data["info"]["Serial Number"] . "\n";
        $id = $data["id"];
        $out .= "ID: $id\n";

        if ($data["ssd"]) {
            $out .= self::YELLOW
                 .  "Solid State Drives do not accurately report SMART Status\n"
                 .  "Please refer to hardware logs to determine if an SSD is failing\n"
                 .  self::CLOSE;
        } else {
            $out .= "\n" . $this->attributeBlock($data["testable"]) ."\n";
            $out .= "Any values that meet replacement criteria for " . $data["disk"] . ":\n";
            if (strpos("$id", "ata-ST") !== false) {
                $out .= self::RED
                    .  "This is a Seagate drive. Please take the results with a grain of salt per this article: "
                    . self::CLOSE
                    . "http://knowledge.seagate.com/articles/en_US/FAQ/203971en?language=en_US"
                    . self::YELLOW . self::BOLD
                    . "Please remember that these third-party programs do not have proprietary access 
                    to Seagate hard disk information, and therefore often provide inconsistent\n
                    and inaccurate results.  
                    SeaTools is more consistent and more accurate and is the standard Seagate uses to 
                    determine hard drive failure.\n"
                    . self::CLOSE;

                $this->summary .= self::YELLOW . self::BOLD
                    . "*****There are one or more Seagate drives on this Datto. Please use caution with the reported SMART values!*****\n"
                    . self::CLOSE;
            } elseif (strpos("$id", "ata-Hitachi") !== false) {
                $out .= self::YELLOW
                     . "Hitachi drives do not accurately report 'Raw_Read_Error_Rate'\n"
                     . self::CLOSE;
            }

            foreach ($data["warnings"] as $attribute => $warning) {
                if (is_array($warning)) {
                    $error = "$attribute greater than " . $warning["threshold"] . "\n";
                } else {
                    $error = "There was an issue checking the $attribute\n";
                }

                if (isset($error)) {
                    $error = self::RED . $error . self::CLOSE;
                    $out .= $error;
                    $this->summary .= $data["disk"] . "--$error";
                    unset($error);
                }
            }
        }

        $out .= "\n";
        return $out;
    }

    /**
     * Generate a printable report for the OS disks
     * @param array $data array of disk information
     * @return string
     */
    private function generateOSReport(array $data): string
    {
        $out = self::BOLD . "OS Drive(s):" . self::CLOSE . "\n";

        foreach ($data["disks"] as $disk => $serial) {
            $out .= "$disk - Serial Number: $serial\n";
        }

        if ($data["arrayWarning"]) {
            $out .= self::RED . self::BOLD .
                "One of the OS array drives has fallen out of the array\n" .
                self::CLOSE;
        }

        $out .= "\n";
        return $out;
    }

    /**
     * Generate a printable report of zpool health
     *
     * @param array $data array of disk information
     * @return string
     */
    private function generateZpoolReport(array $data): string
    {
        if ($data["alto2"]) {
            $out = self::YELLOW .
                "ALTO2 array drives are added by /dev/sdX and will not show up here" .
                self::CLOSE;
        } else {
            $out = self::BOLD . "homePool array drives: \n" . self::CLOSE;

            if ($data["state"] !== "ONLINE") {
                $message = "homePool has a bad status: {$data["state"]}\n";
                $out .= $message;
                $this->summary .= $message;
            }

            $goodString = self::GREEN . "Online\n" . self::CLOSE;
            $badString = self::RED . "Problem\n" . self::CLOSE;

            foreach ($data["array"] as $diskID => $info) {
                $out .= "$diskID => {$info["diskPath"]}   " .
                    (($info["status"] === "ONLINE") ? $goodString : $badString);
            }

            $out .= "\nCache drive:\n";

            foreach ($data["cache"] as $diskID => $info) {
                $out .= "$diskID => " . $info["diskPath"] . "   " .
                    (($info["status"] === "ONLINE") ? $goodString : $badString);

                if ($info["status"] !== "ONLINE") {
                    $this->summary .= "$diskID => {$info["diskPath"]} has a bad zpool status\n";
                }
            }
        }

        $out .= "\n";
        return $out;
    }

    /**
     * Gets power on hours from the info section. The power on hours key
     * can change based on disk type.
     *
     * @param array $data the attributes
     * @return string
     */
    private function getPowerHours(array $data): string
    {
        $notReportingError = self::YELLOW . "not reporting power on hours" . self::CLOSE;
        $keys = array_keys($data);
        $powerOnHoursKeys = preg_grep("#Power_On_Hours#", $keys);
        if ($powerOnHoursKeys === false) {
            return $notReportingError;
        }

        $key = @reset($powerOnHoursKeys);
        return @$data[$key] ?: $notReportingError;
    }

    /**
     * Helper function to make a printable attribute block for a disk
     *
     * @param array $data The transformed attribute section
     * @return string
     */
    private function attributeBlock(array $data): string
    {
        $out = self::CYAN . str_pad("Attribute Name", 26) . "Raw Value\n" . self::CLOSE;

        foreach ($data as $field => $value) {
            $out .= str_pad($field, 26) . "$value\n";
        }

        return $out;
    }
}
