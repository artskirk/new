<?php

namespace Datto\Service\CloudManagedConfig\Exception;

class VersionConflict extends \Exception
{
    const MESSAGE_FORMAT = 'Config version does not match (local: %s, cloud: %s)';

    public function __construct(string $deviceVersion, string $cloudVersion)
    {
        parent::__construct(sprintf(self::MESSAGE_FORMAT, $deviceVersion, $cloudVersion));
    }
}
