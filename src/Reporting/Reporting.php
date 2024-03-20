<?php

namespace Datto\Reporting;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Resource\Zlib;
use Datto\Common\Utility\Filesystem;

abstract class Reporting
{

    const KEYBASE = '/datto/config/keys';

    protected $codeGroups;
    protected $logTag;
    protected $fileSuffix;
    protected $logLocation;
    protected $times;
    protected $filesystem;
    protected Zlib $zlib;

    /**
     * @param Filesystem|null $filesystem
     * @param Zlib|null $zlib
     */
    public function __construct(Filesystem $filesystem = null, Zlib $zlib = null)
    {
        $this->filesystem = $filesystem ?? new Filesystem(new ProcessFactory());
        $this->zlib = $zlib ?? new Zlib();
    }

    /**
     * Gets filtered log information from an asset.
     *
     * @param string $hostname The asset to get the logs from
     * @return array logs with keys start_time and result
     */
    public function getLogs($hostname)
    {
        $this->initLog($hostname);

        $path = $this->getLogLocation();

        $tag = $this->logTag;
        $entries = array();
        $this->times = array();

        foreach ($this->filesystem->glob("$path$hostname.$tag.log*") as $lfile) {
            $fp = $this->zlib->open($lfile, 'r');
            if ($fp == false) {
                continue;
            }
            while (!$this->zlib->atEndOfFile($fp)) {
                $line = trim($this->zlib->get($fp, 4096));

                @list($timeStamp, $logCode, $logMsg) = explode(":", $line);

                if ($timeStamp === "" || $logCode === "") {
                    continue; // We need the code and the time to process, if they don't exist, skip it.
                }

                $entry = array('time' => $timeStamp, 'code' => $logCode, 'msg' => $logMsg);

                $result = $this->generateOrganizedEntry($entry);
                if (isset($result["start_time"])) {
                    if (!array_key_exists($result["start_time"], $this->times)) {
                        $entries[] = $result;
                        $this->times[$result["start_time"]] = 1;
                    }
                }
            }

            $this->zlib->close($fp);
        }
        return $entries;
    }

    /**
     * @return string The directory containing the logs
     */
    public function getLogLocation()
    {
        return self::KEYBASE . "/";
    }

    /**
     * Returns a filled array if the log is important, empty array if it's not
     *
     * @param array $entry an array with keys code and time
     * @return array with keys result and start_time or empty array
     */
    abstract protected function generateOrganizedEntry($entry);

    private function inCodeGroup($code)
    {
        foreach ($this->codeGroups as $group) {
            if (in_array($code, $group)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Creates and fills the secondary log from the primary logs, includes gziped logs
     *
     * @param string $hostname The asset
     */
    public function initLog($hostname)
    {
        $filename = $this->getLogLocation() . $hostname;
        if ($this->filesystem->exists($filename . $this->fileSuffix)) {
            return;
        }

        $newEntries = array();

        foreach ($this->filesystem->glob("$filename.log*") as $lfile) {
            $fp = $this->zlib->open($lfile, 'r');
            if ($fp == false) {
                continue;
            }
            while (!$this->zlib->atEndOfFile($fp)) {
                $line = $this->zlib->get($fp, 4096);
                $timeStamp = strtok($line, ":");
                $logCode = strtok(":");
                $logMsg = strtok(":");

                if ($this->inCodeGroup($logCode)) {
                    $newEntries[] = array('time' => $timeStamp, 'code' => $logCode, 'msg' => $logMsg);
                }
            }

            $this->zlib->close($fp);
        }

        $newLogMessages = array();
        foreach ($newEntries as $entry) {
            $timeStamp = $entry['time'];
            $logCode = $entry['code'];
            $logMsg = $entry['msg'];
            $newLogMessages[] = "$timeStamp:$logCode:$logMsg";
        }
        $this->filesystem->filePutContents($filename . $this->fileSuffix, implode("\n", $newLogMessages) . "\n");
    }

    /**
     * Logs a message to secondary log.
     *
     * @param string $hostname
     * @param string $code
     * @param string $message
     * @param int $time
     */
    public function log($hostname, $code, $message, $time)
    {
        $this->initLog($hostname);

        $filename = $this->getLogLocation() . $hostname . $this->fileSuffix;
        $logLine = "$time:$code:$message\n";

        $this->filesystem->filePutContents($filename, $logLine, FILE_APPEND);
    }

    /**
     * Reads asset log and returns entries without formatting or parsing beyond basic epoch/code/msg
     *
     * @param $hostname
     * @return array
     */
    public function readAssetLog($hostname)
    {
        $filename = $this->getLogLocation() . $hostname;

        $newEntries = [];

        foreach ($this->filesystem->glob("$filename.log*") as $lfile) {
            $fp = $this->zlib->open($lfile, 'r');
            if ($fp == false) {
                continue;
            }
            while (!$this->zlib->atEndOfFile($fp)) {
                $line = $this->zlib->get($fp, 8192);
                $timeStamp = strtok($line, ":");
                $logCode = strtok(":");
                $logMsg = strtok(":");

                $newEntries[] = array('time' => $timeStamp, 'code' => $logCode, 'msg' => $logMsg);
            }

            $this->zlib->close($fp);
        }
        return $newEntries;
    }
}
