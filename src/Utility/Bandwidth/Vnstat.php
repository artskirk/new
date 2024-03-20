<?php

namespace Datto\Utility\Bandwidth;

use Datto\Common\Resource\ProcessFactory;

class Vnstat
{
    const VNSTAT = 'vnstat';

    const MODE_FIVE_MINUTES = 'f';
    const MODE_HOURS = 'h';
    const MODE_DAYS = 'd';
    const MODE_MONTHS = 'm';
    const MODE_YEARS = 'y';
    const MODE_TOP = 't';

    /** @var ProcessFactory */
    private ProcessFactory $processFactory;

    public function __construct(ProcessFactory $processFactory)
    {
        $this->processFactory = $processFactory;
    }

    /**
     * @param string $mode The mode constant for the timeframe of the bandwidth data to retrieve
     * @param int $limit The time period to limit the bandwidth usage data to, based on mode
     * @return array An associative array containing the json data returned by the command
     */
    public function getJson(string $mode, int $limit): array
    {
        $process = $this->processFactory->get([self::VNSTAT, '--json', $mode, $limit]);
        $process->mustRun();

        $rawUsageData = $process->getOutput();

        return json_decode(trim($rawUsageData), true);
    }
}
