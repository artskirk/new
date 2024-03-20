<?php

namespace Datto\App\Controller\Api\V1\Device\Migrate;

use Datto\Cloud\CertificateClient;
use Datto\Config\DeviceConfig;
use Datto\Log\LoggerAwareTrait;
use Datto\Resource\DateTimeService;
use Datto\System\Ssh\SshServer;
use Datto\System\Migration\Device\DeviceMigrationAuthorizationNonceHandler;
use Exception;
use Psr\Log\LoggerAwareInterface;

/**
 * Device SSH Server API.
 * This is called by the SshClient on another device.
 * It is not intended to be used as a Web API.
 *
 * @author Chris LaRosa <clarosa@datto.com>
 */
class Ssh implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var SshServer */
    private $sshServer;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var CertificateClient */
    private $certificateClient;

    /** @var DeviceMigrationAuthorizationNonceHandler */
    private $nonceHandler;

    public function __construct(
        SshServer $sshServer,
        DateTimeService $dateTimeService,
        DeviceConfig $deviceConfig,
        CertificateClient $certificateClient,
        DeviceMigrationAuthorizationNonceHandler $nonceHandler
    ) {
        $this->sshServer = $sshServer;
        $this->dateTimeService = $dateTimeService;
        $this->deviceConfig = $deviceConfig;
        $this->certificateClient = $certificateClient;
        $this->nonceHandler = $nonceHandler;
    }

    /**
     * Start the SSH server and set the authorized key.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_DEVICE_MIGRATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_DEVICE_MIGRATION")
     * @param string $publicKey The public key that will be authorized to login via SSH
     * @param array $authToken An authorization token received from device web
     */
    public function startServer(string $publicKey, array $authToken): void
    {
        // Although the ability to do device migrations has been removed from the web UI, we are also disabling this
        // API endpoint to remove a security weakness. The weakness is that this startServer endpoint used to allow
        // login as root via SSH. When we revamp device migrations in the future, we should consider a more secure way
        // of transferring the necessary data instead of giving the other device full root access.
        throw new Exception('Device migrations have been disabled.');
    }

    /**
     * Stop the SSH server.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_DEVICE_MIGRATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_DEVICE_MIGRATION")
     */
    public function stopServer(): void
    {
        $this->sshServer->tearDown();
    }

    /**
     * Checks that the other device is authorized to migrate data from this device..
     *
     * @param array $authToken An authorization token received from device web
     * @returns bool true the authorization token is valid, false if not
     */
    private function isMigrationAuthorized(array $authToken): bool
    {
        if (!isset(
            $authToken['targetDeviceID'],
            $authToken['sourceDeviceID'],
            $authToken['expiration'],
            $authToken['nonce'],
            $authToken['signature']
        )) {
            $this->logger->error('MIG0034 Device migration authorization is missing data.', $authToken);
            return false;
        }
        $targetDeviceID = $authToken['targetDeviceID']; # The device migrating data to (and requesting the migration)
        $sourceDeviceID = $authToken['sourceDeviceID']; # The device migrating data from (this current device)
        $nonce = $authToken['nonce'];
        $expiration = $authToken['expiration'];
        $deviceIdValid = $sourceDeviceID === intval($this->deviceConfig->get(DeviceConfig::KEY_DEVICE_ID));
        $timeValid = $this->dateTimeService->getTime() <= $expiration;
        $nonceValid = !$this->nonceHandler->hasNonceBeenUsed($nonce);

        $msg_to_verify = "targetDeviceID=$targetDeviceID&sourceDeviceID=$sourceDeviceID&expiration=$expiration&nonce=$nonce";
        $decodedSignature = base64_decode($authToken['signature']);
        $deviceWebCert = $this->certificateClient->retrieveTrustedRootContents();
        $signatureValid = openssl_verify(
            $msg_to_verify,
            $decodedSignature,
            $deviceWebCert,
            OPENSSL_ALGO_SHA256
        ) === 1;

        if (!$deviceWebCert || !$signatureValid || !$deviceIdValid || !$timeValid || !$nonceValid) {
            $context = $authToken;
            $context['deviceWebCertRetrieved'] = $deviceWebCert !== false;
            $context['signatureValid'] = $signatureValid;
            $context['deviceIdValid'] = $deviceIdValid;
            $context['timeValid'] = $timeValid;
            $context['nonceValid'] = $nonceValid;
            $this->logger->error('MIG0032 Device migration authorization failed.', $context);
            return false;
        }
        $this->nonceHandler->markNonceAsUsed($nonce);
        $this->logger->info(
            'MIG0033 Device migration authorization is valid.',
            ['targetDeviceID' => $targetDeviceID]
        );
        return true;
    }
}
