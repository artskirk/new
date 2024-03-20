<?php

namespace Datto\Backup\Stages;

use Datto\Config\DeviceConfig;
use Datto\Config\ServerNameConfig;
use Datto\Common\Resource\CurlRequest;

/**
 * This backup stage sends the snapshot time to device web.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class UpdateDeviceWeb extends BackupStage
{
    const URL_REQUEST_FORMAT = 'https://%s/sirisReporting/latestSnapshot.php?deviceID=%s&agent=%s&time=%s';

    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var ServerNameConfig */
    private $serverNameConfig;

    /** @var CurlRequest */
    private $curlRequest;

    public function __construct(
        DeviceConfig $deviceConfig,
        ServerNameConfig $serverNameConfig,
        CurlRequest $curlRequest
    ) {
        $this->deviceConfig = $deviceConfig;
        $this->serverNameConfig = $serverNameConfig;
        $this->curlRequest = $curlRequest;
    }

    /**
     * @inheritdoc
     */
    public function commit()
    {
        $urlRequest = $this->getUrlRequest();
        $this->sendRequest($urlRequest);
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
    }

    /**
     * Get the url request formatted with the appropriate query parameters.
     *
     * @return string Fully formatted url request string.
     */
    private function getUrlRequest(): string
    {
        $deviceUrl = $this->serverNameConfig->getServer(ServerNameConfig::DEVICE_DATTOBACKUP_COM);
        $devIdEncoded = urlencode($this->deviceConfig->getSecretKey());
        $agentName = urlencode($this->context->getAsset()->getKeyName());
        $timeStamp = urlencode($this->context->getSnapshotTime());
        $url = sprintf(self::URL_REQUEST_FORMAT, $deviceUrl, $devIdEncoded, $agentName, $timeStamp);

        return $url;
    }

    /**
     * Send the url request to device web.
     *
     * @param string $url
     */
    private function sendRequest(string $url)
    {
        $this->curlRequest
            ->init($url)
            ->setOption(CURLOPT_HEADER, 0)
            ->execute();
        $this->curlRequest->close();
    }
}
