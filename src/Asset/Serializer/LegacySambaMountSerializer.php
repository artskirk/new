<?php

namespace Datto\Asset\Serializer;

use Datto\System\SambaMount;
use phpseclib\Crypt\AES;
use phpseclib\Crypt\Random;

/**
 * Serialize and unserialize a SambaMount object into the key files '.sambaMount' and '.sambaMountKey'.
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class LegacySambaMountSerializer implements Serializer
{
    const KEY_LENGTH = 16;

    /** @var AES */
    private $cipher;

    /** @var Random */
    private $random;

    /**
     * @param AES|null $cipher
     * @param Random|null $random
     */
    public function __construct(AES $cipher = null, Random $random = null)
    {
        $this->cipher = $cipher ?: new AES();
        $this->random = $random ?: new Random();
    }

    /**
     * Serializes a SambaMount object into an array of string representing the matching config files
     * '.sambaMount' and '.sambaMountKey'. The password is written out encrypted.
     *
     * @param SambaMount $sambaMount
     * @return array
     */
    public function serialize($sambaMount)
    {
        $passwordKey = $sambaMount->getPasswordKey();
        if ($passwordKey === null) {
            $passwordKey = $this->random->string(static::KEY_LENGTH);
        }
        $this->cipher->setKey($passwordKey);
        $fileArray = array(
            'sambaMount' => serialize(array(
                'host' => $sambaMount->getHost(),
                'folder' => $sambaMount->getFolder(),
                'username' => $sambaMount->getUsername(),
                'password' => base64_encode($this->cipher->encrypt($sambaMount->getPassword())),
                'domain' => $sambaMount->getDomain(),
                'readOnly' => $sambaMount->isReadOnly(),
                'includeCifsAcls' => $sambaMount->includeCifsAcls()
            )),
            'sambaMountKey' => base64_encode($passwordKey)
        );

        return $fileArray;
    }

    /**
     * Serialize an array of file contents of '.sambaMount' and '.sambaMountKey' into a SambaMount object.
     *
     * @param array $fileArray Array of strings containing the file contents of the above listed files.
     * @return SambaMount
     */
    public function unserialize($fileArray)
    {
        $members = unserialize($fileArray['sambaMount'], ['allowed_classes' => false]);
        $passwordKey = $fileArray['sambaMountKey'];
        if (is_string($passwordKey) && isset($members['password'])) {
            $passwordKey = base64_decode($passwordKey);
            $this->cipher->setKey($passwordKey);
            $password = $this->cipher->decrypt(base64_decode($members['password']));
        } else {
            $password = null;
            $passwordKey = null;
        }

        $sambaMount = new SambaMount(
            @$members['host'],
            @$members['folder'],
            @$members['username'],
            $password,
            @$members['domain'],
            @$members['readOnly'],
            @$members['includeCifsAcls'] ?? false,
            $passwordKey
        );

        return $sambaMount;
    }
}
