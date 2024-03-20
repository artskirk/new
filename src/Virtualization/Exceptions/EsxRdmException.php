<?php

namespace Datto\Virtualization\Exceptions;

/**
 * @author Jason Lodice <jlodice@datto.com>
 */
class EsxRdmException extends RemoteStorageException
{
    const RDM_FAIL = 2;

    public function __construct(\Throwable $previous = null)
    {
        $msg = "Failed to create RDM drive. If your datastore is SAN " .
            "device, please make sure it is on the same network as the " .
            "device.";

        parent::__construct($msg, static::RDM_FAIL, $previous);
    }
}
