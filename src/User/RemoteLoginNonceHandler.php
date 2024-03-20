<?php

namespace Datto\User;

use Datto\Utility\Security\NonceHandler;

/**
 * For keeping track of nonces that have been used to login in via remote web.
 *
 * @author Chris McGehee <cmcgehee@datto.com>
 */
class RemoteLoginNonceHandler extends NonceHandler
{
    protected function getNonceFolder(): string
    {
        return 'remoteLogin';
    }
}
