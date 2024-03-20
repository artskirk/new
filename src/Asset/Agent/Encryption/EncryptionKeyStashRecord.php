<?php

namespace Datto\Asset\Agent\Encryption;

use Datto\Asset\Agent\EncryptionService;
use Datto\Config\JsonConfigRecord;

/**
 * This represents the /datto/config/keys/<agent>.encryptionKeyStash file
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class EncryptionKeyStashRecord extends JsonConfigRecord
{
    public const USER_KEYS_JWE_KEY = 'user_keys_jwe';

    /** @var UserKey[] */
    private array $userKeys;

    /** @var string[] */
    private array $userKeysJwe;

    private ?string $masterKeyHash;
    private ?string $masterKeyHashAlgorithm;

    /**
     * @param string|null $masterKeyHash
     * @param string|null $masterKeyHashAlgorithm
     * @param UserKey[] $userKeys
     * @param string[] $userKeysJwe
     */
    public function __construct(
        string $masterKeyHash = null,
        string $masterKeyHashAlgorithm = null,
        array $userKeys = [],
        array $userKeysJwe = []
    ) {
        $this->masterKeyHash = $masterKeyHash;
        $this->masterKeyHashAlgorithm = $masterKeyHashAlgorithm;
        $this->userKeys = $userKeys;
        $this->userKeysJwe = $userKeysJwe;
    }

    /**
     * Check that the supplied master key matches the master key used to encrypt the agent
     *
     * @param string $masterKey
     * @return bool True if valid
     */
    public function verifyMasterKey(string $masterKey): bool
    {
        if (isset($this->masterKeyHashAlgorithm)) {
            $hash = hash($this->masterKeyHashAlgorithm, $masterKey);
            return $hash === $this->masterKeyHash;
        }
        return false;
    }

    /**
     * @return string|null
     */
    public function getMasterKeyHash(): ?string
    {
        return $this->masterKeyHash;
    }

    /**
     * @return string|null
     */
    public function getMasterKeyHashAlgorithm(): ?string
    {
        return $this->masterKeyHashAlgorithm;
    }

    /**
     * @return UserKey[]
     */
    public function getUserKeys(): array
    {
        return $this->userKeys;
    }

    /**
     * Add a new user key.
     * This can later be decrypted with the user's passphrase to get the agent master key.
     *
     * @param UserKey $userKey
     */
    public function addUserKey(UserKey $userKey): void
    {
        $this->userKeys[] = $userKey;
    }

    /**
     * Removes a user key.
     *
     * @param int $index The index of the key to remove
     */
    public function removeUserKey(int $index): void
    {
        unset($this->userKeys[$index]);
        $this->userKeys = array_values($this->userKeys);
    }

    /**
     * @return string[]
     */
    public function getUserKeysJwe(): array
    {
        return $this->userKeysJwe;
    }

    /**
     * Add a new JWE user key.
     * This can later be decrypted with the user's passphrase to get the agent master key.
     *
     * @param string $userKeyJwe
     */
    public function addUserKeyJwe(string $userKeyJwe): void
    {
        $this->userKeysJwe[] = $userKeyJwe;
    }

    /**
     * Removes a JWE user key.
     *
     * @param string $jwe The JWE to remove
     * @return bool True if key is found
     */
    public function removeUserKeyJwe(string $jwe): bool
    {
        if (in_array($jwe, $this->userKeysJwe)) {
            unset($this->userKeysJwe[array_search($jwe, $this->userKeysJwe)]);
            $this->userKeysJwe = array_values($this->userKeysJwe);
            return true;
        }
        return false;
    }

    /**
     * @return string name of key file that this config record will be stored to
     */
    public function getKeyName(): string
    {
        return 'encryptionKeyStash';
    }

    public function jsonSerialize(): array
    {
        $userKeys = [];
        foreach ($this->userKeys as $userKey) {
            $userKeys[] = [
                'algo' => $userKey->getAlgorithm(),
                'salt' => bin2hex($userKey->getSalt()),
                'iv' => bin2hex($userKey->getIv()),
                'iterations' => $userKey->getIterations(),
                'hash' => $userKey->getHash(),
                'master_key' => $userKey->getEncryptedMasterKey()
            ];
        }

        return [
            'mkey_hash' => $this->masterKeyHash,
            'mkey_hash_alg' => $this->masterKeyHashAlgorithm,
            'user_keys' => $userKeys,
            self::USER_KEYS_JWE_KEY => $this->userKeysJwe
        ];
    }

    /**
     * @inheritdoc
     */
    protected function load(array $vals): void
    {
        $this->masterKeyHash = $vals['mkey_hash'];
        $this->masterKeyHashAlgorithm = $vals['mkey_hash_alg'] ?? EncryptionService::HASH_ALGORITHM;
        $this->userKeys = [];
        foreach ($vals['user_keys'] as $userKey) {
            $this->userKeys[] = UserKey::createFromArray($userKey);
        }
        $this->userKeysJwe = [];
        if (array_key_exists(self::USER_KEYS_JWE_KEY, $vals)) {
            $this->userKeysJwe = $vals[self::USER_KEYS_JWE_KEY];
        }
    }

    /**
     * Create EncryptionKeyStashRecord instance from array (does not include user keys collection).
     * (Standard serialization used to send to/from cloud)
     *
     * @param array $data
     * @param bool $includeUserKeys if true, create UserKey array from $data['user_keys'] and
     *                              compact JWE string array from $data['user_keys_jwe'].
     * @return EncryptionKeyStashRecord
     */
    public static function createFromArray(array $data, bool $includeUserKeys = false): EncryptionKeyStashRecord
    {
        if ($includeUserKeys) {
            $userKeys = [];
            foreach ($data['user_keys'] as $userKey) {
                $userKeys[] = UserKey::createFromArray($userKey);
            }
            $userKeysJwe = [];
            if (array_key_exists(self::USER_KEYS_JWE_KEY, $data)) {
                $userKeysJwe = $data[self::USER_KEYS_JWE_KEY];
            }
        }

        return new EncryptionKeyStashRecord(
            $data['mkey_hash'],
            $data['mkey_hash_alg'],
            $userKeys ?? [],
            $userKeysJwe ?? []
        );
    }
}
