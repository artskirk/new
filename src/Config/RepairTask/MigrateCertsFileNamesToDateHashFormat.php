<?php

namespace Datto\Config\RepairTask;

use Datto\Asset\Agent\Certificate\CertificateSetStore;
use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\Common\Utility\Filesystem;

/**
 * Migrates SSL files used for agent communication from the old names to the
 *  new names which support rotating new SSL files into use.
 *
 * NOTE: this can be deleted after 5/3/2010 because by then, the old files are worthless since
 *   they are expiring.
 *
 * @author Chad Barbe <cbarbe@datto.com>
 */
class MigrateCertsFileNamesToDateHashFormat implements ConfigRepairTaskInterface
{
    /** @var string Path to certificate directory */
    const DATTO_CERT_DIR_PATH = '/datto/config/certs';

    /** @var string Private key file associated with the device certificate */
    const DEVICE_KEY_NAME = 'device.key';

    /** @var string Device certificate file */
    const DEVICE_CERT_NAME = 'device.pem';

    /** @var string Root certificate file used to sign the device certificate and to validate the agent */
    const DATTO_AGENT_CA_CERT_NAME = 'dattoAgentCaCert.crt';

    /** @var Filesystem */
    private $filesystem;

    /** @var CertificateSetStore */
    private $certificateSetStore;

    public function __construct(
        Filesystem $filesystem,
        CertificateSetStore $certificateSetStore
    ) {
        $this->filesystem = $filesystem;
        $this->certificateSetStore = $certificateSetStore;
    }

    /**
     * Execute the task
     *
     * @return bool true if the task modified config, else false
     */
    public function run(): bool
    {
        return $this->migrateCertsFileNamesToDateHashFormat();
    }

    /**
     * To support rotating expired or compromised root certificates, we need a more structured
     *  naming convention for our certificate/key files which includes:
     *   - the date that the trusted root certificate was downloaded from device-web
     *   - a hash of the contents of the trusted root certificate
     *
     * NOTE: this should be deleted after 5/3/2020 along with the CertificateRepairTask
     *  because after that all devices MUST have already been on the new system of certificates
     *  anyways.
     */
    public function migrateCertsFileNamesToDateHashFormat()
    {
        $oldTrustedRootPath = self::DATTO_CERT_DIR_PATH . "/" . self::DATTO_AGENT_CA_CERT_NAME;
        $oldDeviceCertPath = self::DATTO_CERT_DIR_PATH . "/" . self::DEVICE_CERT_NAME;
        $oldDeviceKeyPath = self::DATTO_CERT_DIR_PATH . "/" . self::DEVICE_KEY_NAME;

        if ($this->filesystem->exists($oldTrustedRootPath)
            && $this->filesystem->exists($oldDeviceCertPath)
            && $this->filesystem->exists($oldDeviceKeyPath)
            && count($this->certificateSetStore->getCertificateSets()) === 0) {
            $this->certificateSetStore->add(
                $this->filesystem->fileGetContents($oldTrustedRootPath),
                $this->filesystem->fileGetContents($oldDeviceCertPath),
                $this->filesystem->fileGetContents($oldDeviceKeyPath)
            );
            return true;
        }

        return false;
    }
}
