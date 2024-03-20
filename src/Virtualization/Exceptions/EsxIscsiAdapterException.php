<?php

namespace Datto\Virtualization\Exceptions;

/**
 * @author Jason Lodice <jlodice@datto.com>
 */
class EsxIscsiAdapterException extends RemoteStorageException
{
    const MISSING_ISCSI = 0;

    public function __construct(\Throwable $previous = null)
    {
        parent::__construct('iSCSI adapter not found on the ESX host.', static::MISSING_ISCSI, $previous);
    }
}
