<?php

namespace Datto\System\Api;

use Datto\Common\Resource\CurlRequest;
use Datto\Https\HttpsService;
use Datto\JsonRpc\Client as JsonRpcClient;
use Datto\Resource\DateTimeService;
use Datto\Util\HttpErrorHelper;
use Datto\Log\DeviceLoggerInterface;
use Exception;

/**
 * Represents a remote SIRIS client connection using basic authentication.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class DeviceApiClient
{
    const CONNECT_TIMEOUT_SECONDS = 10;
    const TRANSFER_TIMEOUT_SECONDS = 60;
    const API_URI_PATH = '/api';
    const SCRUBBED_PASSWORD = '*****';

    /** @var CurlRequest */
    private $curlRequest;

    /** @var JsonRpcClient */
    private $jsonRpcClient;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var HttpErrorHelper */
    private $httpErrorHelper;

    /** @var HttpsService */
    private $httpsService;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var DeviceApiClientConnectionData|null */
    private $connection;

    /**
     * @param CurlRequest $curlRequest
     * @param JsonRpcClient $jsonRpcClient
     * @param DateTimeService $dateTimeService
     * @param HttpErrorHelper $httpErrorHelper
     * @param HttpsService $httpsService
     * @param DeviceLoggerInterface $logger
     */
    public function __construct(
        CurlRequest $curlRequest,
        JsonRpcClient $jsonRpcClient,
        DateTimeService $dateTimeService,
        HttpErrorHelper $httpErrorHelper,
        HttpsService $httpsService,
        DeviceLoggerInterface $logger
    ) {
        $this->curlRequest = $curlRequest;
        $this->jsonRpcClient = $jsonRpcClient;
        $this->dateTimeService = $dateTimeService;
        $this->httpErrorHelper = $httpErrorHelper;
        $this->httpsService = $httpsService;
        $this->logger = $logger;

        $this->connection = null;
    }

    /**
     * Get the hostname of the device this client is connected to.
     *
     * @return string Hostname
     */
    public function getHostname(): string
    {
        return $this->getConnection()->getHostname();
    }

    /**
     * The secondary IP address to use for bulk data transfers over SSH.
     *
     * If not set, the getHostname() value should be used.
     *
     * @return string|null
     */
    public function getSshIp()
    {
        return $this->getConnection()->getSshIp();
    }

    /**
     * Get the current connection data.
     * The caller should make no assumptions about the format of this data,
     * and it should be used for saving and restoring only.
     *
     * @return string Connection data.
     */
    public function getSerializedConnectionData(): string
    {
        return $this->getConnection()->serialize();
    }

    /**
     * Restore the connection data that was previously obtained by the
     * "getSerializedConnectionData()" function.
     *
     * @param string $connectionData
     */
    public function restoreConnectionData(string $connectionData)
    {
        $this->connection = DeviceApiClientConnectionData::unserialize($connectionData);
    }

    /**
     * Connect to a device directly using username and password.
     * This establishes a valid connection.
     *
     * @param string $ip IP address of the remote device
     * @param string $username
     * @param string $password
     * @param string|null $ddnsDomain Dynamic DNS domain for HTTPS
     * @param string|null $sshIp the IP to use for bulk data transfers over SSH
     */
    public function connect(
        string $ip,
        string $username,
        string $password,
        string $ddnsDomain = null,
        string $sshIp = null
    ) {
        $useHttps = $this->isHttpsSupported($ip, $ddnsDomain);
        $hostname = $useHttps ? $ddnsDomain : $ip;
        $this->connection = new DeviceApiClientConnectionData($hostname, $username, $password, $useHttps, $sshIp);
    }

    /**
     * Determine if the client is currently connected to a device.
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->connection !== null;
    }

    /**
     * Call an API method on the client and return the result.
     * This is a blocking call since it waits for the server to reply.
     *
     * @param string $method
     * @param array $parameters
     * @param int $timeout Timeout in seconds (0 = default)
     * @return mixed The returned result.
     */
    public function call(string $method, array $parameters = [], int $timeout = 0)
    {
        $id = $this->dateTimeService->getTime();
        $this->jsonRpcClient->query($id, $method, $parameters);
        $uriPath = self::API_URI_PATH;
        $data = $this->jsonRpcClient->encode();
        $headers = [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data)
        ];

        $reply = $this->sendHttpRequest($uriPath, $data, $headers, false, $timeout);

        $data = $this->jsonRpcClient->decode($reply);

        if (isset($data['error'])) {
            $errorCode = $data['error']['code'] ?? 0;
            $errorMessage = $data['error']['message'] ?? '';
            $this->logger->info('MDC0003 API call returned error', ['method' => $method, 'errorCode' => $errorCode, 'errorMessage' => $errorMessage]);
            throw new ApiErrorException($errorMessage, $errorCode);
        }

        if (!is_array($data) || !array_key_exists('result', $data)) {
            $this->logger->warning('MDC0004 API call to returned invalid response.', ['method' => $method]);
            throw new Exception("API call to \"$method\" returned invalid response.");
        }

        return $data['result'];
    }

    /**
     * Send an HTTP GET request to the device.
     *
     * @param string $uriPath The path part of the URL or route name.
     * @param int $timeout Timeout in seconds (0 = default)
     * @return mixed The returned HTTP response.
     */
    public function sendHttpGetRequest(string $uriPath, int $timeout = 0)
    {
        return $this->sendHttpRequest($uriPath, null, [], false, $timeout);
    }

    /**
     * Send an HTTP POST request to the device.
     *
     * @param string $uriPath The path part of the URL or route name.
     * @param string $data The POST data.
     * @param int $timeout Timeout in seconds (0 = default)
     * @return mixed The returned HTTP response.
     */
    public function sendHttpPostRequest(string $uriPath, string $data, int $timeout = 0)
    {
        return $this->sendHttpRequest($uriPath, $data, [], false, $timeout);
    }

    /**
     * Send an HTTP request to the device (GET or POST).
     * In most cases, you will need to login first.
     *
     * @param string $uriPath The path part of the URL or route name.
     * @param string|null $postData Post data if POST; null if GET.
     * @param array $headers
     * @param bool $returnHeaders
     * @param int $timeout Timeout in seconds (0 = default)
     * @return mixed The returned HTTP response.
     */
    private function sendHttpRequest(
        string $uriPath,
        string $postData = null,
        array $headers = [],
        bool $returnHeaders = false,
        int $timeout = 0
    ) {
        $scrubbedHeaders = $headers;

        $connection = $this->getConnection();

        $hostname = $connection->getHostname();
        $protocol = $connection->isHttps() ? 'https' : 'http';
        $url = $protocol . '://' . $hostname . '/' . ltrim($uriPath, '/');
        array_unshift($headers, $this->buildAuthorizationHeader($connection->getUsername(), $connection->getPassword()));
        array_unshift($scrubbedHeaders, $this->buildAuthorizationHeader($connection->getUsername(), self::SCRUBBED_PASSWORD));

        $post = !is_null($postData);

        $jsonLogData = [ 'url' => $url, 'headers' => $scrubbedHeaders ];
        $requestType = $post ? 'POST' : 'GET';
        if ($post) {
            $jsonLogData['data'] = $postData;
        }
        $this->logger->info('MDC0101 DeviceClient Request', ['requestType' => $requestType, 'jsonLogData' => json_encode($jsonLogData)]);

        $this->curlRequest->init();

        try {
            $this->curlRequest->setOption(CURLOPT_URL, $url);
            $this->curlRequest->setOption(CURLOPT_HTTPGET, !$post);
            $this->curlRequest->setOption(CURLOPT_POST, $post);
            if ($post) {
                $this->curlRequest->setOption(CURLOPT_POSTFIELDS, $postData);
            }
            $this->curlRequest->setOption(CURLOPT_HEADER, $returnHeaders);
            $this->curlRequest->setOption(CURLOPT_HTTPHEADER, $headers);
            $this->curlRequest->setOption(CURLOPT_FOLLOWLOCATION, !$post);
            $connectTimeout = $timeout ?: self::CONNECT_TIMEOUT_SECONDS;
            $transferTimeout = $timeout ?: self::TRANSFER_TIMEOUT_SECONDS;
            $this->curlRequest->setOption(CURLOPT_CONNECTTIMEOUT, $connectTimeout);
            $this->curlRequest->setOption(CURLOPT_TIMEOUT, $transferTimeout);
            $this->curlRequest->setOption(CURLOPT_RETURNTRANSFER, true);

            $response = $this->curlRequest->execute();

            $scrubbedResponse = preg_replace('/("password":").*?(")/', '$1*****$2', $response);
            $this->logger->info('MDC0102 DeviceClient -> Response', ['response' => json_encode(['response' => mb_strimwidth($scrubbedResponse, 0, 300, '...')])]);

            if ($response === false) {
                $curlError = $this->curlRequest->getError();
                $this->logger->error('MDC0001 CURL Error', ['url' => $url, 'curlError' => $curlError]);
                throw new Exception($curlError);
            }

            $info = $this->curlRequest->getInfo();
            $httpCode = $info['http_code'];

            if ($httpCode != 200) {
                $httpCodeString = $this->httpErrorHelper->getHttpErrorString($httpCode);
                $message = "HTTP $httpCode" . ($httpCodeString ? ": $httpCodeString" : '');
                $this->logger->error('MDC0002 Non-200 http response received', ['url' => $url, 'message' => $message]);
                throw new HttpStatusException($message, $httpCode);
            }
        } finally {
            $this->curlRequest->close();
        }

        return $response;
    }

    /**
     * Build the HTTP authorization header.
     *
     * @param string $username
     * @param string $password
     * @return string
     */
    private function buildAuthorizationHeader(string $username, string $password): string
    {
        return 'Authorization: Basic ' . base64_encode("$username:$password");
    }

    /**
     * Get the current connection if there is one, or generate an exception.
     *
     * @return DeviceApiClientConnectionData
     */
    private function getConnection(): DeviceApiClientConnectionData
    {
        if ($this->connection === null) {
            throw new Exception('No valid device client connection.');
        }
        return $this->connection;
    }

    /**
     * Determines if HTTPS is supported and enabled on the specified device.
     *
     * @param string $ip
     * @param string|null $ddnsDomain
     * @return bool True to use HTTPS, false to use HTTP
     */
    private function isHttpsSupported(string $ip, string $ddnsDomain = null): bool
    {
        $this->logger->debug("MDC0201 Determining communications protocol to use for device $ip ...");

        if (empty($ddnsDomain)) {
            $this->logger->info('MDC0202 Device does not have a DDNS domain. Using HTTP protocol.', ['deviceIp' => $ip]);
            return false;
        }

        $httpsUrl = "https://$ddnsDomain/";

        $this->logger->debug("MDC0203 Checking HTTPS connectivity to device $ip via \"$httpsUrl\"...");

        try {
            $success = $this->httpsService->checkConnectivity($httpsUrl);
        } catch (Exception $e) {
            $this->logger->warning('MDC0204 Error checking HTTPS connectivity', ['httpsUrl' => $httpsUrl, 'exception' => $e]);
            $success = false;
        }

        if ($success) {
            $this->logger->info('MDC0205 Successful HTTPS connection to device.  Using HTTPS protocol.', ['deviceIp' => $ip, 'httpsUrl' => $httpsUrl]);
        } else {
            $this->logger->info('MDC0206 Cannot establish HTTPS connection to device.  Using HTTP protocol.', ['deviceIp' => $ip, 'httpsUrl' => $httpsUrl]);
        }

        return $success;
    }
}
