<?php

namespace Datto\Asset\Agent\Encryption;

use Datto\Utility\Security\SecretString;
use Datto\Common\Utility\Crypto\KeyWrap;
use Datto\Common\Utility\Crypto\Jwk;
use Exception;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Exception\JsonException;

/**
 * Responsible for managing the encryption keys for agent data
 */
class JweEncryptionHelper
{
    private KeyWrap $keyWrap;

    public function __construct(KeyWrap $keyWrap)
    {
        $this->keyWrap = $keyWrap;
    }

    /**
     * Encrypts the agent master key with the passphrase.
     *
     * @param SecretString $masterKey The master key to be encrypted with the passphrase
     * @param SecretString $passphrase
     * @return string The JWE that can be used to decrypt the master key if supplied the passphrase.
     */
    public function createUserKey(SecretString $masterKey, SecretString $passphrase): string
    {
        return $this->keyWrap->wrap(new Jwk($masterKey->getSecret()), $passphrase->getSecret());
    }

    public function getMasterKey(
        string $userKeyJwe,
        SecretString $passphrase,
        EncryptionKeyStashRecord $encryptionKeyStashRecord,
        string &$masterKey
    ): bool {
        $masterKey = "";
        $result = false;

        try {
            $jwk = $this->keyWrap->unwrap($userKeyJwe, $passphrase->getSecret());
            $masterKey = $jwk->getKeyBytes();
            $result = true;
        } catch (InvalidArgumentException|JsonException $x) {
            throw $x;
        } catch (Exception $e) {
            // This is most likely due to an invalid passphrase. The caller may be trying the same
            // passphrase on multiple JWEs to find a match, so we do nothing here. The function will
            // return false, and we leave it to the caller to interpret the result.
        }

        if ($result) {
            if ($encryptionKeyStashRecord->verifyMasterKey($masterKey) === false) {
                throw new Exception(
                    "Found valid user key, but hash of master key as decrypted by user key did not match known hash of master key"
                );
            }
        }

        return $result;
    }
}
