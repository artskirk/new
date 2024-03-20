<?php

namespace Datto\Asset\Agent\Encryption;

use Datto\Asset\Agent\EncryptionService;
use Datto\Common\Utility\Crypto\Jwk;
use Datto\Common\Utility\Crypto\KeyWrap;
use Datto\Config\ShmConfig;
use Datto\Log\SanitizedException;
use Datto\Common\Utility\Filesystem;
use Datto\Utility\Security\SecretString;
use Exception;
use InvalidArgumentException;
use Throwable;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;

/**
 * Responsible for storing agent master keys in memory once a user supplies a passphrase that can decrypt them.
 * These master keys are encrypted with a unique key (per boot) and stored in /dev/shm.
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class KeyStashService implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    // Stores ram encrypted master keys that were accidentally deleted from disk but were still present in memory.
    public const INCONSISTENT_KEY_LOG = '/dev/shm/inconsistentKeyLog';

    // Stores a random seed that is used when calculating the ram key
    public const STASH_SEED_FILE = '/dev/shm/keyStash.seed';

    // Used when calculating the ram key. See getRamKey()
    public const PROGRAM_SOURCE_KEY = '12f87e12ead638d5b1e7854fa4754093b3634f2fd9ef29a88431a4e27dded45a';

    private ShmConfig $shmConfig;
    private Filesystem $filesystem;
    private KeyWrap $keyWrap;

    /**
     * @param ShmConfig $shmConfig
     * @param Filesystem $filesystem
     * @param KeyWrap $keyWrap
     */
    public function __construct(
        ShmConfig $shmConfig,
        Filesystem $filesystem,
        KeyWrap $keyWrap
    ) {
        $this->filesystem = $filesystem;
        $this->shmConfig = $shmConfig;
        $this->keyWrap = $keyWrap;
    }

    /**
     * Save the agent's master key to the key stash.
     *
     * @param string $keyName
     * @param SecretString $masterKey The agent's master key
     */
    public function stash(string $keyName, SecretString $masterKey): void
    {
        $this->logger->setAssetContext($keyName);

        try {
            $ramKey = $this->getRamKey();
            $ramEncryptedMasterKey = $this->keyWrap->wrap(new Jwk($masterKey->getSecret()), $ramKey);

            $keyStashRecord = new KeyStashRecord();
            if ($this->shmConfig->loadRecord($keyStashRecord)) {
                if (!$keyStashRecord->verifyRamKey($ramKey)) {
                    throw new Exception("Inconsistency exists between RAM key and stash session key");
                }
                if ($keyStashRecord->getVersion() === KeyStashRecord::ENCRYPTION_VER_AESCRYPT) {
                    $this->convertKeyStashRecord($keyStashRecord);
                }
            } else {
                $ramKeyHash = hash(EncryptionService::HASH_ALGORITHM, $ramKey);
                $keyStashRecord->setRamKeyHash($ramKeyHash);
                $keyStashRecord->setVersion(KeyStashRecord::ENCRYPTION_VER_JWE);
            }

            $keyStashRecord->setRamEncryptedMasterKey($keyName, $ramEncryptedMasterKey);

            $this->shmConfig->saveRecord($keyStashRecord);
            $this->filesystem->chmod(ShmConfig::BASE_SHM_PATH . '/' . $keyStashRecord->getKeyName(), 0600);

            $this->logger->debug("ENC1002 Agent unsealed");
        } catch (Throwable $e) {
            throw new SanitizedException($e, [$masterKey], false);
        }
    }

    /**
     * Remove an agent's master key from the key stash in /dev/shm.
     * A user will need to input their passphrase again to access the agent's master key.
     *
     * @param string $keyName The agent to unstash.
     */
    public function unstash(string $keyName): void
    {
        $keyStashRecord = new KeyStashRecord();
        if ($this->shmConfig->loadRecord($keyStashRecord)) {
            $keyStashRecord->removeRamEncryptedMasterKey($keyName);
            $this->shmConfig->saveRecord($keyStashRecord);
        }

        $this->logger->setAssetContext($keyName);
        $this->logger->debug("ENC1003 Agent sealed");
    }

    /**
     * Gets the master key of the agent from the key stash.
     *
     * @param string $keyName
     * @return string The master key
     */
    public function getMasterKey(string $keyName): string
    {
        $this->logger->setAssetContext($keyName);
        $ramKey = $this->getRamKey();

        $keyStashRecord = new KeyStashRecord();
        $this->shmConfig->loadRecord($keyStashRecord);

        if (!$keyStashRecord->hasRamEncryptedMasterKey($keyName)) {
            throw new SealedException(
                "No cached master key on hand for agent \"$keyName\". Please unseal this agent to continue."
            );
        }

        if (!$keyStashRecord->verifyRamKey($ramKey)) {
            throw new Exception("Inconsistency exists between RAM key and stash session key");
        }

        $ramEncryptedMasterKey = $keyStashRecord->getRamEncryptedMasterKey($keyName);
        if ($keyStashRecord->getVersion() === KeyStashRecord::ENCRYPTION_VER_AESCRYPT) {
            $aes = AESCrypt::singleton(LegacyEncryptionHelper::AES_KEY_SIZE, LegacyEncryptionHelper::AES_BLOCK_SIZE);
            $masterKey = $aes->decrypt($ramEncryptedMasterKey, $ramKey);
        } else {
            $masterKey = $this->keyWrap->unwrap($ramEncryptedMasterKey, $ramKey)->getKeyBytes();
        }

        return $masterKey;
    }

    /**
     * Returns whether the master key is stashed for this agent
     *
     * @param string $keyName
     * @return bool
     */
    public function hasMasterKey(string $keyName): bool
    {
        $keyStashRecord = new KeyStashRecord();
        $this->shmConfig->loadRecord($keyStashRecord);

        return $keyStashRecord->hasRamEncryptedMasterKey($keyName) && $keyStashRecord->verifyRamKey($this->getRamKey());
    }

    /**
     * Generates and saves a key stash seed if there isn't one already
     * This is used to make the ram key unique.
     */
    public function setupSeed(): void
    {
        if (!$this->filesystem->exists(self::STASH_SEED_FILE)) {
            $this->filesystem->filePutContents(self::STASH_SEED_FILE, random_bytes(64 * 1024));
            $this->filesystem->chmod(self::STASH_SEED_FILE, 0600);
        }

        if (!$this->filesystem->exists(self::STASH_SEED_FILE)) {
            throw new Exception("Internal error: failed to generate key stash seed file");
        }
    }

    /**
     * Stash the transient master key in /dev/shm/inconsistentKeyLog so that it can be recovered if necessary
     *
     * @param string $keyName
     */
    public function logEncryptedMasterKey(string $keyName): void
    {
        $keyStashRecord = new KeyStashRecord();
        $this->shmConfig->loadRecord($keyStashRecord);

        $ramEncryptedMasterKey = $keyStashRecord->getRamEncryptedMasterKey($keyName);

        $this->filesystem->filePutContents(
            self::INCONSISTENT_KEY_LOG,
            "$keyName:$ramEncryptedMasterKey\n",
            FILE_APPEND
        );
    }

    /**
     * Calculate the ram key to use when encrypting master keys to store in the stash.
     *
     * @return string
     */
    public function getRamKey(): string
    {
        // Including the PROGRAM_SOURCE_KEY in the hash means a bad actor would need to deobfuscate
        // this source code to be able to decrypt any stashed master keys
        $this->setupSeed();
        $seed = $this->filesystem->fileGetContents(self::STASH_SEED_FILE);
        $secretKey = $this->filesystem->fileGetContents("/datto/config/secretKey");
        if ($seed === false || $secretKey === false) {
            throw new Exception('RAM key generator data not found.');
        }
        return hash_hmac(
            EncryptionService::HASH_ALGORITHM,
            hash_hmac(EncryptionService::HASH_ALGORITHM, $seed, $secretKey),
            self::PROGRAM_SOURCE_KEY
        );
    }

    private function convertKeyStashRecord(KeyStashRecord $keyStashRecord): void
    {
        if ($keyStashRecord->getVersion() !== KeyStashRecord::ENCRYPTION_VER_AESCRYPT) {
            throw new InvalidArgumentException('KeyStashRecord conversion not needed.');
        }

        $ramKey = $this->getRamKey();
        foreach ($keyStashRecord->getRamEncryptedMasterKeys() as $keyName => $encryptedMasterKey) {
            $aes = AESCrypt::singleton(LegacyEncryptionHelper::AES_KEY_SIZE, LegacyEncryptionHelper::AES_BLOCK_SIZE);
            $masterKey = $aes->decrypt($encryptedMasterKey, $ramKey);
            $keyStashRecord->setRamEncryptedMasterKey(
                $keyName,
                $this->keyWrap->wrap(new Jwk($masterKey), $ramKey)
            );
        }
        $keyStashRecord->setVersion(KeyStashRecord::ENCRYPTION_VER_JWE);
    }
}
