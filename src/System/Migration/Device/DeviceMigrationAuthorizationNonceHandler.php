<?php

namespace Datto\System\Migration\Device;

use Datto\Utility\Security\NonceHandler;

/**
 * For keeping track of nonces that have been used for device migrations.
 *
 * @author Chris McGehee <cmcgehee@datto.com>
 */
class DeviceMigrationAuthorizationNonceHandler extends NonceHandler
{
    protected function getNonceFolder(): string
    {
        return 'migrationAuthorization';
    }
}
