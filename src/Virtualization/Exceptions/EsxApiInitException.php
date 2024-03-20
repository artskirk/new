<?php

namespace Datto\Virtualization\Exceptions;

/**
 * @author Jason Lodice <jlodice@datto.com>
 */
class EsxApiInitException extends RemoteStorageException
{
    const API_INIT = 7;

    public function __construct(\Throwable $previous = null)
    {
        parent::__construct('Failed to configure vSphere API.', static::API_INIT, $previous);
    }
}
