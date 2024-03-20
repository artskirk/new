<?php

namespace Datto\Asset\Agent\Encryption;

use Datto\AppKernel;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\EncryptionService;
use Datto\Asset\AssetException;
use Datto\Log\SanitizedException;
use Datto\Resource\DateTimeService;
use Datto\Utility\Security\SecretString;
use Exception;
use Throwable;

/**
 * Responsible for handling temporary passwordless access to encrypted agents.
 * This only works when the agent is in a decrypted state. This is to allow tech
 * support to work with encrypted agents without knowing the partner's passphrase.
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class TempAccessService
{
    const TEMP_ACCESS_SEC = 6 * 60 * 60; // 6 hours

    /** @var AgentService */
    private $agentService;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var EncryptionService */
    private $encryptionService;

    /** @var KeyStashService */
    private $keyStashService;

    /**
     * @param AgentService|null $agentService
     * @param DateTimeService|null $dateTimeService
     * @param EncryptionService|null $encryptionService
     * @param KeyStashService|null $keyStashService
     */
    public function __construct(
        AgentService $agentService = null,
        DateTimeService $dateTimeService = null,
        EncryptionService $encryptionService = null,
        KeyStashService $keyStashService = null
    ) {
        $this->agentService = $agentService ?: new AgentService();
        $this->dateTimeService = $dateTimeService ?: new DateTimeService();
        $this->encryptionService = $encryptionService
            ?: AppKernel::getBootedInstance()->getContainer()->get(EncryptionService::class);
        $this->keyStashService = $keyStashService
            ?: AppKernel::getBootedInstance()->getContainer()->get(KeyStashService::class);
    }

    /**
     * Returns whether temporary passwordless access to the agent is enabled.
     *
     * @param string $keyName
     * @return bool
     */
    public function isCryptTempAccessEnabled(string $keyName): bool
    {
        $expireTime = $this->getCryptTempAccessTime($keyName);

        return $this->dateTimeService->getTime() < $expireTime && $this->keyStashService->hasMasterKey($keyName);
    }

    /**
     * Get the time that temporary passwordless access expires
     *
     * @param $keyName
     * @return int Time when temp access expires in seconds
     */
    public function getCryptTempAccessTime(string $keyName): int
    {
        $agent = $this->agentService->get($keyName);
        $tempAccessKey = $agent->getEncryption()->getTempAccessKey();

        if ($tempAccessKey === null) {
            return 0;
        }

        $tempAccessKey = json_decode($tempAccessKey, true);
        $time = $tempAccessKey['timestamp'] ?? 0;
        $signature = $tempAccessKey['signature'] ?? '';

        if ($signature !== $this->calculateSignature($keyName, $time)) {
            return 0;
        }

        return $time + self::TEMP_ACCESS_SEC;
    }

    /**
     * Enables temporary passwordless encrypted data access.
     *
     * The keyfile that is set contains a signature that is calculated from the keyName, timestamp and device
     * specific information to prevent changing the time or moving an existing keyfile to another agent to
     * gain access. An unsealed agent only allows support to access the live dataset, while crypt temp access
     * allows them to mount and decrypt older recovery points.
     *
     * @param string $keyName
     * @param SecretString $passphrase
     * @return int epoch time that temporary access expires
     */
    public function enableCryptTempAccess(string $keyName, SecretString $passphrase): int
    {
        try {
            $this->encryptionService->decryptAgentKey($keyName, $passphrase);

            $agent = $this->agentService->get($keyName);
            $time = $this->dateTimeService->getTime();
            $signature = $this->calculateSignature($keyName, $time);

            $agent->getEncryption()->setTempAccessKey(json_encode(['signature' => $signature, 'timestamp' => $time]));
            $this->agentService->save($agent);
            return $time + self::TEMP_ACCESS_SEC;
        } catch (Throwable $e) {
            throw new SanitizedException($e, [$passphrase], false);
        }
    }

    /**
     * Revoke temporary passwordless encrypted data access to an agent
     *
     * @param string $keyName
     * @param SecretString $passphrase
     */
    public function disableCryptTempAccess(string $keyName, SecretString $passphrase): void
    {
        try {
            $this->encryptionService->decryptAgentKey($keyName, $passphrase);

            $agent = $this->agentService->get($keyName);
            $encryptionSettings = $agent->getEncryption();
            $encryptionSettings->setTempAccessKey(null);
            $this->agentService->save($agent);
        } catch (Throwable $e) {
            throw new SanitizedException($e, [$passphrase], false);
        }
    }

    /**
     * Calculate the temp access signature.
     * This is used to prevent modification of existing temp access keyfiles or using ones from other agents.
     *
     * @param string $keyName
     * @param int $timestamp
     * @return string the signature
     */
    private function calculateSignature(string $keyName, int $timestamp): string
    {
        $ramKey = $this->keyStashService->getRamKey();
        return hash_hmac(EncryptionService::HASH_ALGORITHM, $keyName . $timestamp, $ramKey);
    }
}
