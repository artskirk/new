<?php

namespace Datto\Util;

/**
 * Utility to sanitize arrays of parameters, so they can be logged.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class ArraySanitizer
{
    /** @var array list of patterns for parameter keys to sanitize */
    private $paramsToSanitize = [
        '/password/i',
        '/passphrase/i',
        '/^pass$/i',
        '/domainpass/i'
    ];

    /** @var string the string to replace sensitive values */
    private $sanitizedString = '***';

    /**
     * Sanitize the array of parameters, so they can be logged.
     *
     * @param array $message
     * @return array
     */
    public function sanitizeParams(array $message): array
    {
        array_walk_recursive(
            $message,
            function (
                &$value,
                $key
            ) {
                foreach ($this->paramsToSanitize as $param) {
                    if (preg_match($param, $key)) {
                        $value = $this->sanitizedString;
                    }
                }
            }
        );

        return $message;
    }
}
