<?php

namespace Datto\Virtualization\Exceptions;

/**
 * @author Jason Lodice <jlodice@datto.com>
 */
class EsxDatastoreDirectoryAddException extends RemoteStorageException
{
    const CREATE_DIR = 3;

    public function __construct(\Throwable $previous = null)
    {
        parent::__construct('Failed to create directory on datastore.', static::CREATE_DIR, $previous);
    }
}
