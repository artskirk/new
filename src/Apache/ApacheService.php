<?php

namespace Datto\Apache;

use Datto\Common\Utility\Filesystem;
use Datto\Config\DeviceConfig;
use Datto\Feature\FeatureService;
use Datto\Log\DeviceLoggerInterface;
use Datto\System\ServerCertificateConfiguration;
use Datto\System\ServerCertificateManager;

/**
 * Configures Apache sites based on the type of device.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class ApacheService
{
    const DIR_SITES_AVAILABLE = '/etc/apache2/sites-available';
    const DIR_SITES_ENABLED = '/etc/apache2/sites-enabled';
    const SITE_FILE_DEVICE = 'device.conf';
    const SITE_FILE_SERVER = 'server.conf';
    const SITE_FILE_LEGACY_HTTP = '001-http.conf';
    const SITE_FILE_LEGACY_HTTPS = '002-https.conf';

    const APACHE_CA_BUNDLE_FILE = '/etc/apache2/ssl/ca-bundle.crt';
    const APACHE_SERVICE = 'apache2.service';
    const CLOUDAPI_TRUSTED_CERTIFICATE_NAME = 'cloud-api-ca';

    private DeviceLoggerInterface $logger;
    private Filesystem $filesystem;
    private FeatureService $featureService;
    private DeviceConfig $deviceConfig;
    private ServerCertificateManager $serverCertificateManager;

    public function __construct(
        DeviceLoggerInterface $logger,
        Filesystem $filesystem,
        FeatureService $featureService,
        DeviceConfig $deviceConfig,
        ServerCertificateManager $serverCertificateManager
    ) {
        $this->logger = $logger;
        $this->filesystem = $filesystem;
        $this->featureService = $featureService;
        $this->deviceConfig = $deviceConfig;
        $this->serverCertificateManager = $serverCertificateManager;
    }

    /**
     * Enable/disable Apache configuration based on the
     * type of the device.
     */
    public function configure(): void
    {
        if ($this->featureService->isSupported(FeatureService::FEATURE_APACHE_CLOUD_CONFIG)) {
            $this->enable(self::SITE_FILE_SERVER);
            $this->disable(self::SITE_FILE_DEVICE);
        } else {
            $this->enable(self::SITE_FILE_DEVICE);
            $this->disable(self::SITE_FILE_SERVER);
        }

        $this->disable(self::SITE_FILE_LEGACY_HTTP);
        $this->disable(self::SITE_FILE_LEGACY_HTTPS);
    }

    /**
     * Checks if there is a new trusted root certificate to download from deviceweb. If there is, trusted root
     * certificate is downloaded and the apache service is restarted.
     */
    public function checkTrustedRootCertificates(bool $restartService): void
    {
        $this->serverCertificateManager->updateTrustedRootCertificate(
            new ServerCertificateConfiguration(
                self::CLOUDAPI_TRUSTED_CERTIFICATE_NAME,
                self::APACHE_SERVICE,
                self::APACHE_CA_BUNDLE_FILE,
                true
            ),
            $restartService
        );
    }

    /**
     * Enable site by symlinking a site configuration.
     *
     * @param string $site
     */
    private function enable(string $site): void
    {
        $link = self::DIR_SITES_ENABLED . '/' . $site;
        $target = self::DIR_SITES_AVAILABLE . '/' . $site;

        if (!$this->filesystem->isLink($link)) {
            $this->logger->info('APA0001 Enabling Apache site.', ['site' => $site]);
            $this->filesystem->symlink($target, $link);
        }
    }

    /**
     * Disable site by unlinking a site configuratoin symlink.
     *
     * @param string $site
     */
    private function disable(string $site): void
    {
        $link = self::DIR_SITES_ENABLED . '/' . $site;

        if ($this->filesystem->isLink($link)) {
            $this->logger->info('APA0002 Disabling Apache site.', ['site' => $site]);
            $this->filesystem->unlink($link);
        }
    }
}
