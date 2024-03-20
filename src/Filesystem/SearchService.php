<?php

namespace Datto\Filesystem;

use Datto\Common\Resource\ProcessFactory;
use Datto\Log\DeviceLoggerInterface;

/**
 * Searches a directory for files and folders that match a string.
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class SearchService
{
    /** @var DeviceLoggerInterface */
    private $logger;

    private ProcessFactory $processFactory;

    /**
     * @param DeviceLoggerInterface $logger
     * @param ProcessFactory $processFactory
     */
    public function __construct(
        DeviceLoggerInterface $logger,
        ProcessFactory $processFactory
    ) {
        $this->logger = $logger;
        $this->processFactory = $processFactory;
    }

    /**
     * Searches a directory for files and folders that match a string.
     *
     * @param string $rootPath
     * @param string $searchString
     * @return string[]
     */
    public function search(string $rootPath, string $searchString): array
    {
        $process = $this->processFactory
            ->get(['find', $rootPath, '-iname', "*$searchString*"]);

        if ($process->run() !== 0) {
            $this->logger->debug("SRC0001 Find process failed for path '$rootPath' and terms '$searchString'.");
            return [];
        }

        $output = trim($process->getOutput());

        if ($output === '') {
            // No output from find; no matches
            $this->logger->debug("SRC0001 No matches for path '$rootPath' and terms '$searchString'.");
            return [];
        }

        $relativeSearchResults = [];
        foreach (explode(PHP_EOL, $output) as $searchResult) {
            $relativeSearchResults[] = substr($searchResult, strlen($rootPath));
        }

        return $relativeSearchResults;
    }
}
