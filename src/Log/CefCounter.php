<?php
namespace Datto\Log;

use Datto\Config\DeviceConfig;

/**
 * Keeps track of the how often alert codes occur
 * for a given asset. This count is included in log messages.
 *
 * @author Michael Meyer (mmeyer@datto.com)
 */
class CefCounter
{
    /** @var string */
    private $assetKey;

    /** @var DeviceConfig */
    private $deviceConfig;

    /**
     * CefCounter constructor.
     *
     * @param string $assetKey
     * @param DeviceConfig|null $deviceConfig
     */
    public function __construct($assetKey, DeviceConfig $deviceConfig = null)
    {
        $this->assetKey = $assetKey;
        $this->deviceConfig = $deviceConfig ?: new DeviceConfig();
    }

    /**
     * Returns the number of times the given alert has
     * been logged (via CEF/remote logging).
     *
     * @param string $alertCode
     * @return int
     */
    public function getCount($alertCode)
    {
        $json = $this->getJson();
        $countExists = isset($json[$this->assetKey]) && isset($json[$this->assetKey][$alertCode]);
        if ($countExists) {
            return intval($json[$this->assetKey][$alertCode]);
        } else {
            return 0;
        }
    }

    /**
     * Parse the CEFCounts file into an array.
     *
     * If the file becomes corrupt (invalid json) or does
     * not exist, an empty array will be returned instead.
     *
     * @return array
     */
    private function getJson()
    {
        $encodedJson = $this->deviceConfig->get('CEFCounts.json', '[]');
        $data = json_decode($encodedJson, true);
        if ($data !== null) {
            return $data;
        } else {
            return array();
        }
    }

    /**
     * Increment the count for the given alert code.
     *
     * @param string $alertCode
     */
    public function incrementCount($alertCode)
    {
        $json = $this->getJson();
        if (!isset($json[$this->assetKey])) {
            $json[$this->assetKey] = array();
        }
        if (!isset($json[$this->assetKey][$alertCode])) {
            $json[$this->assetKey][$alertCode] = 0;
        }
        $json[$this->assetKey][$alertCode]++;
        $this->deviceConfig->set('CEFCounts.json', json_encode($json));
    }
}
