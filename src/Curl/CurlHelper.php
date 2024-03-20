<?php

namespace Datto\Curl;

use Datto\Cloud\JsonRpcClient;
use Datto\Config\DeviceConfig;
use Datto\Config\ServerNameConfig;
use Datto\Log\LoggerFactory;
use Datto\Common\Resource\CurlRequest;
use Datto\Log\DeviceLoggerInterface;

/**
 * Unit testable version of the old 'CurlTo' class.
 * This class can be used to call both any url and predefined endpoints:
 *
 * - post() and curlOut() methods can call any url,
 * - send() will call curlTo.php, email() will call emailTo.php.
 *
 * @see http://github-server.datto.lan/device/device-web/blob/master/httpsdocs/curlTo.php
 * @see http://github-server.datto.lan/device/device-web/blob/master/httpsdocs/emailTo.php
 * @author Bartosz Neuman <bneuman@datto.com>
 */
class CurlHelper
{
    const CURL_TIMEOUT_SECONDS = 300; //5 mins

    /** @var DeviceConfig  */
    private $config;

    /** @var CurlRequest  */
    private $curlRequest;

    /** @var ServerNameConfig  */
    private $serverNameConfig;

    /** @var DeviceLoggerInterface */
    private $logger;

    /**
     * @param DeviceConfig $config
     * @param ServerNameConfig|null $serverNameConfig
     * @param CurlRequest|null $curlRequest
     * @param DeviceLoggerInterface|null $logger
     */
    public function __construct(
        DeviceConfig $config = null,
        ServerNameConfig $serverNameConfig = null,
        CurlRequest $curlRequest = null,
        DeviceLoggerInterface $logger = null
    ) {
        $this->config = $config ?: new DeviceConfig();
        $this->serverNameConfig = $serverNameConfig ?: new ServerNameConfig();
        $this->curlRequest = $curlRequest ?: new CurlRequest();
        $this->logger = $logger ?: LoggerFactory::getDeviceLogger();
    }

    /**
     * @param string $endpoint
     * @param string $action
     * @param array $inData
     * @return mixed
     */
    public function post($endpoint, $action, array $inData)
    {
        $data = array();
        $data["action"] = $action;
        $data["key"] = $this->getSecretKey();
        $data["deviceID"] = $this->getDeviceId();

        foreach ($inData as $key => $value) {
            $data[$key] = $value;
        }

        $out = json_encode($data);

        return $this->curlOut($out, $endpoint);
    }

    /**
     * @param $url
     * @return mixed
     */
    public function get($url)
    {
        $context = [
            'method' => 'GET',
            'url' => $url
        ];
        $this->logger->info('CUR0001 CurlHelper Request', $context);

        $this->curlRequest
            ->init()
            ->setOption(CURLOPT_TIMEOUT, self::CURL_TIMEOUT_SECONDS)
            ->setOption(CURLOPT_RETURNTRANSFER, 1)
            ->setOption(CURLOPT_URL, $url);

        $response = $this->curlRequest->execute();
        $this->curlRequest->close();

        // failure case will be a boolean, so cast so the types match in the log line
        $context['response'] = (string)$response;
        $this->logger->info('CUR0002 CurlHelper Response', $context);

        return $response;
    }

    /**
     * @param string $action
     * @param array $inData
     * @return mixed
     */
    public function send($action, array $inData)
    {
        $endpoint = "https://" . $this->getDeviceUrl() . "/curlTo.php";
        return $this->post($endpoint, $action, $inData);
    }

    /**
     * @param string $action
     * @param array $inData
     * @return mixed
     */
    public function email($action, array $inData)
    {
        $endpoint = "https://" . $this->getDeviceUrl() . "/emailTo.php";
        return $this->post($endpoint, $action, $inData);
    }

    /**
     * @param array|string $data
     * @param string $url
     * @return bool|mixed
     */
    public function curlOut($data, $url = null)
    {
        $this->logger->debug('CUR0003 CurlHelper POST Request', ['url' => $url, 'data' => json_encode($data)]);

        if (is_null($url)) {
            return false;
        }

        $xRequestId = null;

        if (is_array($data)) {
            $xRequestId = $data[JsonRpcClient::X_REQUEST_ID] ?? null;
            $data["key"] = $this->getSecretKey();
            $data["deviceID"] = $this->getDeviceId();
            $data = json_encode($data);
        } elseif (@unserialize($data, ['allowed_classes' => false]) !== false) {
            $content = unserialize($data, ['allowed_classes' => false]);
            if (is_array($content)) {
                $xRequestId = $content[JsonRpcClient::X_REQUEST_ID] ?? null;
                $content["key"] = $this->getSecretKey();
                $content["deviceID"] = $this->getDeviceId();
                $data = serialize($content);
                unset($content);
            }
        } elseif (json_decode($data) !== null) {
            $decodedData = json_decode($data, true);
            $xRequestId = $decodedData[JsonRpcClient::X_REQUEST_ID] ?? null;
        }

        $this->curlRequest
            ->init($url)
            ->setOption(CURLOPT_POST, true)
            ->setOption(CURLOPT_POSTFIELDS, $data)
            ->setOption(CURLOPT_RETURNTRANSFER, 1);

        if ($this->config->has('debugCurl')) {
            $this->curlRequest
                ->setOption(CURLOPT_SSL_VERIFYHOST, 0)
                ->setOption(CURLOPT_SSL_VERIFYPEER, 0);
        }

        $httpHeaders = array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data)
        );
        if ($xRequestId !== null) {
            $httpHeaders[] = JsonRpcClient::X_REQUEST_ID . ': ' . $xRequestId;
        }
        $this->curlRequest->setOption(CURLOPT_HTTPHEADER, $httpHeaders);

        $response = $this->curlRequest->execute();

        $this->curlRequest->close();

        $this->logger->debug('CUR0004 CurlHelper POST Response', ['response' => $response]);
        return $response;
    }

    /**
     * @param string $name
     * @param string $value
     * @return CurlHelper
     */
    public function setOption($name, $value)
    {
        $this->curlRequest->setOption($name, $value);
        return $this;
    }

    private function getDeviceId()
    {
        return $this->config->get("deviceID");
    }

    private function getSecretKey()
    {
        return $this->config->get("secretKey");
    }

    private function getDeviceUrl()
    {
        return $this->serverNameConfig->getServer('DEVICE_DATTOBACKUP_COM');
    }
}
