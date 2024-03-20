<?php

namespace Datto\Asset\Agent\Encryption;

use Datto\Log\SanitizedException;

/**
 * @author Oliver Castaneda <ocastaneda@datto.com>
 */
class PassphraseNotFoundException extends AbstractPassphraseException
{
    public function __construct(
        SanitizedException $previous = null
    ) {
        $code = $previous ? $previous->getCode() : 0;
        parent::__construct('The given passphrase does not match a valid passphrase for this agent.', $code, $previous);
    }
}
