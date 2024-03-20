<?php

namespace Datto\Asset\Agent\Encryption;

use Datto\Asset\Agent\EncryptionService;
use Datto\Config\JsonConfigRecord;

/**
 * This represents the /dev/shm/keyStash file.
 * It stores master keys once they've been decrypted by a user's passphrase, so we can use them for backups/restores
 * without constantly asking users for their passphrase. These keys are stored encrypted with the ram key.
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class KeyStashRecord extends JsonConfigRecord
{
    public const ENCRYPTION_VER_AESCRYPT = 1;
    public const ENCRYPTION_VER_JWE = 2;

    private string $ramKeyHash;
    private int $version = KeyStashRecord::ENCRYPTION_VER_AESCRYPT;

    /** @var string[] */
    private array $ramEncryptedMasterKeys;

    /**
     * Check that the supplied ram key matches the one used to encrypt the data stored in the key stash
     *
     * @param string $ramKey
     * @return bool True if valid
     */
    public function verifyRamKey(string $ramKey): bool
    {
        return isset($this->ramKeyHash) && hash(EncryptionService::HASH_ALGORITHM, $ramKey) === $this->ramKeyHash;
    }

    /**
     * @return int $version 1 for AESCrypt encrypted, 2 for JWE
     */
    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * @param int $version 1 for AESCrypt encrypted, 2 for JWE
     */
    public function setVersion(int $version): void
    {
        $this->version = $version;
    }

    /**
     * @param string $ramKeyHash A ripemd256 hash of the ram key
     */
    public function setRamKeyHash(string $ramKeyHash): void
    {
        $this->ramKeyHash = $ramKeyHash;
    }

    /**
     * Return whether the stash contains the master key for agent $keyName
     *
     * @param string $keyName
     * @return bool
     */
    public function hasRamEncryptedMasterKey(string $keyName): bool
    {
        return isset($this->ramEncryptedMasterKeys[$keyName]);
    }

    /**
     * Get the encrypted master key for agent $keyName
     *
     * @param string $keyName
     * @return string
     */
    public function getRamEncryptedMasterKey(string $keyName): string
    {
        return $this->ramEncryptedMasterKeys[$keyName];
    }

    /**
     * @param string $keyName
     * @param string $ramEncryptedMasterKey
     */
    public function setRamEncryptedMasterKey(string $keyName, string $ramEncryptedMasterKey): void
    {
        $this->ramEncryptedMasterKeys[$keyName] = $ramEncryptedMasterKey;
    }

    /**
     * Get the encrypted master key array
     */
    public function getRamEncryptedMasterKeys(): array
    {
        return $this->ramEncryptedMasterKeys;
    }

    /**
     * @param string $keyName
     */
    public function removeRamEncryptedMasterKey(string $keyName): void
    {
        unset($this->ramEncryptedMasterKeys[$keyName]);
    }

    /**
     * @return string name of key file that this config record will be stored to
     */
    public function getKeyName(): string
    {
        return 'keyStash';
    }

    public function jsonSerialize(): array
    {
        $output = $this->ramEncryptedMasterKeys;
        $output['_stashSig'] = $this->ramKeyHash;
        $output['_version'] = $this->version;
        return $output;
    }

    /**
     * @inheritdoc
     */
    protected function load(array $vals): void
    {
        $this->ramKeyHash = $vals['_stashSig'] ?? null;
        $this->version = $vals['_version'] ?? 1;
        unset($vals['_stashSig']);
        unset($vals['_version']);
        $this->ramEncryptedMasterKeys = $vals;
    }
}
