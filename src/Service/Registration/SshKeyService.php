<?php

namespace Datto\Service\Registration;

use Datto\Cloud\JsonRpcClient;
use Datto\Cloud\CloudErrorException;
use Datto\Common\Utility\Filesystem;
use Datto\Util\RetryHandler;
use Datto\Utility\Ssh\SshKeygen;
use Datto\Log\DeviceLoggerInterface;

/**
 * Manages SSH keys for the device.
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class SshKeyService
{
    const SSH_PRIVATE_KEY_FILE = '/root/.ssh/id_rsa';
    const SSH_PUBLIC_KEY_FILE = '/root/.ssh/id_rsa.pub';
    const SSH_KEY_UPLOAD_RETRIES = 3;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var Filesystem  */
    private $filesystem;

    /** @var JsonRpcClient */
    private $client;

    /** @var SshKeygen */
    private $sshKeygen;

    /** @var RetryHandler */
    private $retryHandler;

    public function __construct(
        DeviceLoggerInterface $logger,
        Filesystem $filesystem,
        JsonRpcClient $client,
        SshKeygen $sshKeygen,
        RetryHandler $retryHandler
    ) {
        $this->logger = $logger;
        $this->filesystem = $filesystem;
        $this->client = $client;
        $this->sshKeygen = $sshKeygen;
        $this->retryHandler = $retryHandler;
    }

    /**
     * Auto generate new SSH key on boot if one doesn't already exist and the feature is supported.
     *
     * This is used on cloud devices since we don't want to wait until registration time to generate it.
     */
    public function autoGenerateSshKey()
    {
        $this->generateSshKeyIfNotExists();
        $this->sendKeyToWebserver();
    }

    /**
     * Generates a new SSH key if the current one is invalid or doesn't exist,
     * then sends the result to the webserver.
     *
     * @return bool true if needed to be generated, false if not.
     */
    public function generateSshKeyIfNotExists(): bool
    {
        if ($this->sshKeyExists()) {
            $this->logger->info('SSH0000 Valid SSH key already exists, no need to regenerate');

            return false;
        } else {
            $this->logger->info('SSH0001 SSH key is invalid or missing, generating a new one');
            $this->generateSshKey();

            return true;
        }
    }

    /**
     * Gets the SSH public key.
     *
     * @return bool|string SSH key or false on failure
     */
    public function getSshPublicKey()
    {
        return $this->filesystem->fileGetContents(static::SSH_PUBLIC_KEY_FILE);
    }

    /**
     * Uploads the current SSH key to the webserver.
     */
    public function sendKeyToWebserver()
    {
        $publicKey = $this->filesystem->fileGetContents(static::SSH_PUBLIC_KEY_FILE);

        $this->retryHandler->executeAllowRetry(function () use ($publicKey) {
            try {
                $this->client->queryWithId('v1/device/registration/updateSshKey', ['rsaKey' => $publicKey]);
            } catch (CloudErrorException $e) {
                $this->logger->warning('SSH0003 SSH key upload attempt failed', ['exception' => $e, 'errorObjectMessage' => $e->getErrorObject()['message']]);
                throw $e;
            } catch (\Exception $e) {
                $this->logger->warning('SSH0004 SSH key upload attempt failed', ['exception' => $e]);
                throw $e;
            }
        });

        $this->logger->info('SSH0005 Sent new SSH key to webserver');
    }

    /**
     * Checks if a SSH key exists on the device.
     *
     * @return bool
     */
    private function sshKeyExists()
    {
        return $this->filesystem->exists(static::SSH_PRIVATE_KEY_FILE) &&
            $this->filesystem->exists(static::SSH_PUBLIC_KEY_FILE);
    }

    /**
     * Generates a new RSA key pair.
     */
    private function generateSshKey()
    {
        $this->sshKeygen->generatePrivateKey(self::SSH_PRIVATE_KEY_FILE, SshKeygen::KEY_TYPE_RSA);

        $this->logger->info('SSH0002 Generated a new SSH key pair', ['sshPrivateKeyFile' => static::SSH_PRIVATE_KEY_FILE]);
    }
}
