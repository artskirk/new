<?php

namespace Datto\Mercury;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Https\HttpsService;
use Datto\Log\LoggerAwareTrait;
use Datto\Resource\DateTimeService;
use Psr\Log\LoggerAwareInterface;

/**
 * Class for managing the TLS aspects of the MercuryFTP service.
 */
class MercuryFTPTLSService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * Contains a list of MercuryFTPEndpoint objects with the results of the latest TLS check.
     */
    const ENDPOINTS_STATUS_FILE = '/dev/shm/mercuryFtpEndpointsStatus.json';

    /**
     * The number of seconds after which the TLS validity of an endpoint will be checked again.
     */
    const RESULTS_VALIDITY_SECONDS = 7200; // 2 hours

    private Filesystem $filesystem;
    private ProcessFactory $processFactory;
    private DateTimeService $dateTimeService;

    public function __construct(
        Filesystem $filesystem,
        ProcessFactory $processFactory,
        DateTimeService $dateTimeService
    ) {
        $this->filesystem = $filesystem;
        $this->processFactory = $processFactory;
        $this->dateTimeService = $dateTimeService;
    }

    /**
     * Checks the TLS validity of all MercuryFTP endpoints and stores the results in a file.
     */
    public function checkTlsValidity(bool $force): void
    {
        $endpointsToCheck = $this->getEndpointsToCheck();
        $resultsLastCheck = $this->getSavedEndpoints();
        $shouldCheckEndpoints = $this->shouldCheckEndpoints($endpointsToCheck, $resultsLastCheck);
        if (!$shouldCheckEndpoints && !$force) {
            return;
        }

        foreach ($endpointsToCheck as $endpoint) {
            $endpoint->setIsTlsCertificateValid($this->checkEndpointTlsValidity($endpoint));
        }
        $this->filesystem->filePutContents(self::ENDPOINTS_STATUS_FILE, json_encode($endpointsToCheck));
    }

    /**
     * Returns the host name of the MercuryFTP server that the client should connect to.
     *
     * @param string $ipAddress The IP address of the MercuryFTP server.
     * @param bool $verifyCertificate Reference param that will be set to true if the certificate should be verified.
     */
    public function getMercuryFtpHost(string $ipAddress, bool &$verifyCertificate): string
    {
        $verifyCertificate = false;
        $host = $ipAddress;
        $savedEndpoints = $this->getSavedEndpoints();
        foreach ($savedEndpoints as $endpoint) {
            if ($endpoint->getIpAddress() === $ipAddress) {
                $host = $endpoint->getHostName();
                $verifyCertificate = $endpoint->isTlsCertificateValid();
                break;
            }
        }
        return $host;
    }

    /**
     * Returns the MercuryFTP endpoint(s) for the local device.
     *
     * @return MercuryFTPEndpoint[]
     */
    private function getEndpointsToCheck(): array
    {
        // Right now, this will only return a single endpoint, but in BCDR-29247 we will add support for multiple.

        $endpointsToCheck = [];
        if (!$this->filesystem->exists(HttpsService::REDIRECT_HOST_CACHE_FILE)
            || !$this->filesystem->exists(HttpsService::DEVICE_IP_CACHE_FILE)
        ) {
            return $endpointsToCheck;
        }
        $redirectHost = $this->filesystem->fileGetContents(HttpsService::REDIRECT_HOST_CACHE_FILE);
        $deviceIP = $this->filesystem->fileGetContents(HttpsService::DEVICE_IP_CACHE_FILE);
        if (!$redirectHost || !$deviceIP) {
            return $endpointsToCheck;
        }
        $endpointsToCheck[] = new MercuryFTPEndpoint($redirectHost, $deviceIP);
        return $endpointsToCheck;
    }

    /**
     * Returns the results from the last TLS check.
     *
     * @return MercuryFTPEndpoint[]
     */
    private function getSavedEndpoints(): array
    {
        $resultsLastCheck = [];
        if (!$this->filesystem->exists(self::ENDPOINTS_STATUS_FILE)) {
            return $resultsLastCheck;
        }
        $fileContents = $this->filesystem->fileGetContents(self::ENDPOINTS_STATUS_FILE);
        if (!$fileContents) {
            return $resultsLastCheck;
        }
        $resultsLastCheckJson = json_decode($fileContents, true);
        if (!is_array($resultsLastCheckJson)) {
            return $resultsLastCheck;
        }
        foreach ($resultsLastCheckJson as $result) {
            $resultsLastCheck[] = MercuryFTPEndpoint::fromArray($result);
        }
        return $resultsLastCheck;
    }

    /**
     * Checks if the TLS validity of the endpoints should be checked based on whether there are any new endpoints that
     * need to be checked and whether the results of the last check are stale or not.
     *
     * @param MercuryFTPEndpoint[] $endpointsToCheck
     * @param MercuryFTPEndpoint[] $resultsLastCheck
     */
    private function shouldCheckEndpoints(array $endpointsToCheck, array $resultsLastCheck): bool
    {
        if (!$this->filesystem->exists(self::ENDPOINTS_STATUS_FILE)) {
            return true;
        }
        $resultsMTime = $this->filesystem->fileMTime(self::ENDPOINTS_STATUS_FILE);
        if ($resultsMTime === false) {
            return true;
        }
        $resultsAreOld = $this->dateTimeService->getTime() - $resultsMTime > self::RESULTS_VALIDITY_SECONDS;

        // See if there are any endpoints to check that are not present in the results of the last check.
        $newEndpointsNeedChecking = false;
        foreach ($endpointsToCheck as $endpoint) {
            $foundMatch = false;
            foreach ($resultsLastCheck as $result) {
                if ($result->getHostname() === $endpoint->getHostname() &&
                    $result->getIpAddress() === $endpoint->getIpAddress()
                ) {
                    $foundMatch = true;
                }
            }
            if (!$foundMatch) {
                $newEndpointsNeedChecking = true;
            }
        }

        return $newEndpointsNeedChecking || $resultsAreOld;
    }

    /**
     * Checks that a valid TLS connection can be made to the endpoint.
     *
     * @return bool True if the TLS connection is valid, false otherwise.
     */
    private function checkEndpointTlsValidity(MercuryFTPEndpoint $endpoint): bool
    {
        // The 'echo' is necessary to terminate the openssl program.
        $cmd = 'echo | openssl s_client -connect "${:HOSTNAME}":' . MercuryFtpService::MERCURYFTP_TRANSFER_PORT;
        $process = $this->processFactory->getFromShellCommandLine($cmd);
        $process->run(null, ['HOSTNAME' => $endpoint->getHostname()]);
        $output = $process->getOutput();
        $stdErr = $process->getErrorOutput();

        $foundMatch = preg_match('/^\s*Verify return code: (\d+) \((.*)\)/m', $output, $matches) === 1;
        if (!$foundMatch) {
            $this->logger->warning('MFS0001 Could not parse TLS validity check output', ['hostName' => $endpoint->getHostname()]);
            $this->logger->debug('MFS0002 TLS validity check output', ['output' => $output, 'stdErr' => $stdErr]);
            return false;
        }
        $verifyReturnCode = $matches[1];
        $verifyReturnMessage = $matches[2];
        $isValid = $verifyReturnCode === '0';

        if ($isValid) {
            $this->logger->debug('MFS0005 TLS validity check succeeded', ['hostName' => $endpoint->getHostname()]);
        } else {
            $this->logger->warning(
                'MFS0003 TLS validity check failed',
                ['hostName' => $endpoint->getHostname(), 'verifyReturnCode' => $verifyReturnCode, 'verifyReturnMessage' => $verifyReturnMessage]
            );
            $this->logger->debug('MFS0004 TLS validity check output', ['output' => $output, 'stdErr' => $stdErr]);
        }

        return $isValid;
    }
}
