<?php

namespace Datto\Reporting;

use Datto\Reporting\Aggregated\Report;
use Datto\Common\Resource\Zlib;
use Datto\Common\Utility\Filesystem;

class Screenshots extends Reporting
{
    /**
     * @param Filesystem|null $filesystem
     * @param Zlib|null $zlib
     */
    public function __construct(Filesystem $filesystem = null, Zlib $zlib = null)
    {
        parent::__construct($filesystem, $zlib);
        $this->fileSuffix = ".scr.log";
        $this->logTag = "scr";
        $this->codeGroups = array(
            'SCR860' => array("860","SCR0860","SCN0831"),
            'SCN832' => array("SCN0832"),
            'VER510' => array("VER0510", "VER0511", "VER0512", "VER0513"),
            'VER119' => array("VER0119")
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getLogs($hostname)
    {
        $records = parent::getLogs($hostname);

        foreach ($records as &$record) {
            $record['type'] = Report::TYPE_SCREENSHOTS;
        }
        unset($record);

        return $records;
    }

    /**
     * {@inheritdoc}
     */
    protected function generateOrganizedEntry($entry)
    {
        // need to check both codes to support legacy logs as well
        // as the new logs
        $isScr860 = in_array($entry['code'], $this->codeGroups['SCR860']);
        $isScr862 = in_array($entry['code'], $this->codeGroups['SCN832']);
        $isVer510 = in_array($entry['code'], $this->codeGroups['VER510']);
        $isVer119 = in_array($entry['code'], $this->codeGroups['VER119']);
        $thisScreen = array();

        if ($isScr860) {
            $thisScreen['start_time'] = $entry['time'];
            $thisScreen['result'] = 'success';
        } elseif ($isScr862 || $isVer510 || $isVer119) {
            $thisScreen['start_time'] = $entry['time'];
            $thisScreen['result'] = 'failure';
        }
        return $thisScreen;
    }
}
