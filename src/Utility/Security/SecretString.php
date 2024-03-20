<?php

namespace Datto\Utility\Security;

/**
 * String wrapper that obscures content when cast as a string - useful for protecting secrets in stack-traces
 */
class SecretString
{
    /** @var string Plaintext of the secret string */
    private $secret;

    public function __construct(string $secret)
    {
        $this->secret = $secret;
    }

    public function getSecret(): string
    {
        return $this->secret;
    }

    /**
     * Return an obscured string so that the secret is not exposed when cast as a string
     */
    public function __toString(): string
    {
        return '***';
    }
}
