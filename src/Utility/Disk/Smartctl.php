<?php

namespace Datto\Utility\Disk;

use Datto\Common\Resource\ProcessFactory;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * A thin wrapper around the `smartctl` command used to get information from ATA, NVMe, and SAS drives on the
 * system.
 *
 * @author Geoff Amey <gamey@datto.com>
 */
class Smartctl implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private ProcessFactory $processFactory;

    public function __construct(ProcessFactory $processFactory)
    {
        $this->processFactory = $processFactory;
    }

    /**
     * Scan for a list of the drives on the system, returning info about each.
     *
     * @return string[] The device paths for drives that smartctl can analyze
     */
    public function scan(): array
    {
        $process = $this->processFactory->get(['smartctl', '--json', '--scan-open']);
        $exitCode = $process->run();
        if ($exitCode !== 0) {
            $this->logger->warning('SMT0001 smartctl scan exited with errors', [
                'exitCode' => $exitCode
            ]);
        }
        $devices = json_decode($process->getOutput(), true)['devices'] ?? null;
        $deviceNames = [];
        if (is_array($devices)) {
            foreach ($devices as $device) {
                // New Dell RAID cards in JBOD mode show this device and it is causing log noise
                if ($device['name'] !== '/dev/bus/0') {
                    $deviceNames[] = $device['name'];
                }
            }
        }
        return $deviceNames;
    }

    /**
     * Get all the information from a drive at the given path
     *
     * @return array The decoded JSON response from the `smartctl` command
     */
    public function getAll(string $path): array
    {
        $process = $this->processFactory->get([
            'smartctl',
            '--all',
            '--json',
            '--tolerance=permissive',
            '--smart=on',
            $path
        ]);
        $exitCode = $process->run();
        if ($exitCode !== 0) {
            $this->logger->warning('SMT0002 smartctl exited with errors', [
                'path' => $path,
                'exitCode' => $exitCode
            ]);
        } else {
            $this->logger->info('SMT0003: smartctl output received', [
                'output' => $process->getOutput(),
                'path' => $path
            ]);
        }
        return json_decode($process->getOutput(), true) ?? [];
    }
}
