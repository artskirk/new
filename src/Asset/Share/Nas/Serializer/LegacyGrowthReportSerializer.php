<?php

namespace Datto\Asset\Share\Nas\Serializer;

use Datto\Asset\Serializer\Serializer;
use Datto\Asset\Share\Nas\GrowthReportSettings;

/**
 * @author Rixhers Ajazi <rajazi@datto.com>
 */
class LegacyGrowthReportSerializer implements Serializer
{
    /**
     * @param GrowthReportSettings $growthReport 1 per share
     * @return string
     */
    public function serialize($growthReport)
    {
        $output = array(
            $growthReport->getFrequency(),
            $growthReport->getEmailTime(),
            $growthReport->getEmailList()
        );

        return implode(":", $output);
    }

    /**
     * @param string $serialized ":" delimited string 1 per share
     * @return GrowthReportSettings
     */
    public function unserialize($serialized)
    {
        $growthReport = new GrowthReportSettings();

        if ($serialized) {
            $output = explode(":", $serialized);
            $growthReport->setFrequency($output[0]);
            $growthReport->setEmailTime($output[1]);
            $growthReport->setEmailList($output[2]);
        }

        return $growthReport;
    }
}
