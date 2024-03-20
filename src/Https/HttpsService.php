<?php

namespace Datto\Https;

use Datto\Cloud\JsonRpcClient;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\DeviceConfig;
use Datto\Config\LocalConfig;
use Datto\Core\Network\DeviceAddress;
use Datto\Mercury\MercuryFtpService;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Exception;
use Datto\Log\DeviceLoggerInterface;
use GuzzleHttp;

/**
 * Service to manage the x509 certificate and private key used
 * by the Apache webserver.
 *
 * The service supports two modes:
 * - default: If no 'httpsMode' key is set or it is set to 'default',
 *   a self-signed certificate is generated for Apache.
 * - auto: If 'httpsMode' is set to 'auto', a CSR is generated and
 *   device web is contacted to ask Let's Encrypt to issue a proper
 *   certificate.
 *
 * The service knows 3 key files:
 * - httpsMode: Defines the mode (default or auto) in which to run
 * - httpsRedirectEnable: Enables automatic redirect via the web
 *   interface; but only if the connectivity check succeeds.
 * - httpsRedirectNoVerify: If set, the connectivity check does not
 *   verify the certificate's authenticity. This flag is useful for
 *   testing only!
 *
 * The service only supports two public methods:
 * - The check() method checks whether the certificate needs to
 *   be renewed (depending on its validity and the HTTPS mode),
 *   renews it if necessary, and performs a connectivity check
 *   afterwards.
 * - The checkConnectivity() method only performs a connectivity
 *   check.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class HttpsService
{
    const MODE_AUTO = 'auto';
    const MODE_CUSTOM = 'custom';
    const MODE_SELFSIGNED = 'selfsigned';
    const MODE_DEFAULT = self::MODE_AUTO;
    const EXPIRY_THRESHOLD_SOFT_SECONDS = 2592000; // 30 days
    const EXPIRY_THRESHOLD_HARD_SECONDS = 259200; // 3 days
    const TEMP_PATH_CSR = '/tmp/www.csr.tmp';
    const TEMP_PATH_CRT = '/tmp/www.crt.tmp';
    const TEMP_PATH_KEY = '/tmp/www.key.tmp';
    const TARGET_PATH_KEY = '/etc/apache2/ssl/www.key';
    const TARGET_PATH_CRT = '/etc/apache2/ssl/www.crt';
    const CHECK_CONNECT_TIMEOUT_SECONDS = 2;
    const CHECK_SUCCESS_HTTP_CODE = 200;
    const CERT_DEFAULT_COMMON_NAME = 'default';
    const CERT_SELF_SIGNED_SUBJECT_FORMAT = '/C=US/ST=CT/L=Norwalk/O=Datto/OU=CoreProductsFTW/CN=%s';
    const CERT_SELF_SIGNED_EXPIRY_DAYS = 180;
    const MODE_FLAG = 'httpsMode';
    const REDIRECT_DISABLE_FLAG = 'httpsRedirectDisable';
    const REDIRECT_NO_VERIFY_FLAG = 'httpsRedirectNoVerify';
    const REDIRECT_HOST_CACHE_FILE = '/dev/shm/httpsRedirectHost';
    const REDIRECT_HOST_CACHE_TTL_IN_SEC = 3600; // 1 hour
    const DEVICE_IP_CACHE_FILE = '/dev/shm/deviceIP';
    const DDNS_UPDATED_CACHE_FILE = '/dev/shm/ddnsDomainUpdated';
    const DDNS_UPDATED_CACHE_MAX_AGE_SECONDS = 86400; // 1 day
    const DEVICE_REGISTERED_FLAG = 'reg';
    const PERMISSIONS_MODE = 0600;
    const APACHE_GROUP = 'www-data';

    private Filesystem $filesystem;
    private DateTimeService $dateTimeService;
    private DeviceConfig $deviceConfig;
    private LocalConfig $localConfig;
    private ProcessFactory $processFactory;
    private JsonRpcClient $client;
    private DeviceLoggerInterface $logger;
    private DeviceAddress $deviceAddress;
    private GuzzleHttp\Client $httpClient;
    private MercuryFtpService $mercuryFtpService;

    public function __construct(
        Filesystem $filesystem,
        DateTimeService $dateTimeService,
        DeviceConfig $deviceConfig,
        LocalConfig $localConfig,
        ProcessFactory $processFactory,
        JsonRpcClient $client,
        DeviceLoggerInterface $logger,
        DeviceAddress $deviceAddress,
        GuzzleHttp\Client $httpClient,
        MercuryFtpService $mercuryFtpService
    ) {
        $this->filesystem = $filesystem;
        $this->dateTimeService = $dateTimeService;
        $this->deviceConfig = $deviceConfig;
        $this->localConfig = $localConfig;
        $this->processFactory = $processFactory;
        $this->client = $client;
        $this->logger = $logger;
        $this->deviceAddress = $deviceAddress;
        $this->httpClient = $httpClient;
        $this->mercuryFtpService = $mercuryFtpService;
    }

    /**
     * Check and renew the existing certificate if necessary,
     * and perform a connectivity check against the HTTPS URL.
     *
     * DDNS Update:
     *   Update the device's DDNS domain name in the cloud
     *
     * Certificate renewal:
     *   If the certificate needs to be renewed and the HTTPS
     *   mode is set to 'default', a self-signed certificate will be
     *   generated, placed in /etc/apache2/ssl, and the webserver
     *   will be restarted. See renewSelfSigned() method.
     *
     *   If the certificate needs to be renewed and the HTTPS
     *   mode is set to 'auto', the device's DDNS domain is requested
     *   from device web so that a CSR can be generated. This CSR
     *   is then sent to device web which will request a proper
     *   certificate from Let's Encrypt. Once the certificate is
     *   placed in /etc/apache2/ssl, the webserver is restarted.
     *   See renewViaCloud() method.
     *
     * Redirect host update:
     *   Performs a HTTPS request against the HTTPS URL to check if it
     *   is reachable. This is necessary to be sure that a redirect in
     *   the web UI will succeed.
     *
     *   As redirect host, the certificate's common name is used (if it's
     *   a proper certificate), or in the case of a self-signed certificate,
     *   or the local IP address is used.
     *
     * @param bool $forceRenewal renew the certificate regardless of other checks
     *
     * @return bool indicating successful https check if true
     */
    public function check(bool $forceRenewal): bool
    {
        $isSuccessful = true;
        $isRegistered = $this->isRegistered();

        if (!$isRegistered) {
            $this->logger->info('HTT0000 Skipping DDNS and HTTPS certificate checks. Device not registered.');
            return false;
        }

        $ipHasChanged = $this->hasDeviceIpAddressChanged();
        $ddnsIsStale = $this->isDdnsStale();
        $renewalStatus = $this->checkRenewalStatus($forceRenewal);
        $renewalNeeded = $renewalStatus !== RenewalStatus::NOT_NEEDED();
        $redirectCacheValid = $this->isRedirectHostCacheValid();

        if ($ipHasChanged || $ddnsIsStale) {
            $this->updateDdns();
        }

        if ($renewalNeeded) {
            $isSuccessful = $this->renewCertificate($renewalStatus);
            $this->updateRedirectHost();
        } elseif (!$redirectCacheValid) {
            $this->updateRedirectHost();
        }
        return $isSuccessful;
    }

    /**
     * This runs the check without enabling the redirect.
     * This is used by registration since redirecting breaks the automatic login.
     */
    public function checkWithoutRedirect()
    {
        try {
            // prevent the redirect from occuring between the time we run the check and remove the host cache file
            $this->deviceConfig->set(self::REDIRECT_DISABLE_FLAG, true);
            $this->check(false);
            // remove the cache to prevent the redirect until the next time the https check runs
            $this->filesystem->unlink(self::REDIRECT_HOST_CACHE_FILE);
        } finally {
            // We do NOT want this to remain disabled
            $this->deviceConfig->clear(self::REDIRECT_DISABLE_FLAG);
        }
    }

    /**
     * Returns true if the redirect enable flag is set. It does not
     * mean that the user interface will automatically redirect.
     *
     * @return bool
     */
    public function isRedirectEnabled()
    {
        return !$this->deviceConfig->has(self::REDIRECT_DISABLE_FLAG);
    }

    /**
     * Returns the HTTPS redirect hostname if available and accessible,
     * e.g. xyj24js.dattolocal.net.
     *
     * This method only returns the hostname if the "redirect enable" flag
     * is set and the redirect host has been checked and confirmed recently.
     *
     * @return bool|string
     */
    public function getRedirectHost()
    {
        $hasRedirectHost = $this->isRedirectEnabled()
            && $this->filesystem->exists(self::REDIRECT_HOST_CACHE_FILE);

        return $hasRedirectHost ? trim($this->filesystem->fileGetContents(self::REDIRECT_HOST_CACHE_FILE)) : false;
    }

    /**
     * Checks if an HTTPS URL can be accessed via a normal HTTPS client.
     *
     * @param string $secureUrl HTTPS URL ("https://<cn>/")
     * @param bool $verifyCertificate true to validate the certificate
     * @return bool true if the HTTPS URL was successfully accessed
     */
    public function checkConnectivity(string $secureUrl, bool $verifyCertificate = true): bool
    {
        $result = $this->httpClient->request('GET', $secureUrl, [
            'connect_timeout' => self::CHECK_CONNECT_TIMEOUT_SECONDS,
            'allow_redirects' => true,
            'verify' => $verifyCertificate
        ]);

        return $result->getStatusCode() === self::CHECK_SUCCESS_HTTP_CODE;
    }

    /**
     * Checks if this request came in via the primary network interface OR
     * the client is on the same subnet as the primary network interface.
     * In either of these cases, HTTPS redirects are allowed.
     *
     * @return bool true if HTTPS redirects are allowed, false if not
     */
    public function allowRedirectToPrimaryNetworkInterface(): bool
    {
        if (empty($_SERVER['SERVER_ADDR'])) {
            return false;
        }

        $serverAddr = $_SERVER['SERVER_ADDR'];

        $primaryIp = $this->deviceAddress->getLocalIp();

        return !empty($primaryIp) && $serverAddr === $primaryIp;
    }

    /**
     * Update the DDNS domain in the cloud with the current local IP address and record the time in the cache file
     *
     * @return string DDNS domain name for device
     */
    private function updateDdns(): string
    {
        $localIpAddress = $this->deviceAddress->getLocalIp();
        $this->logger->info('DNS0002 Updating DDNS domain with local IP address.', ['localIpAddress' => $localIpAddress]);

        $result = $this->client->queryWithId('v1/device/ddns/update', [
            'ipAddress' => $localIpAddress
        ]);

        if (!isset($result['domain'])) {
            throw new Exception('Cannot update IP address in DDNS service. Invalid result received.');
        }

        $domain = $result['domain'];
        $this->logger->info('DNS0003 Successfully updated domain.', ['domain' => $domain]);

        $this->filesystem->filePutContents(self::DDNS_UPDATED_CACHE_FILE, $this->dateTimeService->getTime());

        return $domain;
    }

    /**
     * Renews the device's HTTPS certificate
     *
     * @param RenewalStatus $renewalStatus
     *
     * @return bool indicating successful renewal if true
     */
    private function renewCertificate(RenewalStatus $renewalStatus): bool
    {
        if ($this->getMode() === self::MODE_AUTO) {
            return $this->renewViaCloud($renewalStatus);
        } elseif ($this->getMode() === self::MODE_SELFSIGNED) {
            $this->renewSelfSigned();
            return true;
        } elseif ($renewalStatus === RenewalStatus::NEEDED_NOW()) {
            $this->logger->warning('HTT7001 Certificate renewal urgently needed. Creating self-signed certificate', ['mode' => $this->getMode()]);
            $this->renewSelfSigned();
            return true;
        } else {
            $this->logger->info('HTT2586 Skipping certificate renewal.', ['mode' => $this->getMode()]);
            return false;
        }
    }

    /**
     * Reads the common name (CN) from the existing certificate
     * and checks if the HTTPS URL (https://<cn>) can be accessed
     * via a normal HTTPS client.
     *
     * If the web request is successful, a HTTPS host flag is written to /dev/shm,
     * which is then later used to automatically redirect web requests.
     *
     * If the certificate is self-signed, the local IP is used instead
     * of the CN. All requests will fail anyway though unless the
     * 'httpsRedirectNoVerify' flag is set.
     *
     * If the 'httpsRedirectNoVerify' is set, the HTTPS certificate will
     * not be verified. This is only useful for debugging/testing.
     */
    private function updateRedirectHost()
    {
        if ($this->deviceConfig->isCloudDevice() || $this->deviceConfig->isAzureDevice()) {
            $this->logger->debug('HTT9006 System is a cloud device, skipping automatic HTTPS redirect update.');
            $this->filesystem->unlinkIfExists(self::REDIRECT_HOST_CACHE_FILE);

            return;
        }

        try {
            $certificate = $this->parseExistingCertificate();
            $httpsHost = $certificate->isSelfSigned()
                ? $this->deviceAddress->getLocalIp()
                : $certificate->getSubject()->getCommonName();

            $localSecureUrl = sprintf('https://%s/', $httpsHost);
            $verifyCertificate = !$this->deviceConfig->has(self::REDIRECT_NO_VERIFY_FLAG); // Allows debugging

            $this->logger->debug('HTT9001 Checking connectivity', ['localSecureUrl' => $localSecureUrl]);
            $success = $this->checkConnectivity($localSecureUrl, $verifyCertificate);

            if ($success) {
                $this->logger->info('HTT9002 Successful HTTPS connection established.', ['localSecureUrl' => $localSecureUrl]);
                $this->filesystem->filePutContents(self::REDIRECT_HOST_CACHE_FILE, $httpsHost);
            } else {
                $this->logger->warning('HTT9003 Connectivity check failed. Disabling automatic HTTPS redirect.', ['localSecureUrl' => $localSecureUrl]);

                $this->filesystem->unlinkIfExists(self::REDIRECT_HOST_CACHE_FILE);
            }
        } catch (Exception $e) {
            $this->logger->warning('HTT9004 Error checking HTTPS connectivity.', ['exception' => $e]);
            $this->logger->warning('HTT9005 Disabling automatic HTTPS redirect.');

            $this->filesystem->unlinkIfExists(self::REDIRECT_HOST_CACHE_FILE);
        }
    }

    /**
     * Check the DDNS cache file to determine whether DDNS domain needs to be updated
     *
     * @return bool true if DDNS domain needs to be updated in the cloud, otherwise false
     */
    private function isDdnsStale(): bool
    {
        if (!$this->filesystem->exists(self::DDNS_UPDATED_CACHE_FILE)) {
            $this->logger->debug('HTT0014 DDNS cache file is not present. DDNS should be updated.');
            return true;
        }

        $isStale = $this->dateTimeService->getTime() - $this->filesystem->fileMTime(self::DDNS_UPDATED_CACHE_FILE)
            >= self::DDNS_UPDATED_CACHE_MAX_AGE_SECONDS;

        if ($isStale) {
            $this->logger->debug('HTT0015 DDNS cache is stale. DDNS should be updated.');
        }

        return $isStale;
    }

    /**
     * Returns the redirect mode.
     *
     * @return string
     */
    private function getMode(): string
    {
        return $this->deviceConfig->get(self::MODE_FLAG, self::MODE_DEFAULT);
    }

    /**
     * Before checking https redirect host connectivity, look at whether it's necessary - checking too often causes
     * load on our DNS server. If the redirect host cache file is missing or stale, or if the redirect host update flag
     * is set (it will be if IP address or cert have changed), a new connectivity check is needed.
     *
     * @return bool true if redirect host connectivity check is needed, otherwise false
     */
    private function isRedirectHostCacheValid(): bool
    {
        $cacheExists = $this->filesystem->exists(self::REDIRECT_HOST_CACHE_FILE);
        $cacheAge = $cacheExists ?
            $this->filesystem->fileMTime(self::REDIRECT_HOST_CACHE_FILE) : 0;
        $currentTime = $this->dateTimeService->getTime();
        $cacheIsStale = $cacheAge < ($currentTime - self::REDIRECT_HOST_CACHE_TTL_IN_SEC);

        $cacheIsValid = $cacheExists && !$cacheIsStale;
        if ($cacheIsValid) {
            $this->logger->debug('HTT0016 Redirect host cache is valid; no update required if IP address is the same.');
        } else {
            $this->logger->debug('HTT0017 Redirect host cache is no longer valid; redirect host must be updated.');
        }
        return $cacheIsValid;
    }

    /**
     * Check whether the device's IP has changed from the last one recorded in the cache, and update the cache
     *
     * @return bool true if the device's IP address has changed since the last check, otherwise false.
     */
    private function hasDeviceIpAddressChanged(): bool
    {
        $cachedIpAddress = $this->filesystem->exists(self::DEVICE_IP_CACHE_FILE) ?
            $this->filesystem->fileGetContents(self::DEVICE_IP_CACHE_FILE) : 0;
        $currentIpAddress = $this->deviceAddress->getLocalIp();
        $this->filesystem->filePutContents(self::DEVICE_IP_CACHE_FILE, $currentIpAddress);

        $ipHasChanged = $cachedIpAddress !== $currentIpAddress;
        if ($ipHasChanged) {
            $this->logger->debug('HTT0013 Local IP address has changed since the last check.');
        }
        return $ipHasChanged;
    }

    /**
     * Read existing certificate and check whether or not it needs to be renewed.
     *
     * A device certificate needs to be renewed if:
     *
     * - It does not exist
     * - It is expired
     * - It will expire soon (30 days)
     * - It is the default certificate
     * - It was switched to 'auto' and is self-signed
     *
     * @param bool $forceRenewal renew the certificate regardless of other checks
     *
     * @return RenewalStatus https certificate renewal status
     */
    private function checkRenewalStatus(bool $forceRenewal): RenewalStatus
    {
        $this->logger->info('HTT0001 Checking if existing certificate needs to be renewed', ['mode' => $this->getMode()]);

        if ($forceRenewal) {
            $this->logger->info('HTT0018 Certificate renewal required. Renewal has been forced.');
            return RenewalStatus::NEEDED_NOW();
        }

        try {
            $cert = $this->parseExistingCertificate();
        } catch (Exception $e) {
            $this->logger->warning('HTT0002 Certificate renewal urgently required. Cannot read current certificate', ['exception' => $e]);
            return RenewalStatus::NEEDED_NOW();
        }

        $isExpired = $cert->getValidTo() - $this->dateTimeService->getTime() < self::EXPIRY_THRESHOLD_HARD_SECONDS;
        $willExpireSoon = $cert->getValidTo() - $this->dateTimeService->getTime() < self::EXPIRY_THRESHOLD_SOFT_SECONDS;
        $defaultCommonName = $cert->getSubject()->getCommonName() === self::CERT_DEFAULT_COMMON_NAME;
        $switchedToAuto = $this->getMode() === self::MODE_AUTO && $cert->isSelfSigned();

        $this->logger->debug(
            'HTT0003 Existing certificate',
            [
                'subject' => $cert->getSubject(),
                'issuer' => $cert->getIssuer(),
                'expiry' => date('m/d/Y H:i', $cert->getValidTo()),
                'self-signed' => $cert->isSelfSigned() ? 'yes' : 'no'
            ]
        );

        if ($isExpired) {
            $this->logger->warning('HTT0008 Certificate renewal urgently required. Certificate is expired.');
            return RenewalStatus::NEEDED_SOON();
        } elseif ($willExpireSoon) {
            $this->logger->info('HTT0009 Certificate renewal required. Certificate expires soon.');
            return RenewalStatus::NEEDED_SOON();
        } elseif ($defaultCommonName) {
            $this->logger->info('HTT0010 Certificate renewal required. Default certificate found.');
            return RenewalStatus::NEEDED_SOON();
        } elseif ($switchedToAuto) {
            $this->logger->info('HTT0011 Certificate renewal required. Self-signed certificate detected, but "auto" mode enabled.');
            return RenewalStatus::NEEDED_SOON();
        } else {
            $this->logger->info('HTT0012 Certificate renewal not required');
            return RenewalStatus::NOT_NEEDED();
        }
    }

    /**
     * Generates a new self-signed private key and certificate,
     * places them in /etc/apache2/ssl and restarts the web server.
     */
    private function renewSelfSigned()
    {
        $this->logger->info('HTT3001 Generating new self-signed certificate ...');

        $this->removeTempFiles();
        $this->generateSelfSignedKeyAndCert();
        $this->moveToFinalLocation();
        $this->restartWebserver();
        $this->mercuryFtpService->requestToRestart(self::TARGET_PATH_CRT);
        $this->removeTempFiles();

        $this->logger->info('HTT3002 Self-signed certificate successfully renewed.');
    }

    /**
     * Generate private key and self-signed certificate using
     * 'openssl' in /tmp.
     */
    private function generateSelfSignedKeyAndCert()
    {
        $this->logger->debug('HTT3030 Generating self-signed certificate ...');

        $localIpAddress = $this->deviceAddress->getLocalIp();
        $process = $this->processFactory
            ->get([
                'openssl',
                'req',
                '-new',
                '-x509',
                '-days',
                self::CERT_SELF_SIGNED_EXPIRY_DAYS,
                '-nodes',
                '-out', self::TEMP_PATH_CRT,
                '-keyout', self::TEMP_PATH_KEY,
                '-subj',
                sprintf(self::CERT_SELF_SIGNED_SUBJECT_FORMAT, $localIpAddress)
            ])
            ->setTimeout(120);

        $process->mustRun();
    }

    /**
     * Generate a certificate signing request using the device's
     * DDNS domain and request a new certificate via device-web. If
     * a new cert is returned, replace key/crt files in /etc/apache2/ssl
     * and restart the webserver.
     *
     * The renewal state is used only in case the renewal via the cloud fails.
     *
     * If it does fail and the renewal is urgently requested, a self-signed
     * certificate is generated.
     *
     * @param RenewalStatus $renewalStatus
     *
     * @return bool indicating successful renewal if true.
     */
    private function renewViaCloud(RenewalStatus $renewalStatus): bool
    {
        try {
            $this->logger->info('HTT2001 Requesting renewal of device certificate from cloud ...');

            $this->removeTempFiles();
            $this->generateCsr();
            $this->requestCertificate();
            $this->moveToFinalLocation();
            $this->restartWebserver();
            $this->mercuryFtpService->requestToRestart(self::TARGET_PATH_CRT);
            $this->removeTempFiles();

            $this->logger->info('HTT2006 Certificate successfully renewed.');
            return true;
        } catch (Exception $e) {
            $this->logger->error('HTT3031 Could not renew certificate via cloud', ['exception' => $e]);

            if ($renewalStatus === RenewalStatus::NEEDED_NOW()) {
                $this->logger->warning('HTT3032 Certificate renewal urgently needed. Falling back to a self-signed certificate.');
                $this->renewSelfSigned();
            } else {
                $this->logger->warning('HTT3033 Certificate renewal failed. Will attempt again later.');
            }
            return false;
        }
    }

    /**
     * Cleans up potentially existing temporary
     * files from old certificate renewal requests (/tmp/www.*).
     */
    private function removeTempFiles()
    {
        $this->logger->debug('HTT1004 Removing temporary certificate files in /tmp/www.* ...');

        @$this->filesystem->unlink(self::TEMP_PATH_CSR);
        @$this->filesystem->unlink(self::TEMP_PATH_KEY);
        @$this->filesystem->unlink(self::TEMP_PATH_CRT);
    }

    /**
     * Generate a certificate signing request (CSR) using the
     * device's DDNS domain.
     *
     * The domain is retrieved from device web using the DDNS service.
     *
     * The CSR and private key are temporarily stored in /tmp/www.*.
     */
    private function generateCsr()
    {
        $domain = $this->updateDdns();
        $this->logger->debug('HTT2002 Generating new keypair and CSR for domain', ['domain' => $domain]);

        $process = $this->processFactory
            ->get([
                'openssl',
                'req',
                '-nodes',
                '-newkey', 'rsa:2048',
                '-keyout', self::TEMP_PATH_KEY,
                '-out', self::TEMP_PATH_CSR,
                '-subj',
                sprintf(self::CERT_SELF_SIGNED_SUBJECT_FORMAT, $domain)
            ])
            ->setTimeout(120);

        $process->mustRun();

        $this->logger->debug('HTT2003 CSR created', ['path' => self::TEMP_PATH_CSR]);
    }

    /**
     * Request a new certificate from device web using the
     * previously generated CSR.
     *
     * The returned certificate is placed in /tmp/www.crt.tmp.
     */
    private function requestCertificate()
    {
        $this->logger->info('HTT2004 Requesting certificate from device web (this may take up to a minute) ...');

        if (!$this->filesystem->exists(self::TEMP_PATH_CSR)) {
            throw new Exception('Unable to read CSR.');
        }

        $result = $this->client->queryWithId('v1/device/https/renew', [
            'csr' => trim($this->filesystem->fileGetContents(self::TEMP_PATH_CSR))
        ]);

        if (!$result || !isset($result['chain'])) {
            throw new Exception('Invalid response from cert renewal.');
        }

        $this->filesystem->filePutContents(self::TEMP_PATH_CRT, $result['chain']);
        $this->logger->debug('HTT2005 Certificate was issued successfully', ['certificateStorage' => self::TEMP_PATH_CRT]);
    }

    /**
     * Moves the private key and certificate from /tmp
     * to the final location in /etc/apache2.
     *
     * After we move the file set the key to be 600 running under www-data
     */
    private function moveToFinalLocation()
    {
        $this->logger->debug('HTT1002 Moving key and certificate to final location in /etc/apache/ssl ...');

        if (!$this->filesystem->exists(self::TEMP_PATH_CRT)) {
            throw new Exception('Certificate file does not exist in temporary location.');
        }

        if (!$this->filesystem->exists(self::TEMP_PATH_KEY)) {
            throw new Exception('Key file does not exist in temporary location.');
        }

        $dir = dirname(self::TARGET_PATH_CRT);

        if (!$this->filesystem->isDir($dir)) {
            $this->filesystem->mkdir($dir, false, self::PERMISSIONS_MODE);
        }

        $this->filesystem->rename(self::TEMP_PATH_KEY, self::TARGET_PATH_KEY);
        $this->filesystem->rename(self::TEMP_PATH_CRT, self::TARGET_PATH_CRT);

        $this->filesystem->chmod($dir, self::PERMISSIONS_MODE, true);
        $this->filesystem->chown($dir, self::APACHE_GROUP, true);
        $this->filesystem->chgrp($dir, self::APACHE_GROUP, true);
    }

    /**
     * Restarts the webserver. Never would have guessed that
     * from the name of the function, right?
     */
    private function restartWebserver()
    {
        $this->logger->debug('HTT1003 Restarting web server ...');

        $process = $this->processFactory->get(['apachectl', '-k', 'graceful']);

        $process->mustRun();
    }

    /**
     * Read the existing certificate file into an object.
     *
     * @return Certificate
     */
    private function parseExistingCertificate()
    {
        if (!$this->filesystem->exists(self::TARGET_PATH_CRT)) {
            throw new Exception('Certificate does not exist.');
        }

        $pem = $this->filesystem->fileGetContents(self::TARGET_PATH_CRT);
        return Certificate::fromPem($pem);
    }

    /**
     * Returns whether or not the device is registered. If it is not,
     * no certificate actions will be taken. This is not ideal,
     * but better than interrupting the registration process.
     *
     * @return bool
     */
    private function isRegistered()
    {
        return $this->localConfig->has(self::DEVICE_REGISTERED_FLAG);
    }
}
