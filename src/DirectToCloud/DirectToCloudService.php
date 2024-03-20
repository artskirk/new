<?php

namespace Datto\DirectToCloud;

use Datto\Log\LoggerAwareTrait;
use Datto\System\ServerCertificateConfiguration;
use Datto\System\ServerCertificateManager;
use Psr\Log\LoggerAwareInterface;

/**
 * Configures DTC Server so that it has the necessary root CAs to be able to communicate
 * with agents that have received certificates from Cloudbase.
 *
 * @author Ben Lucas <blucas@datto.com>
 */
class DirectToCloudService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const DTC_CA_BUNDLE_FILE = '/etc/datto/dtc/certs/ca.pem';
    const DTC_SERVER_SERVICE = 'dtcserver.service';
    const CLOUDBASE_TRUSTED_CERTIFICATE_NAME = 'cloudbase-ca';

    private ServerCertificateManager $serverCertificateManager;

    public function __construct(ServerCertificateManager $serverCertificateManager)
    {
        $this->serverCertificateManager = $serverCertificateManager;
    }

    /**
     * Checks if there is a new trusted root certificate to download from deviceweb. If there is, trusted root
     * certificate is downloaded and the dtccommander service is restarted.
     */
    public function checkTrustedRootCertificates(bool $restartService)
    {
        $this->serverCertificateManager->updateTrustedRootCertificate(
            new ServerCertificateConfiguration(
                self::CLOUDBASE_TRUSTED_CERTIFICATE_NAME,
                self::DTC_SERVER_SERVICE,
                self::DTC_CA_BUNDLE_FILE,
                false
            ),
            $restartService
        );
    }
}
