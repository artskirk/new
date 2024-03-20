<?php

namespace Datto\Asset\Agent\Certificate;

use Datto\Cert\Certificate;
use Datto\Cert\CertificateManager;
use Datto\Cert\Config;
use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;

/**
 * Factory that handles constructing certificates and related classes.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class CertificateFactory
{
    /** @var Filesystem */
    private $filesystem;

    /**
     * @param Filesystem|null $filesystem
     */
    public function __construct(Filesystem $filesystem = null)
    {
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
    }

    /**
     * Construct a new certificate instance.
     *
     * @param string $certificateInfoFile
     * @return Certificate
     */
    public function createCertificate(string $certificateInfoFile): Certificate
    {
        return new Certificate($certificateInfoFile);
    }

    /**
     * Construct a new certificate manager instance.
     *
     * @param string $certificateCaConfigFile
     * @return CertificateManager
     */
    public function createCertificateManager(string $certificateCaConfigFile): CertificateManager
    {
        if (!$this->filesystem->isReadable($certificateCaConfigFile)) {
            throw new \Exception("Certificate request / CA config file not found. Unable to create CSR.");
        }

        return new CertificateManager(new Config($certificateCaConfigFile));
    }
}
