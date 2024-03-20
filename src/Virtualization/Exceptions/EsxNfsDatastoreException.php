<?php

namespace Datto\Virtualization\Exceptions;

/**
 * @author Jason Lodice <jlodice@datto.com>
 */
class EsxNfsDatastoreException extends RemoteStorageException
{
    const NFS_FAIL = 11;

    public function __construct(\Throwable $previous = null)
    {
        parent::__construct('Failed to create NFS datastore.', static::NFS_FAIL, $previous);
    }
}
