<?php

namespace Datto\Asset\Agent\Encryption;

use Datto\Asset\Agent\EncryptionService;
use Datto\Utility\Security\SecretString;
use Exception;

/**
 * Abstracts the AESCrypt specific code from the EncryptionService class.
 */
class LegacyEncryptionHelper
{
    public const AES_KEY_SIZE = 256;
    public const AES_BLOCK_SIZE = 128;

    /**
     * Encrypts the agent master key with the passphrase.
     *
     * @param SecretString $masterKey The master key to be encrypted with the passphrase
     * @param SecretString $passphrase
     * @param int|null $iterations for unit testing
     * @param string|null $salt for unit testing
     * @param string|null $iv for unit testing
     * @return UserKey The parameters that can be used to decrypt the master key if supplied with the passphrase
     */
    public function createUserKey(
        SecretString $masterKey,
        SecretString $passphrase,
        int $iterations = null,
        string $salt = null,
        string $iv = null
    ): UserKey {
        $iterations = $iterations ?: random_int(131072, 524288);
        $salt = $salt ?: random_bytes(32);
        $iv = $iv ?: random_bytes(16);

        $key = $this->pbkdf2(EncryptionService::HASH_ALGORITHM, $passphrase, $salt, $iterations);
        $keyHash = hash(EncryptionService::HASH_ALGORITHM, $key);

        // encrypt master key with user key
        $aes = AESCrypt::singleton(self::AES_KEY_SIZE, self::AES_BLOCK_SIZE);
        $encryptedMasterKey = $aes->encrypt_cbc($masterKey->getSecret(), $key, $iv);

        return new UserKey(
            EncryptionService::HASH_ALGORITHM,
            $salt,
            $iv,
            $iterations,
            $keyHash,
            $encryptedMasterKey
        );
    }

    /**
     * Extract the master key from the given user key using the given passphrase.
     * Returns true with $masterKey set if the passphrase successfully decrypts
     * the master key, otherwise false. Throws an exception if the user key is
     * successfully decrypted but the hash of the resulting master key doesn't
     * match the one stored in the stash record.
     */
    public function getMasterKey(
        UserKey $userKey,
        SecretString $passphrase,
        EncryptionKeyStashRecord $encryptionKeyStashRecord,
        string &$masterKey
    ): bool {
        $masterKey = "";
        $result = false;

        // Derive the key used to encrypt the agent master key from the user's passphrase
        $key = $this->pbkdf2($userKey->getAlgorithm(), $passphrase, $userKey->getSalt(), $userKey->getIterations());

        if ($userKey->verifyKey($key)) {
            $aes = AESCrypt::singleton(self::AES_KEY_SIZE, self::AES_BLOCK_SIZE);
            $masterKey = $aes->decrypt_cbc($userKey->getEncryptedMasterKey(), $key, $userKey->getIv());

            // Handle bug in rijndael.php (it rtrims all null bytes on decryption at line 1640)
            while (strlen($masterKey) < 64 && $encryptionKeyStashRecord->verifyMasterKey($masterKey) === false) {
                $masterKey .= chr(0);
            }

            if ($encryptionKeyStashRecord->verifyMasterKey($masterKey) === false) {
                throw new Exception("Found valid user key, but hash of master key as decrypted by" .
                                    " user key did not match known hash of master key");
            }

            $result = true;
        }

        return $result;
    }

    /**
     * Derive the key used to encrypt the agent master key from the user's passphrase.
     */
    public function getMasterKeyEncryptionKey(UserKey $userKey, SecretString $passphrase): string
    {
        return $this->pbkdf2(
            $userKey->getAlgorithm(),
            $passphrase,
            $userKey->getSalt(),
            $userKey->getIterations()
        );
    }

    /**
     * PBKDF2 key derivation function as defined by RSA's PKCS #5: https://www.ietf.org/rfc/rfc2898.txt
     *
     * @param string $algorithm - The hash algorithm to use. Recommended: SHA256
     * @param SecretString $password - The password.
     * @param string $salt - A salt that is unique to the password.
     * @param int $count - Iteration count. Higher is better, but slower. Recommended: At least 1000.
     * @param int $keyLength - The length of the derived key in bytes.
     * @param bool $rawOutput - If true, the key is returned in raw binary format. Hex encoded otherwise.
     * @return string A $keyLength-byte key derived from the password and salt.
     *
     * This implementation of PBKDF2 was originally created by https://defuse.ca/php-pbkdf2.htm
     *
     * Defuse's implementation differs slightly from php's built in hash_pbkdf2 function. It throws
     * exceptions instead of errors and it outputs a string twice as long when $rawOutput is false.
     *
     * When calling hash_pbkdf2() with $raw_output = false, it converts the output to a hex string then
     * truncates it to the $keyLength. Since each byte takes up two characters in the string, it ends up
     * throwing away half the information. 32 bytes -> hex encode to 64 bytes -> truncate to keyLength of 32 -> 32 bytes
     *
     * $this->pbkdf2() always returns the full key because it does the truncation before encoding to hex.
     * 32 bytes -> truncate to keyLength of 32 -> hex encode -> 64 bytes
     *
     * We cannot use php's builtin hash_pbkdf2 on its own if we want to be able to derive the keys for existing agents
     */
    private function pbkdf2(
        string $algorithm,
        SecretString $password,
        string $salt,
        int $count,
        int $keyLength = 32,
        bool $rawOutput = false
    ): string {
        $algorithm = strtolower($algorithm);
        if (!in_array($algorithm, hash_algos(), true)) {
            throw new Exception('PBKDF2 ERROR: Invalid hash algorithm.');
        }
        if ($count <= 0 || $keyLength <= 0) {
            throw new Exception('PBKDF2 ERROR: Invalid parameters.');
        }

        // Double the keyLength when encoding to hex so hash_pbkdf2 doesn't throw away half the information
        if (!$rawOutput) {
            $keyLength = $keyLength * 2;
        }

        return hash_pbkdf2($algorithm, $password->getSecret(), $salt, $count, $keyLength, $rawOutput);
    }
}
