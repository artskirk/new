<?php

namespace Datto\Core\Security;

use phpseclib\Crypt\AES;
use phpseclib\Crypt\Base;

/**
 * @author Bartosz Neuman <bneuman@datto.com>
 */
class Cipher
{
    /** @var Base */
    private $cipher;

    /** @var string
     *       Crypt key that should NOT be casually changed. We need to be able to read previously encrypted values.
     *       As such, when introducing a new key DO keep the old one and make sure it will fall back in case of failure.
     */
    private $cryptKey = 'b7193cd3ef75a73ffee115a2d778546786d8668f2406ea2f93818269d2a352a3';

    public function __construct(Base $cipher = null)
    {
        if ($cipher !== null) {
            $this->cipher = $cipher;
        } else {
            $this->cipher = new AES();
            $this->cipher->setKey($this->cryptKey);
        }
    }

    public function decrypt(string $cryptText): string
    {
        if (empty($cryptText)) {
            throw new \Exception("Nothing to decrypt");
        }

        return $this->cipher->decrypt(base64_decode($cryptText));
    }

    public function encrypt(string $plainText): string
    {
        return base64_encode($this->cipher->encrypt($plainText));
    }
}
