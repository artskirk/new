<?php

namespace Datto\Utility\Storage;

use Datto\Common\Resource\ProcessFactory;
use Exception;

/**
 * Creates a ZpoolStatus from command output.
 * This class should not be used directly. Use Zpool and call its getParsedStatus method instead.
 * Since PHP does not have a concept of a friend class, and I wanted to encapsulate the status parsing, this is the best
 * approach I could think of.
 *
 * @author Mario Rial <mrial@datto.com>
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class ZpoolStatusParser
{
    private ProcessFactory $processFactory;

    public function __construct(ProcessFactory $processFactory)
    {
        $this->processFactory = $processFactory;
    }

    /**
     * Retrieves the zpool status and returns the parsed output for the given pool name.
     * This method should not be called directly. Call Zpool's getParsedStatus method instead.
     *
     * @param string $poolName Name of the pool to return the status for
     * @param bool $fullDevicePath If true, return the full path of the zpool devices. If false, return only the device name
     * @return string[]
     */
    public function getParsedZpoolStatus(string $poolName, bool $fullDevicePath, bool $verbose = false): array
    {
        $commandOutput = $this->getZpoolStatus($poolName, $fullDevicePath, $verbose);
        $parsedOutput = $this->parseZpoolStatusOutputIntoSections($commandOutput);

        // Parse config section
        $configLines = explode(PHP_EOL, $parsedOutput['config']);
        $config = $this->parseConfigLines($this->filterConfigLines($configLines));
        $parsedOutput['config'] = $config;

        // Transform string into array of errors
        $parsedOutput['errors'] = explode(PHP_EOL, $parsedOutput['errors']);

        // Prepend the raw output
        $parsedOutput = array_merge(['rawOutput' => $commandOutput], $parsedOutput);

        return $parsedOutput;
    }

    /**
     * Get the pool status
     *
     * @param string $poolName Name of the pool to return the status for
     * @param bool $fullDevicePath If true, return the full path of the zpool devices. If false, return only the device name
     * @param bool $verbose If true, return verbose output of the pool status command. If false, return just the default output.
     * @return string
     */
    private function getZpoolStatus(string $poolName, bool $fullDevicePath, bool $verbose = false): string
    {
        if ($poolName === "") {
            throw new Exception('A storage pool name is required.');
        }

        // The execution of the zpool status command is triggered here instead of depending on Zpool utility class
        // as the process output is needed in order to create a ZpoolStatus object. This is done to encapsulate
        // the process and its output within a single class.
        $command = ['sudo', 'zpool', 'status'];
        if ($fullDevicePath) {
            $command[] = '-P';
        }

        if ($verbose) {
            $command[] = '-v';
        }

        $command[] =  $poolName;

        $process = $this->processFactory->get($command);
        $process->mustRun();
        return $process->getOutput();
    }

    /**
     * Parse the zpool status output into sections
     * Each zpool status section starts with a variable amount of whitespace, a header, and a colon.
     *
     * @param string $commandOutput zpool status command output
     * @return array Keys are the section headers, values are the section contents in a single string
     */
    private function parseZpoolStatusOutputIntoSections(string $commandOutput): array
    {
        $lines = explode("\n", trim($commandOutput));

        $sectionHeader = '';
        $sectionContent = '';
        $sections = [];
        foreach ($lines as $line) {
            // Check for a new section
            if (preg_match("/^[ \t]*(?P<header>\w+):(?P<content>.*)$/", $line, $match)) {
                if (!empty($sectionHeader)) {
                    // Save the previous section
                    $sections[$sectionHeader] = $sectionContent;
                }

                $sectionHeader = $match['header'];
                $sectionContent = trim($match['content']);
            } else {
                $sectionContent .= PHP_EOL . $line;
            }
        }

        if (!empty($sectionHeader)) {
            // Save the previous section
            $sections[$sectionHeader] = $sectionContent;
        }
        return $sections;
    }

    /**
     * Returns an array with the lines of the config section of zpool status.
     *
     * @param array $lines
     * @return array
     */
    private function filterConfigLines(array $lines): array
    {
        $output = [];
        foreach ($lines as $line) {
            $match = [];
            if ($this->matchConfigLine($line, $match)) {
                $output[] = $line;
            }
        }
        return $output;
    }

    /**
     * Parses the config section of zpool status returning an array representation.
     *
     * @param array $outputLines
     * @return array
     */
    private function parseConfigLines(array $outputLines): array
    {
        return $this->parseChildren($this->getRootEntriesLineNos($outputLines), $outputLines);
    }

    /**
     * Matches a given line against the config section pattern. It returns true if the line
     * matched the config section pattern.
     * It also returns the matches in an associative array passed by reference.
     *
     * @param string $line
     * @param array $matches
     * @return bool
     */
    private function matchConfigLine(string $line, array &$matches): bool
    {
        $vdev_pattern  = '/^\t(?P<wspace>\s*)';
        $vdev_pattern .= '(?P<devname>[\/\w.-]+)\s*(?P<state>\w*)\s*';
        $vdev_pattern .= '(?P<read>[\d\w-]*)\s*';
        $vdev_pattern .= '(?P<write>[\d\w-]*)\s*';
        $vdev_pattern .= '(?P<cksum>[\d\w-]*)\s*';
        $vdev_pattern .= '(?P<note>.*)$/';

        return preg_match($vdev_pattern, $line, $matches) === 1;
    }

    /**
     * Returns an array representation of the device entry information in the given line.
     *
     * @param string $configLine
     * @return array
     */
    private function getDevice(string $configLine): array
    {
        $matches = [];
        if (!$this->matchConfigLine($configLine, $matches)) {
            // We should never reach here as the config lines are filtered before this method is called.
            // Cannot create a sensible unit test to reach this line.
            // @codeCoverageIgnoreStart
            throw new Exception('Invalid zpool status configuration');
            // @codeCoverageIgnoreEnd
        }

        $device = [
            'name' => $matches['devname'],
            'state' => $matches['state'],
            'read' => $matches['read'],
            'write' => $matches['write'],
            'cksum' => $matches['cksum'],
            'note' => $matches['note']
        ];

        return $device;
    }

    /**
     * Parses the configuration section recursively.
     *
     * @param array $childrenLineNos
     * @param array $configLines
     * @return array
     */
    private function parseChildren(array $childrenLineNos, array $configLines): array
    {
        $siblings = [];
        foreach ($childrenLineNos as $childLineNo) {
            $device = $this->getDevice($configLines[$childLineNo]);

            $kidsLineNos = $this->getChildrenLinesNos($childLineNo, $configLines);
            if ($kidsLineNos) {
                $device['devices'] = $this->parseChildren($kidsLineNos, $configLines);
            }

            $siblings[$device['name']] = $device;
        }

        return $siblings;
    }

    /**
     * Gets the line numbers of the entries in the root depth.
     *
     * @param array $configLines
     * @return array
     */
    private function getRootEntriesLineNos(array $configLines): array
    {
        return $this->getChildrenLinesNos(-1, $configLines, true);
    }

    /**
     * Returns a list of the line numbers of the children of the given entry specified by line number.
     * It returns an empty array if the given entry doesn't have children.
     *
     * @param int $lineNumber
     * @param array $configLines
     * @param bool $rootEntries
     * @return array
     */
    private function getChildrenLinesNos(int $lineNumber, array $configLines, bool $rootEntries = false): array
    {
        if (!$rootEntries) {
            $depth = $this->getLineDepth($configLines[$lineNumber]);
            $baseDepth = $depth + 2;
        } else {
            $baseDepth = 0;
        }

        $childrenLineNos = [];

        for ($i = $lineNumber + 1; $i < count($configLines); $i++) {
            if ($this->getLineDepth($configLines[$i]) === $baseDepth) {
                $childrenLineNos[] = $i;
            } elseif ($this->getLineDepth($configLines[$i]) < $baseDepth) {
                return $childrenLineNos;
            }
        }
        return $childrenLineNos;
    }

    /**
     * It returns the depth of the given line.
     *
     * @param string $line
     * @return int
     */
    private function getLineDepth(string $line): int
    {
        $match = [];
        $result = preg_match('/^\t(?P<wspace>\s*)/', $line, $match);

        return $result ? strlen($match['wspace']) : 0;
    }
}
