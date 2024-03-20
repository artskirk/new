<?php

namespace Datto\Utility\Ssh;

use Datto\Common\Resource\ProcessFactory;

/**
 * Wrapper for the binary "ssh-keygen". Used to generate SSH and other RSA keys.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class SshKeygen
{
    const KEY_TYPE_RSA = 'rsa';
    const EMPTY_PASSPHRASE = '';

    /** @var ProcessFactory */
    private $processFactory;

    public function __construct(ProcessFactory $processFactory)
    {
        $this->processFactory = $processFactory;
    }

    /**
     * Generates a passphraseless private key.
     *
     * @param string $privateKeyFile
     * @param string $keyType
     */
    public function generatePrivateKey(string $privateKeyFile, string $keyType)
    {
        $process = $this->processFactory->get([
                'ssh-keygen',
                '-N',
                self::EMPTY_PASSPHRASE,
                '-t',
                $keyType,
                '-f',
                $privateKeyFile
            ]);
        $process->mustRun();
    }
}
