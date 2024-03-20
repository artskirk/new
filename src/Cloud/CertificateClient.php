<?php

namespace Datto\Cloud;

use Datto\Config\ServerNameConfig;
use Datto\Log\DeviceLoggerInterface;
use Datto\Curl\CurlHelper;

class CertificateClient
{
    /**
     * Certificate endpoint used to retrieve the CA cert used to create the cloud-api client cert
     */
    const URL_FORMAT = 'https://%s/v1/cert/%s';

    /**
     * The name of the device certificate in device-web
     */
    const DEVICE_CERT_NAME = 'device';

    /**
     * @var DeviceLoggerInterface
     */
    private $logger;

    /**
     * @var ServerNameConfig
     */
    private $serverNameConfig;

    /**
     * @var CurlHelper
     */
    private $curlHelper;

    public function __construct(
        DeviceLoggerInterface $logger,
        ServerNameConfig $serverNameConfig,
        CurlHelper $curlHelper
    ) {
        $this->serverNameConfig = $serverNameConfig;
        $this->logger = $logger;
        $this->curlHelper = $curlHelper;
    }

    /**
     * Fetches the "device" root certificate authority used by DWA and DLA
     * @return string|false Certificate contents or false if it could not be fetched
     */
    public function retrieveTrustedRootContents()
    {
        return $this->fetchCertificate(self::DEVICE_CERT_NAME);
    }

    /**
     * Fetches the specified certificate from deviceweb
     *
     * @param string $certificateName The name of the certificate to fetch
     * @return string|false Certificate contents or false if it could not be fetched
     */
    public function fetchCertificate(string $certificateName)
    {
        $deviceUrl = $this->serverNameConfig->getServer('DEVICE_DATTOBACKUP_COM');
        $certApiUrl = sprintf(
            self::URL_FORMAT,
            $deviceUrl,
            $certificateName
        );

        $this->logger->info("CRT0066 Requesting certificate ...", ['url' => $certApiUrl]);

        $responseStr = $this->curlHelper->get(
            $certApiUrl
        );

        if (!$responseStr) {
            $this->logger->warning("CRT0067 Unable to fetch certificate", ['url' => $certApiUrl]);
            return false;
        }

        $isCertificate = strpos($responseStr, '-----BEGIN CERTIFICATE-----') !== false &&
                         strpos($responseStr, '-----END CERTIFICATE-----') !== false;
        if (!$isCertificate) {
            // it's possible there won't be a cert, that's fine
            $this->logger->warning(
                'CRT0068 Response from device-web was not a certificate',
                [ "response" => $responseStr ]
            );

            return false;
        }

        return $responseStr;
    }
}
