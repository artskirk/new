<?php

namespace Datto\Virtualization\Exceptions;

/**
 * @author Jason Lodice <jlodice@datto.com>
 */
class HvIscsiCleanupException extends RemoteStorageException
{
    const CODE = 14;

    public function __construct(\Throwable $previous = null)
    {
        parent::__construct('Failed to remove iSCSI target.', static::CODE, $previous);
    }
}
