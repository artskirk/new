<?php

namespace Datto\Util;

/**
 * Maps http response codes to human readable strings.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class HttpErrorHelper
{
    const HTTP_CODE_STRINGS = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Time-out',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Payload Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        418 => 'I\'m a teapot',
        421 => 'Misdirected Request',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons'
    ];

    /**
     * Get the http error string associated with the http response code
     *
     * @param string|int $httpCode
     * @return string
     */
    public function getHttpErrorString($httpCode): string
    {
        $result = '';

        if (is_numeric($httpCode) &&
            array_key_exists($httpCode, self::HTTP_CODE_STRINGS)) {
            $result = self::HTTP_CODE_STRINGS[$httpCode];
        }

        return $result;
    }
}
