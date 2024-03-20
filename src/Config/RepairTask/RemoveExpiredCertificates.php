<?php

namespace Datto\Config\RepairTask;

use Datto\Asset\Agent\Certificate\CertificateHelper;
use Datto\Asset\Agent\Certificate\CertificateSetStore;
use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\Common\Utility\Filesystem;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * Deletes expired certificate sets.
 *
 * Note: it is important that this RepairTask runs before any calls to
 * "CertificateSetStore::getCertificateSets()" to ensure that certificate files
 * are not deleted while they are in use.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class RemoveExpiredCertificates implements ConfigRepairTaskInterface
{
    /** @var Filesystem */
    private $filesystem;

    /** @var CertificateHelper */
    private $certificateHelper;

    /** @var CertificateSetStore */
    private $certificateSetStore;

    /** @var DeviceLoggerInterface */
    private $logger;

    public function __construct(
        Filesystem $filesystem,
        CertificateHelper $certificateHelper,
        CertificateSetStore $certificateSetStore,
        DeviceLoggerInterface $logger
    ) {
        $this->filesystem = $filesystem;
        $this->certificateHelper = $certificateHelper;
        $this->certificateSetStore = $certificateSetStore;
        $this->logger = $logger;
    }

    /**
     * Execute the task
     *
     * @return bool true if the task modified config, else false
     */
    public function run(): bool
    {
        $certificateSetsDeleted = false;
        $currentCertSets = $this->certificateSetStore->getCertificateSets();
        foreach ($currentCertSets as $certificateSet) {
            try {
                $rootExpirationSeconds = $this->certificateHelper->getCertificateExpirationSeconds($certificateSet->getRootCertificatePath());
                $deviceExpirationSeconds = $this->certificateHelper->getCertificateExpirationSeconds($certificateSet->getDeviceCertPath());
                if ($deviceExpirationSeconds < 0 || $rootExpirationSeconds < 0) {
                    $this->filesystem->unlink($certificateSet->getRootCertificatePath());
                    $this->filesystem->unlink($certificateSet->getDeviceKeyPath());
                    $this->filesystem->unlink($certificateSet->getDeviceCertPath());
                    $this->logger->info('CRT1001 Deleted expired certificate hash', ['expiredHash' => $certificateSet->getHash()]);
                    $certificateSetsDeleted = true;
                }
            } catch (Throwable $e) {
                $this->logger->error(
                    'CRT1002 Error during remove expired certificate hash',
                    ['expiredHash' => $certificateSet->getHash(), 'exception' => $e]
                );
            }
        }
        return $certificateSetsDeleted;
    }
}
