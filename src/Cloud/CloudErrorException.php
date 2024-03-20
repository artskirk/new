<?php

namespace Datto\Cloud;

use Exception;

/**
 * Dedicated exception class for cloud calls.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class CloudErrorException extends Exception
{
    /** @var array */
    private $errorObject;

    /**
     * @param string $message
     * @param array $errorObject
     */
    public function __construct(string $message, array $errorObject)
    {
        $message .= ' JSON-RPC $response[\'error\'] = "' . json_encode($errorObject) . '"';
        parent::__construct($message);
        $this->errorObject = $errorObject;
    }

    /**
     * Get the JSON-RPC error object that was returned
     * from a failed request
     *
     * @return array
     */
    public function getErrorObject(): array
    {
        return $this->errorObject;
    }
}
