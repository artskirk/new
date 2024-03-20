<?php

namespace Datto\Asset\Agent\Certificate;

use Datto\Common\Resource\ProcessFactory;
use Datto\Log\LoggerFactory;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Exception;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * This class is responsible for managing how and where the certificates used for Agent communications
 *   are stored. Clients include but are not limited to CertificateHelper and the AgentAPIs.
 *
 * @author Chad Barbe <cbarbe@datto.com>
 */
class CertificateSetStore
{
    /** @var string separator for filename parts */
    const DELIMITER = '_';

    /** @var string Path to certificate directory */
    const DATTO_CERT_DIR_PATH = '/datto/config/certs';

    /** @var string Private key file associated with the device certificate */
    const DEVICE_KEY_NAME = 'device.key';

    /** @var string Device certificate file */
    const DEVICE_CERT_NAME = 'device.pem';

    /** @var string Root certificate file used to sign the device certificate and to validate the agent */
    const DATTO_AGENT_CA_CERT_NAME = 'dattoAgentCaCert.crt';

    /** @var string Glob to list agent root certificates */
    const DATTO_AGENT_CA_CERT_GLOB = self::DATTO_CERT_DIR_PATH . '/*' . self::DELIMITER . self::DATTO_AGENT_CA_CERT_NAME;

    /** @var Filesystem */
    private $filesystem;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var DeviceLoggerInterface */
    private $logger;

    public function __construct(
        DeviceLoggerInterface $logger = null,
        Filesystem $filesystem = null,
        DateTimeService $dateTimeService = null
    ) {
        $this->logger = $logger ?? LoggerFactory::getDeviceLogger();
        $this->filesystem = $filesystem ?? new Filesystem(new ProcessFactory());
        $this->dateTimeService = $dateTimeService ?? new DateTimeService();
    }

    /**
     * Adds a set of certificate/key contents to our store.
     *
     * @param string $trustedRootCertificateContents
     * @param string $deviceCertificateContents
     * @param string $deviceKeyContents
     */
    public function add(
        string $trustedRootCertificateContents,
        string $deviceCertificateContents,
        string $deviceKeyContents
    ): void {
        $date = $this->dateTimeService->getDate('YmdHis');
        $hash = md5($trustedRootCertificateContents);

        $trustedRootFile = $this->buildFilePath(self::DATTO_CERT_DIR_PATH, $date, $hash, self::DATTO_AGENT_CA_CERT_NAME);
        $clientCertFile = $this->buildFilePath(self::DATTO_CERT_DIR_PATH, $date, $hash, self::DEVICE_CERT_NAME);
        $clientKeyFile = $this->buildFilePath(self::DATTO_CERT_DIR_PATH, $date, $hash, self::DEVICE_KEY_NAME);

        try {
            $this->logger->info('CRT0057 - Writing certificate file', ['clientCertFile' => $clientCertFile]);
            $this->saveFile($clientCertFile, $deviceCertificateContents, "certificate");
            $this->filesystem->chmod($clientCertFile, 0644);
            $this->logger->info('CRT0056 - Writing private key file', ['clientKeyFile' => $clientKeyFile]);
            $this->saveFile($clientKeyFile, $deviceKeyContents, "private key");
            // Change permissions on the file to be 0640 and root:ssl-cert
            $this->filesystem->chmod($clientKeyFile, 0640);
            $this->filesystem->chgrp($clientKeyFile, 'ssl-cert');
            // This should always be written last to prevent unnecessary
            // "Missing device certificates" warnings in "getCertificateSets()"
            $this->logger->info('CRT0064 - Writing trusted root file', ['trustedRootFile' => $trustedRootFile]);
            $this->saveFile($trustedRootFile, $trustedRootCertificateContents, "trusted root");
            $this->filesystem->chmod($trustedRootFile, 0644);
        } catch (Throwable $t) {
            $this->filesystem->unlinkIfExists($trustedRootFile);
            $this->filesystem->unlinkIfExists($clientCertFile);
            $this->filesystem->unlinkIfExists($clientKeyFile);
            throw($t);
        }
    }

    /**
     * Returns the list of certificates on the device, with most recent first.
     *
     * @param string|null $trustedRootOverrideFile if provided, put the provided files
     *   as the trusted root for all sets.
     * @return CertificateSet[]
     */
    public function getCertificateSets(string $trustedRootOverrideFile = null): array
    {
        $certificateSets = [];
        $dattoAgentCaCerts = $this->filesystem->glob(self::DATTO_AGENT_CA_CERT_GLOB);
        foreach ($dattoAgentCaCerts as $rootCertificatePath) {
            $parsed = $this->parseFilePath($rootCertificatePath);
            if ($parsed) {
                $deviceKeyPath = $this->buildFilePath(
                    $parsed['path'],
                    $parsed['date'],
                    $parsed['hash'],
                    self::DEVICE_KEY_NAME
                );
                $deviceCertPath = $this->buildFilePath(
                    $parsed['path'],
                    $parsed['date'],
                    $parsed['hash'],
                    self::DEVICE_CERT_NAME
                );
                if ($this->filesystem->exists($deviceKeyPath) && $this->filesystem->exists($deviceCertPath)) {
                    $certificateSets[] = new CertificateSet(
                        $parsed['date'],
                        $parsed['hash'],
                        is_null($trustedRootOverrideFile)
                            ? $rootCertificatePath
                            : self::DATTO_CERT_DIR_PATH . "/" . $trustedRootOverrideFile,
                        $deviceKeyPath,
                        $deviceCertPath
                    );
                } else {
                    $this->logger->warning(
                        'CRT0001 Missing device certificates for root certificate',
                        ['rootCertificatePath' => $rootCertificatePath]
                    );
                }
            }
        }

        return array_reverse($certificateSets);
    }

    /**
     * Build a filesystem path to a certificate file from the file name components.
     *
     * @param string $path Directory path (must not be blank)
     * @param string $date Date part of the file name
     * @param string $hash Hash part of the file name
     * @param string $name Base name part of the file name
     * @return string Full path to the certificate file
     */
    private function buildFilePath(string $path, string $date, string $hash, string $name)
    {
        return "$path/${date}_${hash}_${name}";
    }

    /**
     * Parse a filesystem path name to a certificate file and return the components.
     *
     * @param string $filePath Full path to the certificate file
     * @return array If successful, the array contains the following keys:
     *     path   The directory path
     *     date   The date part of the file name
     *     hash   The hash part of the file name
     *     name   The base name part of the file name
     *   If the file path is not in the correct format, an empty array is returned.
     */
    private function parseFilePath(string $filePath): array
    {
        if (preg_match('#^(?<path>.*)/(?<date>[^/_]+)_(?<hash>[^/_]+)_(?<name>[^/]+)$#', $filePath, $matches)) {
            return $matches;
        } else {
            return [];
        }
    }

    /**
     * @param string $file
     * @param string $contents
     * @param string $logName
     */
    private function saveFile(
        string $file,
        string $contents,
        string $logName
    ): void {
        $keyFileWritten = $this->filesystem->putAtomic($file, $contents);

        if (!$keyFileWritten) {
            throw new Exception("Cannot write $logName file to " . $file);
        }
    }
}
