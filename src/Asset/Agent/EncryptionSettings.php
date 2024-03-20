<?php

namespace Datto\Asset\Agent;

/**
 * Class to represent Encryption from the filesystem
 *
 * Note:
 *      This class can be used to also store the contents of the EncryptionKeyStash file
 *      which is a json serialized array
 *
 * @author Rixhers Ajazi <rajazi@datto.com>
 */
class EncryptionSettings
{
    /** @var bool */
    private $encrypted;

    /** @var string|null */
    private $tempAccessKey;

    /** @var array|null */
    private $keyStash;

    public function __construct(bool $encrypted, $tempAccessKey, $keyStash)
    {
        $this->encrypted = $encrypted;
        $this->tempAccessKey = $tempAccessKey;
        $this->keyStash = $keyStash;
    }

    /**
     * Returns whether encryption is enabled on the device
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->encrypted;
    }

    /**
     * Returns whether temporary access to encrypted data is enabled
     *
     * @return string|null
     */
    public function getTempAccessKey()
    {
        return $this->tempAccessKey;
    }

    /**
     * Sets a $sig value which controls temporary passwordless encrypted data access.
     * Use a null parameter to disable.
     *
     * @param string|null $sig - null or a hash_hmac computed by the EncryptionService class
     */
    public function setTempAccessKey($sig): void
    {
        $this->tempAccessKey = $sig;
    }

    /**
     * Returns array of encryption key information
     *
     * @return array|null
     */
    public function getKeyStash()
    {
        return $this->keyStash;
    }
}
