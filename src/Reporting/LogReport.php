<?php

namespace Datto\Reporting;

use Datto\Common\Resource\Zlib;
use Datto\Common\Utility\Filesystem;

/**
 * This class handles creating message and subject for Log Report emails
 *
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class LogReport extends Reporting
{
    /**
     * @param Filesystem|null $filesystem
     * @param Zlib|null $zlib
     */
    public function __construct(
        Filesystem $filesystem = null,
        Zlib $zlib = null
    ) {
        parent::__construct($filesystem, $zlib);
        $this->fileSuffix = 'dgst';
        $this->logTag = false;
        $this->codeGroups = 'all';
    }

    /**
     * This is currently not called or used
     * all log entries are returned as is, no codes are parsed out, no formatting to array is currently done
     * @param array $entry
     * @return array
     */
    public function generateOrganizedEntry($entry): array
    {
        // This function is not used by any log report generation, but required by parent class
        return $entry;
    }

    /**
     * Fetch last 500 log messages and reverse, for mailing
     *
     * @param $assetKey
     * @return array
     */
    public function getLastFiveHundredLogMessages($assetKey): array
    {
        $logs = $this->readAssetLog($assetKey);
        return array_reverse(array_slice($logs, -500));
    }
}
