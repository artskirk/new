<?php

namespace Datto\Utility\Disk;

use Datto\Common\Resource\ProcessFactory;
use Exception;

/**
 * Retrieves smart data
 *
 * @author Justin Giacobbi
 *
 * @deprecated Use Datto\Utility\Disk\Smartctl instead, which returns a structured array parsed from JSON.
 */
class SmartData
{
    const SMARTCTL = "smartctl";
    const SATA_ATTRIBUTE_HEADER_LINES = 6;
    const NVME_ATTRIBUTE_HEADER_LINES = 4;
    const INFO_HEADER_LINES = 3;

    /** @var string[] */
    private $disks = [];

    /** @var string[] */
    private $info = [];

    /** @var string[] */
    private $attributes = [];

    /** @var ProcessFactory */
    private $processFactory;

    /** @var bool */
    private $initialized = false;

    /**
     * @param ProcessFactory|null $processFactory
     */
    public function __construct(ProcessFactory $processFactory = null)
    {
        $this->processFactory = $processFactory ?: new ProcessFactory();
    }

    /**
     * Enable SMART monitoring for a disk
     *
     * @param string $disk The drive for which to enable SMART
     * @return int Exit code of the enable call
     */
    public function enable(string $disk): int
    {
        $process = $this->processFactory->get([self::SMARTCTL, $disk, '-s', 'on']);
        $process->run();
        return $process->getExitCode();
    }

    /**
     * Get a list of disks
     *
     * @return string[]
     */
    public function getDisks(): array
    {
        $this->initialize();

        return $this->disks;
    }

    /**
     * Gets the SMART info section for a disk
     *
     * @param string $disk the path to the disk
     * @return string[]
     */
    public function getInfo(string $disk): array
    {
        $this->initialize();

        if (!$this->isSmartDisk($disk)) {
            throw new Exception("$disk is not a SMART disk");
        }

        if (!isset($this->info[$disk])) {
            $this->info[$disk] = $this->getInfoSection($disk);
        }

        return $this->info[$disk];
    }

    /**
     * Gets the SMART attribute section for a disk
     *
     * @param string $disk the path to the disk
     * @return string[]
     */
    public function getAttributes(string $disk): array
    {
        $this->initialize();

        if (!$this->isSmartDisk($disk)) {
            throw new Exception("$disk is not a SMART disk");
        }

        if (!isset($this->attributes[$disk])) {
            $this->attributes[$disk] = $this->getAttributeSection($disk);
        }

        return $this->attributes[$disk];
    }

    /**
     * Gets the smartctl info section for a disk and translates it into
     * key-value pairs
     *
     * @param string $disk The disk to check ie /dev/sda
     * @return string[]
     */
    private function getInfoSection(string $disk): array
    {
        $raw = $this->splitCommandOutput($this->getRawReport($disk, true));
        // Don't care about the header
        $raw = array_slice($raw, self::INFO_HEADER_LINES);

        $out = [];

        foreach ($raw as $info) {
            $parts = explode(":", $info, 2);
            if (count($parts) === 2) {
                $out[$parts[0]] = trim($parts[1]);
            }
        }

        return $this->normalizeInfo($out);
    }

    /**
     * For NVMe drives, some properties are not present named differently than for
     * SATA drives.  This normalizes the array so that the same key names exist
     * for both types of drive.
     *
     * @param string[] $info
     * @return string[] Associative array of names to values
     */
    private function normalizeInfo(array $info): array
    {
        if (!isset($info['User Capacity'])) {
            $matchingKeys = preg_grep("#Size/Capacity#", array_keys($info));
            if ($matchingKeys) {
                $key = array_shift($matchingKeys);
                $value = $info[$key];
                $info['User Capacity'] = $value;
            }
        }
        if (!isset($info['Device Model'])) {
            $info['Device Model'] = $info['Model Number'] ?? '';
        }
        return $info;
    }

    /**
     * Gets the smartctl attribute section for a disk and translates it into
     * key-value pairs
     *
     * @param string $disk The disk to check ie /dev/sda
     * @return string[] Associative array of attribute names to values
     */
    private function getAttributeSection(string $disk): array
    {
        $raw = $this->splitCommandOutput($this->getRawReport($disk, false));
        $headerLine = $raw[6] ?? '';
        if (strpos($headerLine, "ID#") === 0) {
            return $this->parseSataAttributes($raw);
        }
        $attributes = $this->parseNvmeAttributes($raw);
        return $this->normalizeAttributes($attributes);
    }

