<?php

namespace Datto\Utility\Disk;

use Datto\Common\Resource\ProcessFactory;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * A thin wrapper around the `mvcli` command used to get information from the Dell BOSS
 * cards that provide Hardware RAID for the OS drives in SIRIS5 devices.
 */
class Mvcli implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private ProcessFactory $processFactory;

    public function __construct(ProcessFactory $processFactory)
    {
        $this->processFactory = $processFactory;
    }

    /**
     * Get the information of a given object
     * @param string $object The object to query (hba, vd, pd, etc...)
     * @param int|null $id The optional ID (e.g. when querying physical disks)
     * @return array<string, string> The parsed key/value pairs
     */
    public function info(string $object, ?int $id = null): array
    {
        $cmdLine = ['mvcli', 'info', '-o', $object];
        if (!is_null($id)) {
            $cmdLine = array_merge($cmdLine, ['-i', $id]);
        }

        try {
            $startTime = time();
            $process = $this->processFactory->get($cmdLine)->setTimeout(180)->mustRun();
            $duration = time() - $startTime;
            $this->logger->info('MVC0101 Execution of mvcli info command duration (seconds)', ['duration' => $duration, 'cmdline' => $cmdLine]);
            $output = $process->getOutput();
            return $this->parseInfo($output);
        } catch (Throwable $exception) {
            $this->logger->warning('MVC0001 Could not get mvcli info', [
                'cmd' => $cmdLine,
                'exception' => $exception
            ]);
        }
        return [];
    }

    /**
     * Get the health attributes from a physical disk
     * @param int $pd The index of the physical disk
     * @return array<array{id: int, name: string, current: int, worst: int, thresh: int, raw: int}>
     */
    public function smart(int $pd): array
    {
        try {
            $startTime = time();
            $cmdLine = ['mvcli', 'smart', '-p', strval($pd)];
            $process = $this->processFactory->get($cmdLine)->setTimeout(180)->mustRun();
            $duration = time() - $startTime;
            $this->logger->info('MVC0102 Execution of mvcli smart command duration (seconds)', ['duration' => $duration, 'cmdline' => $cmdLine]);
            $output = $process->getOutput();
            return $this->parseSmartAttributes($output);
        } catch (Throwable $exception) {
            $this->logger->warning('MVC0002 Could not parse SMART attributes', [
                'pd' => $pd,
                'exception' => $exception
            ]);
        }
        return [];
    }

    /**
     * Parse the key/value pairs from an `mvcli info` command output
     *
     * @param string $output The multiline text from the mvcli info command
     * @return array The parsed key/value pairs
     */
    private function parseInfo(string $output): array
    {
        $retVal = [];
        $lines = explode("\n", $output);
        foreach ($lines as $line) {
            // Skip over the lines that aren't key/value pairs
            if (strpos($line, ':') === false) {
                continue;
            }
            $kvp = explode(':', $line, 2);
            $retVal[trim($kvp[0])] = trim($kvp[1]);
        }
        return $retVal;
    }

    /**
     * Parse the tabular output from an `mvcli smart` command into health attributes
     *
     * @param string $output The multiline text from the `mvcli smart` command
     * @return array<array{id: int, name: string, current: int, worst: int, thresh: int, raw: int}>
     */
    private function parseSmartAttributes(string $output): array
    {
        $attrs = [];
        $smartRegex = '/^([0-9A-F]{2})\s+(\D+?)\s+(\d+)\s+(\d+)\s+(\d+)\s+([0-9A-F]{12})$/';
        // Example Lines:
        // Smart Info
        // ID   Attribute Name              Current Worst   Threshhold  RawValue
        // 05   Reallocated Sectors         100     100     10          000000000000
        // 09   Power-On Hours Count        100     100     0           000000002184

        $lines = explode("\n", $output);
        foreach ($lines as $line) {
            if (preg_match($smartRegex, $line, $matches)) {
                $attrs[] = [
                    'id' => intval($matches[1], 16),
                    'name' => $matches[2],
                    'current' => intval($matches[3]),
                    'worst' => intval($matches[4]),
                    'thresh' => intval($matches[5]),
                    'raw' => intval($matches[6], 16)
                ];
            }
        }
        return $attrs;
    }
}
