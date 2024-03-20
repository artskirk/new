<?php

namespace Datto\Virtualization\Exceptions;

/**
 * @author Jason Lodice <jlodice@datto.com>
 */
class HvIscsiException extends RemoteStorageException
{
    const CODE = 13;

    public function __construct(\Throwable $previous = null)
    {
        parent::__construct('Failed to offload storage via ISCSI.', static::CODE, $previous);
    }
}