    /**
     * For SATA drives, attributes are in a table format, with name in column
     * two and value in column ten.
     *
     * @param string[] $lines
     * @return string[] Associative array of attribute names to values
     */
    private function parseSataAttributes(array $lines): array
    {
        $out = [];
        $lines = array_slice($lines, self::SATA_ATTRIBUTE_HEADER_LINES);

        foreach ($lines as $info) {
            $parts = preg_split("#\s+#", trim($info));
            $key = $parts[1];
            // column one is attribute name, column nine is lines value
            if (!isset($out[$key])) {
                $out[$key] = $parts[9];
            }
        }

        return $out;
    }


    /**
     * For NVMe drives, attributes are in the same "Key: Value" format as info.
     *
     * @param string[] $lines
     * @return string[] Associative array of attribute names to values
     */
    private function parseNvmeAttributes(array $lines): array
    {
        $out = [];
        $lines = array_slice($lines, self::NVME_ATTRIBUTE_HEADER_LINES);

        foreach ($lines as $info) {
            $parts = explode(":", $info, 2);
            $out[$parts[0]] = trim($parts[1]);
        }

        return $out;
    }

    /**
     * Some attributes are named differently for NVMe drives than for SATA.  This
     * normalizes the array so that the same keys are present for both types.
     *
     * @param string[] $attributes
     * @return string[]
     */
    private function normalizeAttributes(array $attributes): array
    {
        // Reformat the critical warning key, if present
        if (isset($attributes['Critical Warning'])) {
            $attributes['Critical_Warning'] = (string)hexdec($attributes['Critical Warning']);
            unset($attributes['Critical Warning']);
        }

        // Make all the key names underscore-separated words.
        $keys = array_keys($attributes);
        foreach ($keys as $key) {
            $cleanedKey = preg_replace("/\W+/", '_', $key);
            // Some values are comma-formatted integers, so remove the commas and truncate.
            $cleanedValue = (int)preg_replace('/,/', '', $attributes[$key]);
            // Note: Clients expect values to be strings.
            $attributes[$cleanedKey] = (string)$cleanedValue;
            if ($cleanedKey !== $key) {
                unset($attributes[$key]);
            }
        }

        if (!isset($attributes['Temperature_Celsius'])) {
            $temperature = null;
            if (isset($attributes['Temperature'])) {
                $temperature = (int)$attributes['Temperature'];
                $temperature = (string) $temperature;
                unset($attributes['Temperature']);
            }
            $attributes['Temperature_Celsius'] = $temperature;
        }
        if (!isset($attributes['Power_On_Hours'])) {
            // Make sure this is always set since clients look for it.
            $attributes['Power_On_Hours'] = '';
        }
        if (!isset($attributes['Power_Cycle_Count'])) {
            $attributes['Power_Cycle_Count'] = $attributes['Power_Cycles'] ?? '';
            unset($attributes['Power_Cycles']);
        }


        return $attributes;
    }

    /**
     * Take command output and split it into an array, omitting empty lines.
     * @param string $output
     * @return string[]
     */
    private function splitCommandOutput(string $output): array
    {
        $filter = function (string $value) {
            return trim($value) !== "";
        };
        $lines = explode("\n", $output);
        return array_filter($lines, $filter);
    }

    /**
     * Gets the raw smartctl info or attribute section
     *
     * @param string $disk
     * @param bool $info Optional, defaults to false which means get the attribute section
     * @return string
     */
    private function getRawReport(string $disk, bool $info = false): string
    {
        $switch = $info ? "-i" : "-A"; //info or attributes

        $process = $this->processFactory->get([self::SMARTCTL, $switch, $disk]);
        $process->mustRun();
        return trim($process->getOutput());
    }

    private function initialize()
    {
        if (!$this->initialized) {
            $this->disks = $this->getSmartDisks();
            $this->initialized = true;
        }
    }

    /**
     * Get all /dev/sdX values
     *
     * sample output:
     * root@bmrsiris:~# smartctl --scan
     * /dev/sda -d scsi # /dev/sda, SCSI device
     * /dev/sdb -d scsi # /dev/sdb, SCSI device
     * /dev/sdc -d scsi # /dev/sdc, SCSI device
     *
     * @return string[]
     */
    private function getSmartDisks(): array
    {
        $process = $this->processFactory->get([self::SMARTCTL, '--scan']);
        $process->run();
        return array_map(
            function ($value) {
                return preg_split("#\s+#", $value)[0];
            },
            $this->splitCommandOutput($process->getOutput())
        );
    }

    /**
     * @param string $device
     * @return bool
     */
    private function isSmartDisk(string $device): bool
    {
        if (in_array($device, $this->disks)) {
            return true;
        }

        $name = basename($device);
        if (strpos($name, 'nvme') === 0) {
            $parentDevice = preg_replace('|(.+nvme\d+)n.+|', '$1', $device);
            return in_array($parentDevice, $this->disks);
        }

        return false;
    }
}
