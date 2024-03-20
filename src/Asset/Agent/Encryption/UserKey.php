<?php

namespace Datto\Asset\Agent\Encryption;

/**
 * Holds the data necessary for a user to decrypt the agent master key with their passphrase.
 *
 * The user's passphrase is run through pbkdf2 with parameters from this class, $algorithm, $salt,
 * and $iterations to derive the user key. The agent master key is encrypted with this user key and
 * stored in this class as $encryptedMasterKey. With their passphrase, the user can decrypt the master
 * key to access the agent data.
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class UserKey
{
    /** @var string */
    private $algorithm;

    /** @var string */
    private $salt;

    /** @var string */
    private $iv;

    /** @var int */
    private $iterations;

    /** @var string */
    private $hash;

    /** @var string */
    private $encryptedMasterKey;

    /**
     * @param string $algorithm
     * @param string $salt
     * @param string $iv
     * @param int $iterations
     * @param string $hash
     * @param string $encryptedMasterKey
     */
    public function __construct(
        string $algorithm,
        string $salt,
        string $iv,
        int $iterations,
        string $hash,
        string $encryptedMasterKey
    ) {
        $this->algorithm = $algorithm;
        $this->salt = $salt;
        $this->iv = $iv;
        $this->iterations = $iterations;
        $this->hash = $hash;
        $this->encryptedMasterKey = $encryptedMasterKey;
    }

    /**
     * Check that the supplied user key matches the one used to encrypt the master key
     *
     * @param string $userKey
     * @return bool True if valid
     */
    public function verifyKey(string $userKey): bool
    {
        return hash($this->algorithm, $userKey) === $this->hash;
    }

    /**
     * @return string The hash algorithm to use when generating the user key from the passphrase with pbkdf2
     */
    public function getAlgorithm(): string
    {
        return $this->algorithm;
    }

    /**
     * @return string
     */
    public function getSalt(): string
    {
        return $this->salt;
    }

    /**
     * @return string The initialization vector for encrypting the master key with the user key
     */
    public function getIv(): string
    {
        return $this->iv;
    }

    /**
     * @return int Number of iterations of pbkdf2
     */
    public function getIterations(): int
    {
        return $this->iterations;
    }

    /**
     * @return string The hash of the user key
     */
    public function getHash(): string
    {
        return $this->hash;
    }

    /**
     * @return string The master key encrypted with the user key
     */
    public function getEncryptedMasterKey(): string
    {
        return $this->encryptedMasterKey;
    }

    /**
     * @param string $encryptedMasterKey The master key encrypted with the user key
     */
    public function setEncryptedMasterKey(string $encryptedMasterKey): void
    {
        $this->encryptedMasterKey = $encryptedMasterKey;
    }

    /**
     * Create UserKey instance from array.
     * (Standard serialization used to send to/from cloud)
     *
     * @param array $data
     * @return UserKey
     */
    public static function createFromArray(array $data)
    {
        return new UserKey(
            $data['algo'],
            hex2bin($data['salt']),
            hex2bin($data['iv']),
            $data['iterations'],
            $data['hash'],
            $data['master_key']
        );
    }
}
