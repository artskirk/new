<?php

namespace Datto\System;

use Datto\Common\Resource\ProcessFactory;
use Datto\Tests\System\ResourceMonitorTest;
use Datto\Utility\Block\BlockDevice;
use Datto\Utility\Block\LsBlk;
use Datto\Utility\ByteUnit;
use Datto\Common\Utility\Filesystem;

/**
 * System resource monitor
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class ResourceMonitor
{
    const PROC_MEMINFO = '/proc/meminfo';
    const ZFS_ARCSTATS = '/proc/spl/kstat/zfs/arcstats';
    const PROC_LOADAVG = "/proc/loadavg";

    /** @var Filesystem */
    private $filesystem;

    /** @var ProcessFactory */
    private $processFactory;

    /** @var LsBlk */
    private $lsBlk;

    /**
     * @param Filesystem|null $filesystem
     * @param ProcessFactory|null $processFactory
     */
    public function __construct(Filesystem $filesystem = null, ProcessFactory $processFactory = null, LsBlk $lsBlk = null)
    {
        $this->processFactory = $processFactory ?? new ProcessFactory();
        $this->filesystem = $filesystem ?? new Filesystem($this->processFactory);
        $this->lsBlk = $lsBlk ?? new LsBlk(new ProcessFactory());
    }

    /**
     * Get IOPS/TPS for physical disks
     *
     * @return float[]
     *
     * @see ResourceMonitorTest::IOPS_OUTPUT
     */
    public function getPhysicalDriveIops(): array
    {
        $process = $this->processFactory->get([
            'iostat',
            '-d'
        ]);
        $process->mustRun();

        $physicalDrives = $this->lsBlk->getDiskDrives();

        $output = trim($process->getOutput());
        $lines = explode(PHP_EOL, $output);

        $driveIops = [];

        foreach ($lines as $line) {
            $chunks = preg_split('/\s+/', $line, 3);

            if (empty($chunks) || !$this->physicalDriveExists($physicalDrives, $chunks[0])) {
                continue;
            }

            list($device, $iops,) = $chunks;

            $driveIops[$device] = floatval($iops);
        }

        return $driveIops;
    }

    /**
     * Get average CPU load over the last 1 minute
     *
     * @return float
     */
    public function getCpuAvgLoad(): float
    {
        // 0.37 0.65 0.73 1/835 14065
        $loadAvg = $this->filesystem->fileGetContents(self::PROC_LOADAVG);
        if ($loadAvg !== false) {
            $parts = explode(" ", $loadAvg);
            return floatval($parts[0] ?? 0.0);
        }

        return false;
    }

    /**
     * Get the amount of free RAM in MiB
     *
     * @param bool $addZfsArc should ZFS ARC be added back to free RAM (default true)
     * @return int
     */
    public function getRamFreeMiB($addZfsArc = true): int
    {
        $freeBytes = $this->getAvailableRamBytes();

        if ($addZfsArc) {
            $freeBytes += $this->getAvailableZfsArcBytes();
        }

        return round(ByteUnit::BYTE()->toMiB($freeBytes));
    }

    /**
     * Get ram that is being used by ZFS ARC
     *
     * @return int
     */
    private function getAvailableZfsArcBytes()
    {
        $arcStats = $this->filesystem->fileGetContents(self::ZFS_ARCSTATS);
        // c_min                           4    393113344
        // c_max                           4    6289813504
        // size                            4    0
        if ($arcStats !== false && preg_match_all('/^(c_min|size)\s+\d+\s+(\d+)/m', $arcStats, $matches, PREG_PATTERN_ORDER)) {
            $arcSize = 0;
            $arcMin = 0;

            if (($arcSizeIndex = array_search('size', $matches[1])) !== false) {
                $arcSize = $matches[2][$arcSizeIndex] ?? 0;
            }

            if (($arcCminIndex = array_search('c_min', $matches[1])) !== false) {
                $arcMin = $matches[2][$arcCminIndex] ?? 0;
            }

            return $arcSize - $arcMin;
        }

        return 0;
    }

    /**
     * Get free system ram in bytes
     *
     * @return float
     */
    private function getAvailableRamBytes()
    {
        $memInfo = $this->filesystem->fileGetContents(self::PROC_MEMINFO);

        // MemAvailable:    8130908 kB
        if ($memInfo !== false && preg_match('/MemAvailable:\s+(\d+)/m', $memInfo, $matches)) {
            $availableKiB = $matches[1] ?? 0;

            return round(ByteUnit::KIB()->toByte($availableKiB));
        }

        return 0;
    }

    /**
     * @param BlockDevice[] $drives
     * @param string $shortName
     * @return bool
     */
    private function physicalDriveExists(array $drives, string $shortName): bool
    {
        foreach ($drives as $drive) {
            if ($drive->getName() === $shortName) {
                return true;
            }
        }

        return false;
    }
}
