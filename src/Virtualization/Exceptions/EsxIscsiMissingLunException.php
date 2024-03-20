<?php

namespace Datto\Virtualization\Exceptions;

/**
 * @author Jason Lodice <jlodice@datto.com>
 */
class EsxIscsiMissingLunException extends RemoteStorageException
{
    const MISSING_LUN = 1;

    public function __construct(\Throwable $previous = null)
    {
        parent::__construct('iSCSI LUN is not present.', static::MISSING_LUN, $previous);
    }
}
