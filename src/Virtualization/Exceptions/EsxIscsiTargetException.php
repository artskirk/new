<?php

namespace Datto\Virtualization\Exceptions;

/**
 * @author Jason Lodice <jlodice@datto.com>
 */
class EsxIscsiTargetException extends RemoteStorageException
{
    const ADD_ISCSI_TARGET = 5;

    public function __construct(\Throwable $previous = null)
    {
        parent::__construct('Failed to add iSCSI target to ESX.', static::ADD_ISCSI_TARGET, $previous);
    }
}
