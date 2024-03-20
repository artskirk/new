<?php

namespace Datto\JsonRpc;

/**
 * The JSON RPC request is invalid
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class JsonRpcInvalidRequestException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Invalid Request', -32600);
    }
}
