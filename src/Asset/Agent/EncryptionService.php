<?php

namespace Datto\Asset\Agent;

use Datto\Alert\AlertManager;
use Datto\Asset\Agent\Encryption\EncryptionKeyStashRecord;
use Datto\Asset\Agent\Encryption\InvalidPassphraseException;
use Datto\Asset\Agent\Encryption\KeyStashService;
use Datto\Asset\Agent\Encryption\PassphraseNotFoundException;
use Datto\Asset\Agent\Encryption\SealedException;
use Datto\Asset\Agent\Encryption\LegacyEncryptionHelper;
use Datto\Asset\Agent\Encryption\JweEncryptionHelper;
use Datto\Config\AgentConfig;
use Datto\Config\AgentConfigFactory;
use Datto\Log\SanitizedException;
use Datto\Utility\Security\SecretString;
use Exception;
use Throwable;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;

/**
 * Responsible for managing the encryption keys for agent data
 */
class EncryptionService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const HASH_ALGORITHM = "ripemd256";

    private AgentConfigFactory $agentConfigFactory;
    private KeyStashService $keyStashService;
    private AlertManager $alertManager;
    private LegacyEncryptionHelper $legacyEncryptionHelper;
    private JweEncryptionHelper $jweEncryptionHelper;

    public function __construct(
        AgentConfigFactory $agentConfigFactory,
        KeyStashService $keyStashService,
        AlertManager $alertManager,
        JweEncryptionHelper $jweEncryptionHelper,
        LegacyEncryptionHelper $legacyEncryptionHelper
    ) {
        $this->agentConfigFactory = $agentConfigFactory;
        $this->keyStashService = $keyStashService;
        $this->alertManager = $alertManager;
        $this->jweEncryptionHelper = $jweEncryptionHelper;
        $this->legacyEncryptionHelper = $legacyEncryptionHelper;
    }

    /**
     * Return whether the agent referred to by keyName is encrypted
     *
     * @param string $keyName
     * @return bool true if encrypted
     */
    public function isEncrypted(string $keyName): bool
    {
        $agentConfig = $this->getAgent($keyName);
        return $agentConfig->isEncrypted();
    }

    /**
     * Returns whether or not the agent's master key is loaded (present in the keyStash)
     *
     * @param string $keyName
     * @return bool
     */
    public function isAgentMasterKeyLoaded(string $keyName): bool
    {
        try {
            $this->getAgentCryptKey($keyName);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * @param string $agentKey
     *
     * @return bool true if agent is encrypted but no master key is stashed
     */
    public function isAgentSealed(string $agentKey): bool
    {
        $isEncrypted = $this->isEncrypted($agentKey);
        $isMasterKeyLoaded = $this->isAgentMasterKeyLoaded($agentKey);
        return $isEncrypted && !$isMasterKeyLoaded;
    }

    /**
     * Enable encryption for an agent by generating the needed encryption key
     *
     * This randomly generates a master key which is used to encrypt the agent's backup data.
     * We store the master key encrypted with the user's passphrase, so only they can decrypt it.
     * We also store a copy of the master key encrypted with a device specific key in memory. This allows us
     * to take backups without the user having to enter their passphrase for each one. On boot the user will be
     * required to enter their passphrase to put the master key back in memory.
     *
     * @param string $keyName The agent to generate the encryption key for
     * @param SecretString $passphrase The passphrase to use to decrypt the agent's master key
     * @param string|null $masterKey Used for unittest injection only
     */
    public function encryptAgent(string $keyName, SecretString $passphrase, string $masterKey = null): void
    {
        try {
            $agentConfig = $this->getAgent($keyName);
            $this->logger->setAssetContext($keyName);

            // don't generate keys for agents that are already encrypted or already have encryption keys
            if ($this->isEncrypted($keyName) || $agentConfig->has('encryptionKeyStash')) {
                $this->logger->error(
                    'ENC0010 Refusing to generate encryption keys. Agent is already encrypted.',
                    ['keyName' => $keyName]
                );
                return;
            }

            $agentConfig->touch('encryption'); // signal that this agent is encrypted

            $this->logger->debug('ENC0001 Generating storage encryption key', ['keyName' => $keyName]);

            $masterKey = new SecretString($masterKey ?? random_bytes(64));
            $masterKeyHash = hash(self::HASH_ALGORITHM, $masterKey->getSecret());

            $userKey = $this->legacyEncryptionHelper->createUserKey($masterKey, $passphrase);
            $userKeyJwe = $this->jweEncryptionHelper->createUserKey($masterKey, $passphrase);

            $agentConfig->saveRecord(new EncryptionKeyStashRecord(
                $masterKeyHash,
                self::HASH_ALGORITHM,
                [$userKey],
                [$userKeyJwe]
            ));

            $this->keyStashService->stash($keyName, $masterKey);

            $this->logger->debug('ENC1001 Master key was generated for agent', ['keyName' => $keyName]);
            $this->alertManager->clearAlert($keyName, 'ENC1001');
        } catch (Throwable $e) {
            throw new SanitizedException($e, [$passphrase, $masterKey ?? ""], false);
        }
    }

    /**
     * Decrypt an agent's master key. Throws an exception if decryption fails.
     *
     * @param string $keyName
     * @param SecretString $passphrase
     * @param bool $auditable true if failed decryption should be logged
     */
    public function decryptAgentKey(string $keyName, SecretString $passphrase, bool $auditable = true): void
    {
        try {
            $agentConfig = $this->getAgent($keyName);
            $this->logger->setAssetContext($keyName);
            $encryptionKeyStashRecord = new EncryptionKeyStashRecord();
            if ($agentConfig->loadRecord($encryptionKeyStashRecord) === false) {
                throw new Exception("No key stash for $keyName");
            }

            $masterKey = "";
            if ($this->findMasterKeyFromJweUserKey($encryptionKeyStashRecord, $passphrase, $keyName, $masterKey)) {
                $this->stashKeyAndLogSuccessfulPassphraseEntry($keyName, new SecretString($masterKey));
                return;
            }

            if ($this->findMasterKeyFromLegacyUserKey($encryptionKeyStashRecord, $passphrase, $keyName, $masterKey)) {
                $this->migrateLegacyUserKey(
                    new SecretString($masterKey),
                    $passphrase,
                    $encryptionKeyStashRecord,
                    $agentConfig
                );
                $this->stashKeyAndLogSuccessfulPassphraseEntry($keyName, new SecretString($masterKey));
                return;
            }
        } catch (Throwable $e) {
            throw new SanitizedException($e, [$passphrase, $masterKey ?? ""], false);
        }

        if ($auditable) {
            $this->logger->error('ENC1005 Unsuccessful passphrase entry attempt', ['keyName' => $keyName]);
            $this->alertManager->clearAlert($keyName, 'ENC1005');
        }
        throw new InvalidPassphraseException();
    }

    /**
     * Get the master encryption key for an agent from the key stash.
     *
     * @param string $keyName
     * @return string binary master key
     */
    public function getAgentCryptKey(string $keyName): string
    {
        try {
            $this->logger->setAssetContext($keyName);
            $agentConfig = $this->getAgent($keyName);
            $agentName = $agentConfig->getFullyQualifiedDomainName();
            if (!$this->isEncrypted($keyName)) {
                throw new Exception("Agent \"$agentName\" is not encrypted, cannot fetch key");
            }

            try {
                $masterKey = $this->keyStashService->getMasterKey($keyName);
            } catch (SealedException $exception) {
                $message =
                    "No cached master key on hand for agent \"$agentName\". Please unseal this agent to continue.";
                throw new SealedException($message);
            }

            $encryptionKeyStashRecord = new EncryptionKeyStashRecord();
            if ($agentConfig->loadRecord($encryptionKeyStashRecord) === false) {
                throw new Exception("No key stash for $keyName");
            }

            // Handle bug in rijndael.php (it rtrims all null bytes on decryption at line 1640)
            while (strlen($masterKey) < 64 && $encryptionKeyStashRecord->verifyMasterKey($masterKey) === false) {
                $masterKey .= chr(0);
            }

            if (!$encryptionKeyStashRecord->verifyMasterKey($masterKey)) {
                $this->logger->emergency("ENC1016 " .
                    "Agent has the wrong master key loaded into memory! If " .
                    "the agent was recently renamed and chains shuffled before the " .
                    "fix for CP-8707 was pushed out, and recovery points exist for " .
                    "this agent, the master key in memory will be lost forever the " .
                    "next time the device is rebooted unless it is backed up!", ['keyName' => $keyName]);

                // Stash the transient master key in /dev/shm/inconsistentKeyLog so
                // that it can be recovered if necessary
                $this->keyStashService->logEncryptedMasterKey($keyName);

                throw new Exception(
                    "Inconsistency exists between master key cached in memory and the " .
                    "master key represented in the stash file on disk. To continue, " .
                    "seal and unseal the agent. WARNING! If you do this, the master " .
                    "key in memory will be irrecoverably wiped."
                );
            }

            return $masterKey;
        } catch (Throwable $e) {
            throw new SanitizedException($e, [$masterKey ?? ""], false);
        }
    }

    /**
     * Add a passphrase to an agent. Can add multiple passphrases.
     *
     * @param string $keyName
     * @param SecretString $passphrase The passphrase to add
     * @param int|null $iterations Used for unittest injection only
     * @param string|null $salt Used for unittest injection only
     * @param string|null $iv Used for unittest injection only
     */
    public function addAgentPassphrase(
        string $keyName,
        SecretString $passphrase,
        int $iterations = null,
        string $salt = null,
        string $iv = null
    ): void {
        try {
            $agentConfig = $this->getAgent($keyName);
            $masterKey = new SecretString($this->getAgentCryptKey($keyName));

            $encryptionKeyStashRecord = new EncryptionKeyStashRecord();
            $agentConfig->loadRecord($encryptionKeyStashRecord);

            $userKey = $this->legacyEncryptionHelper->createUserKey(
                $masterKey,
                $passphrase,
                $iterations,
                $salt,
                $iv
            );
            $encryptionKeyStashRecord->addUserKey($userKey);

            $userKeyJwe = $this->jweEncryptionHelper->createUserKey($masterKey, $passphrase);
            $encryptionKeyStashRecord->addUserKeyJwe($userKeyJwe);

            $agentConfig->saveRecord($encryptionKeyStashRecord);

            $this->logger->setAssetContext($keyName);
            $this->logger->debug('ENC1004 New passphrase added to keystore', ['keyName' => $keyName]);
        } catch (Throwable $e) {
            throw new SanitizedException($e, [$passphrase, $masterKey ?? ""], false);
        }
    }

    /**
     * Remove a passphrase for an agent.
     *
     * @param string $keyName The agent to remove the passphrase from.
     * @param SecretString $passphrase The passphrase to remove
     */
    public function removeAgentPassphrase(string $keyName, SecretString $passphrase): void
    {
        try {
            $agentConfig = $this->getAgent($keyName);
            $this->logger->setAssetContext($keyName);
            $writeStashRecord = false;

            $encryptionKeyStashRecord = new EncryptionKeyStashRecord();
            if ($agentConfig->loadRecord($encryptionKeyStashRecord) === false) {
                throw new Exception("No key stash for $keyName");
            }

            // Keys encrypted with JWE can only be added when the user provides a passphrase. Therefore,
            // we still consider the legacy AESCrypt encrypted key list as the only complete list.
            if (count($encryptionKeyStashRecord->getUserKeys()) < 2) {
                throw new Exception("Agent \"$keyName\" only has one key slot. Refusing to destroy the last one.");
            }

            $masterKey = "";
            foreach ($encryptionKeyStashRecord->getUserKeysJwe() as $userKeyJwe) {
                if ($this->jweEncryptionHelper->getMasterKey(
                    $userKeyJwe,
                    $passphrase,
                    $encryptionKeyStashRecord,
                    $masterKey
                )) {
                    $encryptionKeyStashRecord->removeUserKeyJwe($userKeyJwe);
                    $writeStashRecord = true;
                    break;
                }
            }

            foreach ($encryptionKeyStashRecord->getUserKeys() as $i => $userKey) {
                // Derive the key used to encrypt the agent master key from the user's passphrase
                $key = $this->legacyEncryptionHelper->getMasterKeyEncryptionKey($userKey, $passphrase);

                if ($userKey->verifyKey($key)) {
                    $encryptionKeyStashRecord->removeUserKey($i);
                    $writeStashRecord = true;
                    break;
                }
            }
        } catch (Throwable $e) {
            throw new SanitizedException($e, [$passphrase, $key ?? null], false);
        }

        if ($writeStashRecord) {
            $agentConfig->saveRecord($encryptionKeyStashRecord);
            $this->logger->debug('ENC1009 Passphrase removed by user entry', ['keyName' => $keyName]);
            $this->alertManager->clearAlert($keyName, "ENC1009");
        } else {
            $this->logger->error(
                'ENC1008 Unsuccessful passphrase entry attempt during attempted removal',
                ['keyName' => $keyName]
            );
            $this->alertManager->clearAlert($keyName, "ENC1008");
            throw new PassphraseNotFoundException();
        }
    }

    /**
     * @param string $keyName
     * @return AgentConfig
     */
    private function getAgent(string $keyName): AgentConfig
    {
        return $this->agentConfigFactory->create($keyName);
    }

    /**
     * @param EncryptionKeyStashRecord $encryptionKeyStashRecord Loaded from disk by caller.
     * @param SecretString $passphrase Passphrase to use in verifying we have the correct UserKey.
     * @param string $keyName For logging.
     * @param string & $masterKey receives the master key if found.
     * @return bool True if masterKey found.
     */
    private function findMasterKeyFromJweUserKey(
        EncryptionKeyStashRecord $encryptionKeyStashRecord,
        SecretString $passphrase,
        string $keyName,
        string &$masterKey
    ): bool {
        $masterKey = "";
        foreach ($encryptionKeyStashRecord->getUserKeysJwe() as $userKeyJwe) {
            try {
                if ($this->jweEncryptionHelper->getMasterKey(
                    $userKeyJwe,
                    $passphrase,
                    $encryptionKeyStashRecord,
                    $masterKey
                )) {
                    return true;
                }
            } catch (Exception $e) {
                $this->logger->error(
                    'ENC1017 Master key decrypt internal error.',
                    ['keyName' => $keyName]
                );
                throw $e;
            }
        }
        return false;
    }

    /**
     * @param EncryptionKeyStashRecord $encryptionKeyStashRecord Loaded from disk by caller.
     * @param SecretString $passphrase Passphrase to use in verifying we have the correct UserKey.
     * @param string $keyName For logging.
     * @param string & $masterKey receives the master key if found.
     * @return bool True if masterKey found.
     */
    private function findMasterKeyFromLegacyUserKey(
        EncryptionKeyStashRecord $encryptionKeyStashRecord,
        SecretString $passphrase,
        string $keyName,
        string &$masterKey
    ):bool {
        $masterKey = "";
        foreach ($encryptionKeyStashRecord->getUserKeys() as $i => $userKey) {
            try {
                if ($this->legacyEncryptionHelper->getMasterKey(
                    $userKey,
                    $passphrase,
                    $encryptionKeyStashRecord,
                    $masterKey
                )) {
                    return true;
                }
            } catch (Exception $e) {
                $this->logger->error(
                    'ENC1006 Master key decrypt internal error (hash mismatch)',
                    ['keyName' => $keyName, 'keyIndex' => $i]
                );
                throw $e;
            }
        }
        return false;
    }

    /** Migrate a legacy user key to JWE */
    private function migrateLegacyUserKey(
        SecretString $masterKey,
        SecretString $passphrase,
        EncryptionKeyStashRecord $encryptionKeyStashRecord,
        AgentConfig $agentConfig
    ): void {
        try {
            $this->logger->debug('ENC1018 Adding JWE keys not found in encryption key stash record.');
            $userKeyJwe = $this->jweEncryptionHelper->createUserKey($masterKey, $passphrase);
            $encryptionKeyStashRecord->addUserKeyJwe($userKeyJwe);
            $agentConfig->saveRecord($encryptionKeyStashRecord);
        } catch (Exception $e) {
            $this->logger->warning(
                'ENC1019 Unable to copy user key to JWE.',
                [
                    'agentName' => $agentConfig->getFullyQualifiedDomainName(),
                    'exception' => $e
                ]
            );
        }
    }

    private function stashKeyAndLogSuccessfulPassphraseEntry(string $keyName, SecretString $masterKey): void
    {
        $this->keyStashService->stash($keyName, $masterKey);
        $this->logger->debug('ENC1007 Successful passphrase entry attempt', ['keyName' => $keyName]);
        $this->alertManager->clearAlert($keyName, 'ENC1007');
    }
}
