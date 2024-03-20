<?php

namespace Datto\Utility;

use Exception;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;

/**
 * Base class that provides utility wrappers with common facilities, such as logging and simple regex-based parsing.
 */
abstract class AbstractUtility implements LoggerAwareInterface
{
    protected LoggerInterface $logger;

    /**
     * Set the logger, per LoggerAwareInterface.
     *
     * @param LoggerInterface $logger
     * @return void
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Parse a size value from the output of the command, using a regex. The regex *MUST* contain one or more groups, or
     * this method will fail. Only the first group is considered for the match.
     *
     * @param string $output the command output
     * @param string $regex the regular expression used to parse the command output
     * @return int the size value
     * @throws Exception if the value can't be parsed with the regular expression
     */
    protected function parseForSize(string $output, string $regex): int
    {
        if (preg_match($regex, $output, $size)) {
            if (isset($size[1])) {
                return intval(trim($size[1]));
            }
        }

        throw new Exception("Failed to parse command output '$output' for $regex");
    }
}
