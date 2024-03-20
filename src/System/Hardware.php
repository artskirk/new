<?php

namespace Datto\System;

use Datto\Common\Resource\ProcessFactory;
use Datto\Utility\ByteUnit;
use Datto\Common\Utility\Filesystem;
use Datto\Virtualization\HypervisorType;

/**
 * This class determines the type of hardware we're running on
 * @author Matt Cheman <mcheman@datto.com>
 */
class Hardware
{
    const PROC_CPUINFO = '/proc/cpuinfo';

    private ?HypervisorType $detectedHypervisor = null;
    private ProcessFactory $processFactory;
    private Filesystem $filesystem;

    public function __construct(
        ProcessFactory $processFactory = null,
        Filesystem $filesystem = null
    ) {
        $this->processFactory = $processFactory ?: new ProcessFactory();
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
    }

    /**
     * Detect the presence of a hypervisor.
     * Sets some config keys according to the hypervisor found.
     */
    public function detectHypervisor(): ?HypervisorType
    {
        if ($this->detectedHypervisor === null) {
            $name = $this->getSystemProductName();

            if (preg_match('/vmware/i', $name)) {
                $this->detectedHypervisor = HypervisorType::VMWARE();
            } elseif ($name === 'Virtual Machine') {
                $this->detectedHypervisor = HypervisorType::HYPER_V();
            } else {
                $this->detectedHypervisor = null;
            }
        }

        return $this->detectedHypervisor;
    }

    /**
     * Get number of cpu cores in this machine
     */
    public function getCpuCores(): int
    {
        $cpuInfo = $this->filesystem->fileGetContents(self::PROC_CPUINFO);

        // processor    : 0
        if ($cpuInfo !== false && preg_match_all('/^processor\b/m', $cpuInfo, $matches, PREG_SET_ORDER)) {
            return count($matches);
        }

        return 0;
    }

    /**
     * Get CPU Model Name
     */
    public function getCpuModel(): string
    {
        $cpuInfo = $this->filesystem->fileGetContents(self::PROC_CPUINFO);

        // model name   : Intel Xeon E3-12xx v2 (Ivy Bridge)
        if ($cpuInfo !== false && preg_match('/^model name\s:\s(.*)$/m', $cpuInfo, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Does the CPU support HW assisted virtualization
     */
    public function supportsHwAssistedVirt(): bool
    {
        $cpuInfo = $this->filesystem->fileGetContents(self::PROC_CPUINFO);

        if ($cpuInfo !== false) {
            // flags        : fpu vme de pse tsc msr pae mce vmx cx8 apic sep mtrr pge mca
            return preg_match('/^flags\s+:\s+.*(vmx|svm)/m', $cpuInfo) == 1;
        }

        return false;
    }

    /**
     * Total Physical RAM in MiB
     */
    public function getPhysicalRamMiB(): int
    {
        $totalMiB = 0;

        // Parsing /proc/meminfo is an alternative, but it does not return the exact physical memory capacity
        $output = $this->getDmiDecodeType('memory');
        
        if (preg_match_all('/^\s*Size:\s+(\d+)\s+(MB|GB)$/m', $output, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $value = $match[1];
                if ($match[2] == 'GB') {
                    $value = ByteUnit::GIB()->toMiB($value);
                }
                $totalMiB += intval($value);
            }
        }

        return $totalMiB;
    }

    /**
     * Get description of storage controller
     */
    public function getStorageController(): string
    {
        $proc = $this->processFactory->get(['lspci']);
        $proc->run();

        if (preg_match('/Serial Attached SCSI controller:\s+(.*)$/m', $proc->getOutput(), $matches)) {
            return $matches[1];
        } elseif (preg_match('/RAID bus controller:\s+(.*)$/m', $proc->getOutput(), $matches)) {
            return $matches[1];
        } elseif (preg_match('/SATA controller:\s+(.*)$/m', $proc->getOutput(), $matches)) {
            return 'onboard';
        } else {
            return 'N/A';
        }
    }

    /**
     * If you're looking for the motherboard model of a physical device, this is the recommended method.
     *
     * Note: although typically the same, this value may differ from 'system-product-name'.
     */
    public function getBaseboardProductName(): string
    {
        return $this->getDmidecodeString('baseboard-product-name');
    }

    /**
     * Note: although typically the same, this value may differ from 'baseboard-product-name'.
     */
    public function getSystemProductName(): string
    {
        return $this->getDmidecodeString('system-product-name');
    }

    public function getSystemUuid(): string
    {
        return $this->getDmidecodeString('system-uuid');
    }

    public function getSystemSerialNumber(): string
    {
        return $this->getDmidecodeString('system-serial-number');
    }

    /**
     * Get a known string value from dmidecode
     */
    private function getDmidecodeString(string $key) : string
    {
        $proc = $this->processFactory->get(['dmidecode', '-s', $key]);
        $proc->run();
        return trim($proc->getOutput());
    }

    /**
     * Get complete type output from dmidecode
     */
    private function getDmiDecodeType(string $type) : string
    {
        $proc = $this->processFactory->get(['dmidecode', '-t', $type]);
        $proc->run();
        return $proc->getOutput();
    }
}
