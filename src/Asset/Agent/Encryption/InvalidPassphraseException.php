<?php

namespace Datto\Asset\Agent\Encryption;

use Datto\Log\SanitizedException;

/**
 * @author Oliver Castaneda <ocastaneda@datto.com>
 */
class InvalidPassphraseException extends AbstractPassphraseException
{
    public function __construct(
        SanitizedException $previous = null
    ) {
        $code = $previous ? $previous->getCode() : 0;
        parent::__construct('Invalid passphrase', $code, $previous);
    }
}
