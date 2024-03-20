<?php

namespace Datto\Utility\Security;

use Datto\Common\Utility\Filesystem;

/**
 * For keeping track of nonces that have been used. This can be used to prevent the same payload from being accepted
 * twice.
 *
 * @author Chris McGehee <cmcgehee@datto.com>
 */
abstract class NonceHandler
{
    private const BASE_DIRECTORY = '/dev/shm/nonces/';

    /** @var Filesystem */
    private $filesystem;

    /** @var string */
    private $nonceDirectory;

    public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
        $this->nonceDirectory =  self::BASE_DIRECTORY . trim($this->getNonceFolder(), '/') . '/';
    }

    /**
     * Mark nonce so it will not be used again.
     * @param int $nonce
     */
    public function markNonceAsUsed(int $nonce): void
    {
        $this->filesystem->mkdirIfNotExists($this->nonceDirectory, true);
        $this->filesystem->touch($this->nonceDirectory . $nonce);
    }

    /**
     * @param int $nonce
     * @return bool True if nonce has been used, otherwise false
     */
    public function hasNonceBeenUsed(int $nonce): bool
    {
        return $this->filesystem->exists($this->nonceDirectory . $nonce);
    }

    /**
     * The name of the folder where nonces will be written. This should be a relative not absolute folder.
     */
    abstract protected function getNonceFolder(): string;
}
