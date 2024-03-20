<?php

namespace Datto\Virtualization\Exceptions;

/**
 * Exception thrown when detecting that an ESX host that is managed by a vCenter server is being accessed directly.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class BypassedVcenterException extends \Exception
{
    /**
     * @param string $esxHostIp
     * @param string $vCenterIp
     */
    public function __construct(string $esxHostIp, string $vCenterIp)
    {
        parent::__construct("The ESX host $esxHostIp is managed by $vCenterIp, you should use that instead");
    }
}
