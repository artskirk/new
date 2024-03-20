<?php

namespace Datto\Asset\Agent\Api;

use Datto\Asset\Agent\Certificate\CertificateSet;
use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Resource\CurlRequest;
use Datto\Common\Utility\Filesystem;
use Datto\Util\HttpErrorHelper;
use Datto\Log\DeviceLoggerInterface;

/**
 * Handles curl requests to the backup agents
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class AgentRequest
{
    const GET = 'GET';
    const POST = 'POST';
    const DELETE = 'DELETE';

    const CURL_CONNECT_TIMEOUT = 30;
    const DEFAULT_CURL_TIMEOUT = 600;
    const CURL_SSL_ERRORS = [
        CURLE_SSL_CONNECT_ERROR,
        CURLE_SSL_PEER_CERTIFICATE,
        CURLE_SSL_CERTPROBLEM,
        CURLE_SSL_CACERT,
        CURLE_GOT_NOTHING,
        CURLE_RECV_ERROR,
        CURLE_SEND_ERROR
    ];

    const CERTIFICATE_SET_USED = 'certificateSetUsed';

    /** @var string */
    private $url;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var Filesystem */
    private $filesystem;

    /** @var CurlRequest */
    private $curlRequest;

    /** @var HttpErrorHelper */
    private $httpErrorHelper;

    /** @var string[] */
    private $headers;

    /** @var CertificateSet[] */
    private $certificateSetArray;

    /** @var int */
    private $curlTimeout;

    /**
     * @param string $url
     * @param DeviceLoggerInterface $logger
     * @param Filesystem|null $filesystem
     * @param CurlRequest|null $curlRequest
     * @param HttpErrorHelper|null $httpErrorHelper
     */
    public function __construct(
        string $url,
        DeviceLoggerInterface $logger,
        Filesystem $filesystem = null,
        CurlRequest $curlRequest = null,
        HttpErrorHelper $httpErrorHelper = null
    ) {
        $this->url = $url;
        $this->logger = $logger;
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->curlRequest = $curlRequest ?: new CurlRequest();
        $this->httpErrorHelper = $httpErrorHelper ?: new HttpErrorHelper();
        $this->headers = [];
        $this->certificateSetArray = [];
        $this->curlTimeout = self::DEFAULT_CURL_TIMEOUT;
    }

    /**
     * Closes the current curl session
     */
    public function closeSession(): void
    {
        $this->curlRequest->close();
    }

    /**
     * Send a get request to the backup agent
     *
     * @param string $resource
     * @param array $params
     * @param bool $asJson
     * @param bool $verbose verbose output for agent:request command
     * @param bool $rawResult
     * @return array|string|int (See comments in sendRequest)
     */
    public function get(
        string $resource,
        array $params = [],
        bool $asJson = true,
        bool $verbose = false,
        bool $rawResult = false
    ) {
        return $this->sendRequest(
            self::GET,
            $resource,
            $asJson,
            $rawResult,
            $verbose,
            $params
        );
    }

    /**
     * Send a post request to the backup agent
     *
     * @param string $resource
     * @param string $data
     * @param bool $asJson
     * @param bool $verbose verbose output for agent:request command
     * @param bool $rawResult
     * @return array|string|int (See comments in sendRequest)
     */
    public function post(
        string $resource,
        string $data,
        bool $asJson = true,
        bool $verbose = false,
        bool $rawResult = false
    ) {
        return $this->sendRequest(
            self::POST,
            $resource,
            $asJson,
            $rawResult,
            $verbose,
            [],
            $data
        );
    }

    /**
     * Send a delete request to the backup agent
     *
     * @param string $resource
     * @param string $data
     * @param bool $asJson
     * @param bool $verbose verbose output for agent:request command
     * @param bool $rawResult
     * @return array|string|int (See comments in sendRequest)
     */
    public function delete(
        string $resource,
        string $data = '',
        bool $asJson = true,
        bool $verbose = false,
        bool $rawResult = false
    ) {
        return $this->sendRequest(
            self::DELETE,
            $resource,
            $asJson,
            $rawResult,
            $verbose,
            [],
            $data
        );
    }

    /**
     * Enable this request to try an array of certificate sets until the
     *  connection succeeds.
     *
     * @param CertificateSet[] $certificateSetArray
     */
    public function includeCertificateSet(array $certificateSetArray): void
    {
        $this->certificateSetArray = $certificateSetArray;
    }

    /**
     * Include basic authorization with the requests
     *
     * @param string $username
     * @param string $password
     */
    public function includeBasicAuthorization(string $username, string $password): void
    {
        $this->includeHeader("Authorization", sprintf("Basic %s", base64_encode("$username:$password")));
    }

    /**
     * @param string $key
     * @param string $value
     */
    public function includeHeader(string $key, string $value): void
    {
        $this->headers[$key] = $value;
    }

    /**
     * Tell curl to use a new connection for every request.  If this doesn't get called for ShadowSnap,
     * some bad things can happen (it apparently caches the cert name from "open" requests, and uses it across requests
     * from different IP addresses!)
     *
     * @param int $value
     */
    public function setFreshConnect(int $value): void
    {
        $this->curlRequest->setOption(CURLOPT_FRESH_CONNECT, $value);
    }

    /**
     * Enable or disable the SSL Session Cache. Session caching in curl is a behavior that
     * allows multiple connections to use a single TLS handshake. Unfortunately, for some agents
     * (ShadowSnap) this breaks, likely because there are different authentication mechanisms
     * used for different endpoints.
     *
     * @param bool $enable True to enable the SSL Session Cache (the default) or False to disable it
     */
    public function setSslSessionCache(bool $enable): void
    {
        $this->curlRequest->setOption(CURLOPT_SSL_SESSIONID_CACHE, $enable);
    }

    /**
     * Allow setting of the SSL cipher list. This can be individual ciphers, or a specified
     * security level.
     *
     * @see https://curl.se/docs/ssl-ciphers.html
     *
     * @param string $ciphers The SSL Cipher list for this request
     */
    public function setSslCipherList(string $ciphers = 'DEFAULT'): void
    {
        $this->curlRequest->setOption(CURLOPT_SSL_CIPHER_LIST, $ciphers);
    }

    /**
     * @param int $seconds
     */
    public function setTimeout(int $seconds): void
    {
        $this->curlTimeout = $seconds;
    }

    /**
     * Set the SSL version with the requests
     *
     * @param int $version
     */
    public function setSslVersion(int $version): void
    {
        $this->curlRequest->setOption(CURLOPT_SSLVERSION, $version);
    }

    /**
     * Include the SSL certs with the requests
     *
     * @param string $caInfoFile
     * @param string $sslCertFile
     * @param string $sslKeyFile
     */
    private function includeSslCerts(string $caInfoFile, string $sslCertFile, string $sslKeyFile): void
    {
        $this->curlRequest->setOption(CURLOPT_SSLCERT, $sslCertFile);
        $this->curlRequest->setOption(CURLOPT_SSLKEY, $sslKeyFile);

        // Set up curl for SSL mutual auth.  Check if CA exists so existing agents don't immediately break.
        if ($this->filesystem->exists($caInfoFile)) {
            $this->curlRequest->setOption(CURLOPT_SSL_VERIFYPEER, 1);
            $this->curlRequest->setOption(CURLOPT_CAINFO, $caInfoFile);
        } else {
            $this->curlRequest->setOption(CURLOPT_SSL_VERIFYPEER, 0);
        }
    }

    /**
     * Send a curl request to the backup agent
     *
     * @param string $httpRequestMethod
     * @param string $resource
     * @param bool $asJson
     * @param bool $rawResult True to return an array that includes info from the curl response. Overrides $asJson.
     * @param bool $verbose
     * @param array $urlParams
     * @param string $data
     * @return array|string|int
     *      if $rawResult is true returns array
     *      otherwise if there is a response body
     *          if $asJson is true, returns array of decoded json
     *          otherwise returns raw response string
     *      otherwise returns integer HTTP code
     *
     * TODO: Refactor usages of AgentRequest to only use the raw result - don't depend on extra processing of response data in this class
     */
    private function sendRequest(
        string $httpRequestMethod,
        string $resource,
        bool $asJson,
        bool $rawResult,
        bool $verbose,
        array $urlParams = [],
        string $data = ""
    ) {
        $url = $this->url . $resource;
        if (count($urlParams) > 0) {
            $url .= "?" . http_build_query($urlParams);
        }

        $rawCurlResult = $this->attemptRequestUntilItCompletes($httpRequestMethod, $url, $verbose, $data);
        if ($rawResult) {
            return $rawCurlResult;
        }

        $httpCode = $rawCurlResult['httpCode'];
        $error = $rawCurlResult['error'];
        $response = $rawCurlResult['response'];
        $errorCode = $rawCurlResult['errorCode'];
        if ($httpCode >= 400) {
            $curlErrorString = $error !== '' ? "CURL Error: '$error'" : '';
            $responseString = $response !== '' ? "Response: '$response'" : '';
            $httpCodeString = $this->httpErrorHelper->getHttpErrorString($httpCode);
            if ($httpCodeString !== '') {
                $httpCodeString = '(' . $httpCodeString . ')';
            }

            throw new AgentApiException(
                "CURL Error: $url: HTTP Code: $httpCode $httpCodeString $curlErrorString $responseString",
                $errorCode,
                $httpCode,
                $response
            );
        } elseif ($error) {
            throw new AgentApiException("CURL Error: $url: $error", $errorCode);
        }

        if ($response) {
            return ($asJson ? json_decode($response, true) : $response);
        }

        return $httpCode;
    }

    /**
     * Executes the curl request, and packages up the result of the request into an array
     * @return array includes response, error, errorCode, httpCode, and info from the curl request
     */
    private function runCurl()
    {
        $response = $this->curlRequest->execute();
        $info = $this->curlRequest->getInfo();
        $error = $this->curlRequest->getError();
        $errorCode = $this->curlRequest->getErrorCode();
        $httpCode = $info['http_code'] ?? 0;

        return [
            'response' => $response,
            'error' => $error,
            'errorCode' => $errorCode,
            'httpCode' => $httpCode,
            'info' => $info
        ];
    }

    /**
     * Attempts all of the certificate sets until the request can be sent without a curl error
     *    or until all configured CertificateSets have been tried.
     * If this class is configured to use certificates and all of the certificate sets fail with SSL errors,
     *   throws an AgentCertificateException.
     * The results include a certificateSetUsed key that contains the CertificateSet that was used for the connection
     *    or null if the class isn't configured to use CertificateSets.
     *
     * @param string $httpRequestMethod
     * @param string $url
     * @param bool $verbose
     * @param string $data
     * @return array includes response, error, errorCode, httpCode, trustedRootHash, and info from the curl request
     */
    private function attemptRequestUntilItCompletes(
        string $httpRequestMethod,
        string $url,
        bool $verbose,
        string $data = ""
    ) {
        $enableCertificateAuthorization = count($this->certificateSetArray) > 0;
        $certificatesList = $this->certificateSetArray;
        $certificateSetToTry = null;
        $sslErrors = [];
        do {
            if ($enableCertificateAuthorization) {
                $certificateSetToTry = array_shift($certificatesList);
            }

            $this->setCurlOptions($httpRequestMethod, $url, $verbose, $data, $certificateSetToTry);
            // Run the CURL and check the return for errors
            $rawCurlResult = $this->runCurl();
            $rawCurlResult[self::CERTIFICATE_SET_USED] = $certificateSetToTry;
            $errorCode = $rawCurlResult["errorCode"];
            $transportError = $enableCertificateAuthorization && $errorCode > 0;
            $isSslError = $transportError && in_array($errorCode, self::CURL_SSL_ERRORS);
            if ($isSslError) {
                $sslErrors[] = $errorCode;
            }
            // loop control
            $moreCertificateSetsToTry = count($certificatesList) > 0;
        } while ($transportError && $moreCertificateSetsToTry);

        if ($transportError && count($sslErrors) > 0) {
            $this->logger->info("ARQ0002 All certificate sets failed");
            throw new AgentCertificateException("Certificate error communicating with Agent", $sslErrors[0]);
        }

        return $rawCurlResult;
    }

    /**
     * @param string $httpRequestMethod
     * @param string $url
     * @param bool $verbose
     * @param string $data
     * @param CertificateSet|null $certificateSetToTry
     */
    private function setCurlOptions(
        string $httpRequestMethod,
        string $url,
        bool $verbose,
        string $data,
        CertificateSet $certificateSetToTry = null
    ): void {
        $this->curlRequest->setOption(CURLOPT_CONNECTTIMEOUT, self::CURL_CONNECT_TIMEOUT);
        $this->curlRequest->setOption(CURLOPT_TIMEOUT, $this->curlTimeout);
        $this->curlRequest->setOption(CURLOPT_RETURNTRANSFER, 1);
        $this->curlRequest->setOption(CURLOPT_SSL_VERIFYHOST, 0);
        $this->curlRequest->setOption(CURLOPT_SSL_VERIFYPEER, 0);
        $this->curlRequest->setOption(CURLOPT_FOLLOWLOCATION, 1);

        //Headers
        $this->includeHeader('Content-Type', 'application/json');
        $this->curlRequest->setOption(CURLOPT_HTTPHEADER, $this->getCurlHeaders());
        if (!is_null($certificateSetToTry)) {
            $this->includeSslCerts(
                $certificateSetToTry->getRootCertificatePath(),
                $certificateSetToTry->getDeviceCertPath(),
                $certificateSetToTry->getDeviceKeyPath()
            );
        }

        $this->curlRequest->setOption(CURLOPT_URL, $url);
        $this->curlRequest->setOption(CURLOPT_VERBOSE, $verbose);

        // setup the request method
        if ($httpRequestMethod === self::GET || $httpRequestMethod === self::POST) {
            $this->curlRequest->setOption(CURLOPT_POST, $httpRequestMethod == self::POST);
            $this->curlRequest->setOption(CURLOPT_CUSTOMREQUEST, null);
        } else {
            $this->curlRequest->setOption(CURLOPT_CUSTOMREQUEST, $httpRequestMethod);
        }

        // setup the post fields for non-get requests
        if ($httpRequestMethod !== self::GET) {
            $this->curlRequest->setOption(CURLOPT_POSTFIELDS, $data);
        }
    }

    private function getCurlHeaders(): array
    {
        $curlHeaders = [];
        foreach ($this->headers as $key => $value) {
            $curlHeaders[] = "$key: $value";
        }
        return $curlHeaders;
    }
}
