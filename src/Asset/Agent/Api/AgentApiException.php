<?php

namespace Datto\Asset\Agent\Api;

use Exception;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Exception for when an exception is thrown while interfacing with an agent's API.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class AgentApiException extends Exception
{
    const INVALID_JOB_ID = -100;

    /** @var int HTTP response code */
    private $httpCode;

    /** @var string The contents of the response that caused this AgentApiException */
    private $response;

    /**
     * @param string $message
     * @param int $code
     * @param int $httpCode
     * @param string $response
     * @param Throwable|null $previous
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        int $httpCode = Response::HTTP_OK,
        string $response = '',
        Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);

        $this->httpCode = $httpCode;
        $this->response = $response;
    }

    /**
     * @return int
     */
    public function getHttpCode(): int
    {
        return $this->httpCode;
    }

    /**
     * Returns the contents of the response that caused the AgentApiException
     * @return string
     */
    public function getResponse(): string
    {
        return $this->response;
    }
}
