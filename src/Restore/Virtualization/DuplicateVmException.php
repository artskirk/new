<?php

namespace Datto\Restore\Virtualization;

/**
 * @author Jason Lodice <jlodice@datto.com>
 */
class DuplicateVmException extends \Exception
{
    public const MESSAGE_PREFIX = 'DuplicateVmException';

    public function __construct(string $vmName, string $connectionName)
    {
        parent::__construct(self::MESSAGE_PREFIX . " A vm with name '$vmName' already exists on hypervisor '$connectionName'.");
    }
}
