<?php

namespace Datto\System;

use Datto\Cloud\CertificateClient;
use Datto\Common\Resource\Filesystem;
use Datto\Log\LoggerAwareTrait;
use Datto\Util\RetryAttemptsExhaustedException;
use Datto\Util\RetryHandler;
use Datto\Utility\Systemd\Systemctl;
use Exception;
use Psr\Log\LoggerAwareInterface;
use Throwable;

class ServerCertificateManager implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private CertificateClient $certificateClient;
    private Systemctl $systemctl;
    private RetryHandler $retryHandler;
    private Filesystem $filesystem;

    /**
     * @var array<string,ServerCertificateConfiguration>
     */
    private $serverCertificateConfigurations;

    public function __construct(
        CertificateClient $certificateClient,
        Systemctl $systemctl,
        RetryHandler $retryHandler,
        Filesystem $filesystem
    ) {
        $this->certificateClient = $certificateClient;
        $this->systemctl = $systemctl;
        $this->retryHandler = $retryHandler;
        $this->filesystem = $filesystem;
    }

    /***
     * Downloads and installs the requested trusted root certificate.
     */
    public function updateTrustedRootCertificate(
        ServerCertificateConfiguration $serverCertificateConfiguration,
        bool $restartService
    ) {
        $trustedCertificateName = $serverCertificateConfiguration->getTrustedRootCertificateName();
        try {
            $this->logger->info(
                'SCM0000 Fetching trusted root certificate',
                ['trustedCertificateName' => $trustedCertificateName]
            );

            $trustedCertificateContents = $this->retrieveRootCertificate($trustedCertificateName);
            $caBundleContents = '';
            if ($serverCertificateConfiguration->getExtraCertificates()) {
                $caBundleContents = $serverCertificateConfiguration->getExtraCertificates() . PHP_EOL;
            }

            if ($trustedCertificateContents) {
                $caBundleContents = $caBundleContents . $trustedCertificateContents;
            } else {
                $this->logger->warning(
                    'SCM0001 Trusted root certificate is not available on device-web',
                    ['trustedCertificateName' => $trustedCertificateName]
                );
            }

            $fileDoesNotExist = !$this->filesystem->exists($serverCertificateConfiguration->getCaBundlePath());

            if ($fileDoesNotExist || !empty($trustedCertificateContents)) {
                $downloadedCertSha1 = sha1($caBundleContents);
                $existingCertSha1 = $this->filesystem->sha1($serverCertificateConfiguration->getCaBundlePath());
                $fileChanged = $downloadedCertSha1 !== $existingCertSha1;

                if ($fileChanged) {
                    $this->logger->info(
                        'SCM0002 Writing trusted root certificates to ca bundle.',
                        [
                            'trustedCertificateName' => $trustedCertificateName,
                            'fileLocation' => $serverCertificateConfiguration->getCaBundlePath()
                        ]
                    );

                    if (!$this->filesystem->filePutContents(
                        $serverCertificateConfiguration->getCaBundlePath(),
                        $caBundleContents
                    )) {
                        throw new Exception("Failed to write trusted root certificate $trustedCertificateName");
                    }

                    if ($restartService) {
                        $this->resetOrReloadService(
                            $serverCertificateConfiguration->getServiceName(),
                            $serverCertificateConfiguration->getSupportsReload()
                        );
                    } else {
                        $this->logger->info(
                            'SCM0008 Not restarting service',
                            [
                                'trustedCertificateName' => $trustedCertificateName,
                                'serviceName' => $serverCertificateConfiguration->getServiceName()
                            ]
                        );
                    }
                } else {
                    $this->logger->debug(
                        'SCM0006 Trusted root contents did not change',
                        [
                            'trustedCertificateName' => $trustedCertificateName,
                            'fileLocation' => $serverCertificateConfiguration->getCaBundlePath()
                        ]
                    );
                }
            }
        } catch (Throwable $e) {
            $this->logger->error(
                'SCM0003 Failed to update trusted root certificates',
                [
                    'trustedCertificateName' => $trustedCertificateName,
                    'exception' => $e
                ]
            );

            throw $e;
        }
    }

    private function retrieveRootCertificate(string $certificateName): string
    {
        try {
            $certificate = $this->retryHandler->executeAllowRetry(
                function () use ($certificateName) {
                    $certificate = $this->certificateClient->fetchCertificate($certificateName);

                    if (!$certificate) {
                        throw new Exception('Could not fetch trusted root certificate from device-web');
                    }

                    return $certificate;
                }
            );
        } catch (RetryAttemptsExhaustedException $e) {
            // logged in the RetryHandler

            $certificate = '';
        }
        return $certificate;
    }

    private function resetOrReloadService(string $service, bool $reload)
    {
        if ($this->systemctl->isActive($service)) {
            if ($reload) {
                $this->logger->info('SCM0004 Reloading service', ['serviceName' => $service]);
                $this->systemctl->reload($service);
            } else {
                $this->logger->info('SCM0005 Restarting service', ['serviceName' => $service]);
                $this->systemctl->restart($service);
            }
        } else {
            $this->logger->info('SCM0007 Starting service', ['serviceName' => $service]);
            $this->systemctl->start($service);
        }
    }
}
