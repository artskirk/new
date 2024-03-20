<?php

namespace Datto\Cloud;

use Datto\Config\DeviceConfig;
use Datto\Config\ServerNameConfig;
use Datto\JsonRpc\Http;
use Datto\Log\DeviceLoggerInterface;
use Datto\Log\LoggerFactory;

/**
 * Client class to communicate with the device-web JSON-RPC API.
 *
 * This class is meant as an easy way to call device-specific endpoints
 * on device-web. It handles authentication and provides two JSON-RPC
 * methods 'query' (request with result) and 'notify' (request without
 * result).
 *
 * To test calls to the API on a DLAMP (or any server with an invalid
 * TLS/SSL certificate), it also provides a debug mode that can be
 * enabled by creating the file /datto/config/debugCurl (also used by CurlHelper).
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class JsonRpcClient
{
    const DEFAULT_API_ENDPOINT_URL_FORMAT = 'https://%s/api/api.php';
    const DEFAULT_CA_PATH = '/etc/ssl/certs';
    const X_REQUEST_ID = 'X-Request-Id';

    /** @var string URL of the JSON-RPC API endpoint */
    private $endpoint;

    /** @var string Directory that system certs are stored in */
    private $caPath;

    /** @var bool True to enable debug and disable SSL cert verification, false otherwise */
    private $debug;

    /** @var Http\Client */
    private $client;

    /** @var bool Determines if we should batch our requests */
    private $shouldBatchRequests;

    /** @var int ID to be used for the next json-rpc query */
    private $nextQueryId;

    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var ServerNameConfig */
    private $serverNameConfig;

    /** @var DeviceLoggerInterface */
    private $logger;

    /**
     * Create a JSON-RPC client to communicate with device-web endpoints.
     *
     * @param string|null $endpoint
     *            URL of the endpoint; if null, the endpoint is determined via the ServerNameConfig class
     * @param string|null $caPath
     * @param bool|null $debug True to enable debug; if null, debug is enabled if /datto/config/debugCurl exists
     * @param DeviceConfig|null $deviceConfig
     * @param ServerNameConfig|null $serverNameConfig
     * @param DeviceLoggerInterface|null $logger
     */
    public function __construct(
        $endpoint = null,
        $caPath = null,
        $debug = null,
        DeviceConfig $deviceConfig = null,
        ServerNameConfig $serverNameConfig = null,
        DeviceLoggerInterface $logger = null
    ) {
        $this->deviceConfig = $deviceConfig ?: new DeviceConfig();
        $this->serverNameConfig = $serverNameConfig ?: new ServerNameConfig();
        $this->endpoint = $endpoint ?: $this->getDefaultEndpoint();
        $this->caPath = $caPath ?: $this->getCaPath();
        $this->debug = $debug ?: $this->getDefaultDebug();
        $this->logger = $logger ?: LoggerFactory::getDeviceLogger();
        $this->client = $this->buildClient();
        $this->shouldBatchRequests = false;
        $this->nextQueryId = 0;
    }

    /**
     * Send JSON-RPC 'query' request, i.e. a request WITH a response.
     *
     * @param string $method Method name for JSON-RPC request, e.g. v1/device/service/getServiceInfo
     * @param null|array $arguments Array of key/value arguments to be passed
     * @return array|string|int|bool Result returned by the endpoint
     */
    public function query($method, $arguments = null)
    {
        $queryId = $this->nextQueryId();
        $this->client->query($queryId, $method, $arguments);

        if ($this->shouldBatchRequests) {
            return $queryId;
        } else {
            $reply = $this->client->send();

            if ($reply && isset($reply['result'])) {
                return $reply['result'];
            } elseif ($reply && isset($reply['error'])) {
                throw new CloudErrorException('Query for ' . $method . ' returned with an error', $reply['error']);
            } else {
                throw new CloudNoResponseException('Query for ' . $method . ' returned no result');
            }
        }
    }

    /**
     * Lookup the result of a batched query from the list of replies (see batch() and send()).
     *
     * @param array|null $batchedReplies
     * @param int $queryId
     * @return array|string Result returned by the endpoint
     */
    public function getBatchedQueryResult($batchedReplies, int $queryId)
    {
        $foundReply = null;

        // If a single query was sent in the batch, the response will be as if it was non-batched.
        if (isset($batchedReplies['id']) && $batchedReplies['id'] == $queryId) {
            $foundReply = $batchedReplies;
        } elseif (is_array($batchedReplies)) {
            foreach ($batchedReplies as $reply) {
                if (isset($reply['id']) && $reply['id'] == $queryId) {
                    $foundReply = $reply;
                    break;
                }
            }
        }

        if (isset($foundReply['result'])) {
            return $foundReply['result'];
        } elseif (isset($foundReply['error'])) {
            throw new CloudErrorException('Query returned with an error', $foundReply['error']);
        } else {
            throw new CloudNoResponseException('Query returned no result');
        }
    }

    /**
     * Send JSON-RPC 'query' request, i.e. a request WITH a response,
     * and add an 'id' parameter to the $arguments array.
     *
     * This is a convenience method to avoid having to determine
     * the device ID outside of the client.
     *
     * @param string $method Method name for JSON-RPC request, e.g. v1/device/service/getServiceInfo
     * @param null|array $arguments Array of key/value arguments to be passed
     * @return array|string|int|bool Result returned by the endpoint
     */
    public function queryWithId($method, $arguments = null)
    {
        return $this->query($method, $this->addIdArgument($arguments));
    }

    /**
     * Send JSON-RPC 'notify' request, i.e. a request WITHOUT a response.
     *
     * @param string $method Method name for JSON-RPC request, e.g. v1/device/service/setServiceInfo
     * @param null|array $arguments Array of key/value arguments to be passed
     */
    public function notify($method, $arguments = null)
    {
        $this->client->notify($method, $arguments);

        if (!$this->shouldBatchRequests) {
            $this->client->send();
        }
    }

    /**
     * Send JSON-RPC 'notify' request, i.e. a request WITHOUT a response,
     * and add an 'id' parameter to the $arguments array.
     *
     * @param string $method Method name for JSON-RPC request, e.g. v1/device/service/setServiceInfo
     * @param null|array $arguments Array of key/value arguments to be passed
     */
    public function notifyWithId($method, $arguments = null)
    {
        $this->notify($method, $this->addIdArgument($arguments));
    }

    /**
     * Begin batching requests
     *
     *  Example:
     *      $client = new Client();
     *
     *      $client->batch();
     *
     *      $client->notify(...); // not sent yet
     *      $client->notify(...); // not sent yet
     *
     *      $client->send(); // send both notifications together
     */
    public function batch()
    {
        $this->shouldBatchRequests = true;
    }

    /**
     * Send all of our requests and end our batching 'session'
     *
     * @return mixed
     */
    public function send()
    {
        $reply = $this->client->send();

        // reset the client and mark ourselves as no longer a batch request
        $this->client = $this->buildClient();
        $this->shouldBatchRequests = false;

        return $reply;
    }

    /**
     * Adds an 'id' parameter containing the device ID to the given
     * arguments array and returns.
     *
     * @param null|array $arguments Arguments array
     * @return array New arguments array, including the 'id' parameter
     */
    public function addIdArgument($arguments)
    {
        if (is_null($arguments)) {
            $arguments = array();
        }

        $arguments['id'] = $this->deviceConfig->getDeviceId();
        return $arguments;
    }

    /**
     * Rebuilds the client  This should be called if either deviceID or secret changes
     * to ensure that the JsonRpcClient has the correct authorization set
     */
    public function rebuildClient()
    {
        $this->client = $this->buildClient();
    }

    /**
     * Creates an HTTP client object.
     *
     * @param $endpoint
     * @param $headers
     * @param $options
     * @return Http\Client
     */
    protected function createJsonRpcClient($endpoint, $headers, $options)
    {
        return new Http\Client($endpoint, $headers, $options);
    }

    /**
     * Builds the actual JSON-RPC client, passes relevant authentication
     * headers and debug options.
     *
     * @return Http\Client
     */
    private function buildClient()
    {

        $formattedDeviceId = 'id{' . $this->deviceConfig->getDeviceId() . '}';
        $headers = array(
            'Authorization' => 'Basic ' . base64_encode($formattedDeviceId.':'.$this->deviceConfig->getSecretKey())
        );

        $contextId = $this->logger->getContextId();
        $headers[self::X_REQUEST_ID] = $contextId;

        // Make testable
        $verifyCerts = !$this->debug;

        $options = array(
            "ssl" =>
                array(
                    "capath" => $this->caPath,
                    "verify_peer" => $verifyCerts,
                    "verify_peer_name" => $verifyCerts
                )
        );

        return $this->createJsonRpcClient($this->endpoint, $headers, $options);
    }

    /**
     * @return string Default endpoint for the device-web API
     */
    private function getDefaultEndpoint()
    {
        return sprintf(
            self::DEFAULT_API_ENDPOINT_URL_FORMAT,
            $this->serverNameConfig->getServer('DEVICE_DATTOBACKUP_COM')
        );
    }

    /**
     * @return string Directory that CA certs are stored in
     */
    private function getCaPath()
    {
        return self::DEFAULT_CA_PATH;
    }

    /**
     * @return bool True if /datto/config/debugCurl is set, false otherwise
     */
    private function getDefaultDebug()
    {
        return $this->deviceConfig->has('debugCurl');
    }

    /**
     * Increments and returns the next query id
     *
     * @return int
     */
    private function nextQueryId()
    {
        return ++$this->nextQueryId;
    }
}
