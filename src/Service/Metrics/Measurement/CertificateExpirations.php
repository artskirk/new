<?php

namespace Datto\Service\Metrics\Measurement;

use Datto\Apache\ApacheService;
use Datto\Asset\Agent\Certificate\CertificateSetStore;
use Datto\Common\Utility\Filesystem;
use Datto\DirectToCloud\DirectToCloudService;
use Datto\Feature\FeatureService;
use Datto\Https\HttpsService;
use Datto\Log\DeviceLoggerInterface;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Datto\Resource\DateTimeService;
use Datto\Service\Metrics\Measurement;
use Datto\Service\Metrics\MetricsContext;

class CertificateExpirations extends Measurement
{
    const CLOUDBASE_CA_FILE = DirectToCloudService::DTC_CA_BUNDLE_FILE;
    const APACHE_CA_FILE = ApacheService::APACHE_CA_BUNDLE_FILE;
    const APACHE_SSL_FILE = HttpsService::TARGET_PATH_CRT;

    private Filesystem $filesystem;
    private DateTimeService $dateTimeService;
    private CertificateSetStore $certificateSetStore;

    public function __construct(
        Collector $collector,
        FeatureService $featureService,
        DeviceLoggerInterface $logger,
        Filesystem $filesystem,
        DateTimeService $dateTimeService,
        CertificateSetStore $certificateSetStore
    ) {
        parent::__construct($collector, $featureService, $logger);

        $this->filesystem = $filesystem;
        $this->dateTimeService = $dateTimeService;
        $this->certificateSetStore = $certificateSetStore;
    }

    public function description(): string
    {
        return 'certificate expirations';
    }

    public function collect(MetricsContext $context)
    {
        $certificatesToWatch = $this->getCertificatesToWatch();

        foreach ($certificatesToWatch as $certificateToWatch) {
            $certificates = $this->readCertificates($certificateToWatch['files']);

            foreach ($certificates as $certificateId => $certificate) {
                $metric = Metrics::STATISTICS_CERTIFICATE_SECONDS_UNTIL_EXPIRED;
                $value = $this->getSecondsUntilExpired($certificate);
                $tags = [
                    'name' => $certificateToWatch['name'],
                    'certificate_id' => $certificateId
                ];

                $this->collector->measure($metric, $value, $tags);
            }
        }
    }

    public function getCertificatesToWatch(): array
    {
        $certificatesToWatch = [];

        $certificatesToWatch[] = [
            'name' => 'apache-ca',
            'files' => [self::APACHE_CA_FILE]
        ];

        $certificatesToWatch[] = [
            'name' => 'apache-letsencrypt',
            'files' => [self::APACHE_SSL_FILE]
        ];

        $certificatesToWatch[] = [
            'name' => 'cloudbase-ca',
            'files' => [self::CLOUDBASE_CA_FILE]
        ];

        $certificateSets = $this->certificateSetStore->getCertificateSets();
        if (isset($certificateSets[0])) {
            $certificatesToWatch[] = [
                'name' => 'onprem-agents-ca',
                'files' => [$certificateSets[0]->getRootCertificatePath()]
            ];
            $certificatesToWatch[] = [
                'name' => 'onprem-agents-client-cert',
                'files' => [$certificateSets[0]->getDeviceCertPath()]
            ];
        }

        return $certificatesToWatch;
    }

    public function getSecondsUntilExpired(array $certificate)
    {
        $expiration = $certificate['validTo_time_t'];

        return $expiration - $this->dateTimeService->getTime();
    }

    public function readCertificates(array $certificateFiles): array
    {
        $certificates = [];

        foreach ($certificateFiles as $certificateFile) {
            $fileContents = $this->filesystem->fileGetContents($certificateFile);

            preg_match_all(
                '/-----BEGIN CERTIFICATE-----[\S\s]+?-----END CERTIFICATE-----/m',
                $fileContents,
                $allMatches
            );

            foreach ($allMatches as $matches) {
                foreach ($matches as $encodedCertificate) {
                    $encodedCertificate = trim($encodedCertificate);

                    $certificate = openssl_x509_parse($encodedCertificate);
                    if ($certificate) {
                        $certificates[$this->getCertificateId($certificateFile, $certificate)] = $certificate;
                    }
                }
            }
        }

        return $certificates;
    }

    private function getCertificateId(string $path, array $certificate)
    {
        return sprintf('%s_%s', str_replace('/', '_', $path), $certificate['hash']);
    }
}
