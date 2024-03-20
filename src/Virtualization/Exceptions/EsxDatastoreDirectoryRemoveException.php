<?php

namespace Datto\Virtualization\Exceptions;

/**
 * @author Jason Lodice <jlodice@datto.com>
 */
class EsxDatastoreDirectoryRemoveException extends RemoteStorageException
{
    const CODE = 12;

    public function __construct(string $message, \Throwable $previous = null)
    {
        parent::__construct("Failed to delete directory on datastore. $message", static::CODE, $previous);
    }
}
