<?php

namespace Datto\Asset\Agent\Certificate;

use Datto\Cert\Certificate;
use Datto\Cert\CertificateManager;
use Datto\Cert\CertificateRequest;
use Datto\Cloud\CertificateClient;
use Datto\Cloud\JsonRpcClient;
use Datto\Config\DeviceConfig;
use Datto\Log\LoggerAwareTrait;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Datto\Service\Device\EnvironmentService;
use Datto\Utility\File\LockFactory;
use Datto\Utility\File\LockInfo;
use Exception;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Sets up certificates that are needed by datto-based agents (e.g. Linux, Mac)
 *
 * @author Bartosz Neuman <bneuman@datto.com>
 */
class CertificateHelper implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** Local certificate request / CA config file used by the certificate manager. */
    const CLIENT_CERT_CA_CONFIG_FILE = '/datto/certs/dla/ca.conf';

    /** Time frame before certificate expiry at which to delete it locally and create a new one. */
    const CLIENT_CERT_RENEW_BEFORE_EXPIRY_SECONDS = 60 * 60 * 24 * 30; // 30 days

    private Filesystem $filesystem;
    private JsonRpcClient $cloudClient;
    private CertificateFactory $certificateFactory;
    private DateTimeService $dateService;
    private DeviceConfig $deviceConfig;
    private CertificateSetStore $certificateSetStore;
    private LockFactory $lockFactory;
    private CertificateClient $certificateClient;
    private EnvironmentService $environmentService;

    public function __construct(
        Filesystem $filesystem,
        JsonRpcClient $cloudClient,
        CertificateFactory $certificateFactory,
        DateTimeService $dateService,
        DeviceConfig $deviceConfig,
        CertificateSetStore $certificateSetStore,
        LockFactory $lockFactory,
        CertificateClient $certificateClient,
        EnvironmentService $environmentService
    ) {
        $this->filesystem = $filesystem;
        $this->cloudClient = $cloudClient;
        $this->certificateFactory = $certificateFactory;
        $this->dateService = $dateService;
        $this->deviceConfig = $deviceConfig;
        $this->certificateSetStore = $certificateSetStore;
        $this->lockFactory = $lockFactory;
        $this->certificateClient = $certificateClient;
        $this->environmentService = $environmentService;
    }

    /**
     * Gets the latest trusted root certificate from device-web and stores them in the CertificateStore.
     * Updates device certificates if it is within threshold of expiration.
     *
     * @param string $deviceWebTrustedRootHash Checkin sends down the trusted root hash of its current cert and that
     *   gets passed in here
     */
    public function updateCertificates(string $deviceWebTrustedRootHash)
    {
        try {
            $lock = $this->lockFactory->create(LockInfo::CERTIFICATE_HELPER_LOCK_PATH);
            $lock->assertExclusiveAllowWait(LockInfo::CERTIFICATE_HELPER_LOCK_WAIT_TIMEOUT);

            $currentCertSets = $this->certificateSetStore->getCertificateSets();

            if (!isset($currentCertSets[0]) || $currentCertSets[0]->getHash() !== $deviceWebTrustedRootHash) {
                $this->logger->info('CRT0052 Trusted root ca is missing or out of date.');
                $trustedRoot = $this->retrieveTrustedRootContents();
                if ($trustedRoot === false) {
                    throw new Exception('Could not fetch trusted root certificate from device-web');
                }
                $this->assertMatchesHash($trustedRoot, $deviceWebTrustedRootHash);
                $this->createKeyAndRetrieveCertificate($trustedRoot);
                return;
            }

            $this->checkAndHandleCertificateExpiration($currentCertSets[0]);
        } catch (Throwable $e) {
            $this->logger->error('CRT0043 Error during updateCertificates', ['exception' => $e]);
        }
    }

    /**
     * Assert that the hash of the trusted root matches what we're expecting (deviceWebTrustedRootHash)
     * This is a sanity check that protects us from continually creating new certs if there's an issue on device-web
     * and the hash doesn't match (such as if code was changed, or the certificate file couldn't be read)
     */
    private function assertMatchesHash(string $trustedRoot, string $deviceWebTrustedRootHash): void
    {
        $trustedRootHash = md5($trustedRoot);
        if ($trustedRootHash !== $deviceWebTrustedRootHash) {
            throw new Exception(
                'Trusted root that we received from device-web does not match the hash they sent us. Aborting. ' .
                "Retrieved root hash='$trustedRootHash' Device-web hash='$deviceWebTrustedRootHash'"
            );
        }
    }

    /**
     * Gets the remaining time until the certificate expires in seconds.
     *
     * @param string $certPath
     * @param int Value to return if certificate has no expiration (default 0)
     * @return int Remaining time until expiration in seconds
     */
    public function getCertificateExpirationSeconds(string $certPath, int $defaultTime = 0)
    {
        $certificate = $this->createCertificate($certPath);
        $validToTime = $certificate->getValidTo();
        if ($validToTime <= 0) {
            $this->logger->warning('CRT0065 Certificate has missing or invalid expiration date', ['certPath' => $certPath, 'validTo' => $validToTime]);
            return $defaultTime;
        }
        return $validToTime - $this->dateService->getTime();
    }

    /**
     * @param string $currentTrustedRootContents
     */
    protected function createKeyAndRetrieveCertificate(string $currentTrustedRootContents): void
    {
        $deviceId = $this->deviceConfig->getDeviceId();
        $certificateManager = $this->createCertificateManager();

        $this->logger->info("CRT0054 Generating keypair, and creating signing request ...");

        $keyPair = $certificateManager->generateKeyPair();
        $certificateRequest = $certificateManager->createCertificateRequest($keyPair, $deviceId);

        $privateKey = $keyPair->getPrivateKey()->getValue();
        $certificate = $this->retrieveCertificate($certificateRequest);

        $this->certificateSetStore->add($currentTrustedRootContents, $certificate, $privateKey);
        $this->logger->info("CRT0058 Client certificates successfully set up. ALL GOOD.");

        $this->environmentService->writeEnvironment();
    }

    /**
     * @param CertificateRequest $certificateRequest
     * @return string
     */
    private function retrieveCertificate(CertificateRequest $certificateRequest): string
    {
        $this->logger->info("CRT0061 Asking device-web to sign the certificate using new CA.");

        $issueCertificateParameters = ["csr" => $certificateRequest->getRequest()];
        $response = $this->cloudClient->queryWithId("v1/device/issueCert", $issueCertificateParameters);

        if (empty($response['certificate'])) {
            throw new Exception("Cannot retrieve certificate from device web. Empty result.");
        }

        return $response['certificate'];
    }

    /**
     * Returns the contents of the trusted root that device-web provides.
     *
     * @return string|false
     */
    public function retrieveTrustedRootContents()
    {
        return $this->certificateClient->retrieveTrustedRootContents();
    }

    /**
     * @return CertificateManager
     */
    private function createCertificateManager(): CertificateManager
    {
        return $this->certificateFactory->createCertificateManager(self::CLIENT_CERT_CA_CONFIG_FILE);
    }

    /**
     * @param string $certificateInfoPath path to the certificate file on disk
     * @return Certificate
     */
    private function createCertificate(string $certificateInfoPath): Certificate
    {
        return $this->certificateFactory->createCertificate($certificateInfoPath);
    }

    /**
     * @param string $certPath path to the client certificate currently on disk
     * @param int $certRenewSeconds
     * @return bool
     */
    private function isCertificateExpiringSoon(string $certPath, int $certRenewSeconds): bool
    {
        $expiresInSeconds = $this->getCertificateExpirationSeconds($certPath);
        return $expiresInSeconds < $certRenewSeconds;
    }

    /**
     * Check for certificate expiration and handle appropriately by:
     *      - logging the occurrence
     *      - requesting a new device cert if it is expiring and the root CA certificate is not also expiring
     * @param CertificateSet $currentCertSet
     */
    private function checkAndHandleCertificateExpiration(CertificateSet $currentCertSet): void
    {
        $certPath = $currentCertSet->getDeviceCertPath();
        $rootPath = $currentCertSet->getRootCertificatePath();

        $deviceCertExpiringSoon = $this->isCertificateExpiringSoon(
            $certPath,
            self::CLIENT_CERT_RENEW_BEFORE_EXPIRY_SECONDS
        );

        $rootCertExpiringSoon = $this->isCertificateExpiringSoon(
            $rootPath,
            self::CLIENT_CERT_RENEW_BEFORE_EXPIRY_SECONDS
        );

        if ($deviceCertExpiringSoon) {
            if (!$rootCertExpiringSoon) {
                $this->logger->info('CRT0059 Device certificate expiring soon, creating new key pair.');
                $trustedRoot = $this->filesystem->fileGetContents($rootPath);
                $this->createKeyAndRetrieveCertificate($trustedRoot);
            } else {
                $this->logger->info('CRT0060 Device and CA certificate are expiring soon.');
            }
        } elseif ($rootCertExpiringSoon) {
            $this->logger->warning("CRT0062 CA certificate is (or soon will be) out of date.");
        }
    }
}
