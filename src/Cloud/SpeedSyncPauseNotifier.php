<?php

namespace Datto\Cloud;

use Datto\Config\DeviceConfig;
use Datto\Config\ServerNameConfig;
use Datto\Curl\CurlHelper;

/**
 * Sends notifications to the cloud when SpeedSync is paused.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class SpeedSyncPauseNotifier
{
    /** @var CurlHelper */
    private $curlHelper;

    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var ServerNameConfig */
    private $serverNameConfig;

    /** @var JsonRpcClient */
    private $client;

    /**
     * @param CurlHelper|null $curlHelper
     * @param DeviceConfig|null $deviceConfig
     * @param ServerNameConfig|null $serverNameConfig
     * @param JsonRpcClient|null $client
     */
    public function __construct(
        CurlHelper $curlHelper = null,
        DeviceConfig $deviceConfig = null,
        ServerNameConfig $serverNameConfig = null,
        JsonRpcClient $client = null
    ) {
        $this->curlHelper = $curlHelper ?: new CurlHelper();
        $this->deviceConfig = $deviceConfig ?: new DeviceConfig();
        $this->serverNameConfig = $serverNameConfig ?: new ServerNameConfig();
        $this->client = $client ?: new JsonRpcClient();
    }

    /**
     * Notifies device-web that SpeedSync has been paused for the given number of hours.
     *
     * @param int $delay The number of hours until the pause expires.
     */
    public function sendDevicePaused($delay)
    {
        $secretKey = $this->deviceConfig->get('secretKey');
        $serverName = $this->serverNameConfig->getServer('DEVICE_DATTOBACKUP_COM');
        $updateUrl = "https://" . $serverName . "/setDelay.php?key=" . $secretKey . "&delay=" . $delay;
        $this->curlHelper->get($updateUrl);
    }

    /**
     * Notifies device-web that SpeedSync has been paused for the given asset.
     *
     * @param string $assetKeyName
     */
    public function sendAssetPaused($assetKeyName)
    {
        $this->sendAssetPausedState($assetKeyName, true);
    }

    /**
     * Notifies device-web that SpeedSync has been resumed for the given asset.
     *
     * @param string $assetKeyName
     */
    public function sendAssetResumed($assetKeyName)
    {
        $this->sendAssetPausedState($assetKeyName, false);
    }

    /**
     * Notify device-web of an asset's paused state.
     *
     * @param string $assetKeyName
     * @param boolean $paused
     */
    private function sendAssetPausedState($assetKeyName, $paused)
    {
        $method = 'v1/device/asset/offsite/setPaused';
        $parameters = array('asset' => $assetKeyName, 'paused' => $paused);
        $this->client->notifyWithId($method, $parameters);
    }
}
