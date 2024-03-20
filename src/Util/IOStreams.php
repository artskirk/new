<?php

namespace Datto\Util;

/**
 * Class IOStreams contains utility functions for IO to be used in command-line utilities and scripts.
 *
 * @author Christopher R. Wicks <cwicks@datto.com>
 */
class IOStreams
{
    /**
     * Wait for a line of input from stdin, then return it.
     * @return string The line entered by the user
     * @codeCoverageIgnore
     */
    public function getStdin()
    {
        $fp = fopen('php://stdin', 'r');
        $line = trim(fgets($fp, 1024), "\r\n");
        fclose($fp);
        return $line;
    }
}
