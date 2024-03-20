<?php

namespace Datto\Https;

/**
 * Represents a small subset of an x509 principal, i.e.
 * the subject or the issuer.
 *
 * This is only used to get the common name (CN) field
 * of the certificate and a string representation of
 * the principal.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class Principal
{
    /** @var array */
    private $fields;

    /** @var string */
    private $commonName;

    /**
     * @param array $fields Fields as per the output of openssl_x509_parse()
     */
    public function __construct(array $fields)
    {
        $this->fields = $fields;
        $this->commonName = $fields['CN'] ?? '';
    }

    /**
     * @return string
     */
    public function getCommonName()
    {
        return $this->commonName;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        $keys = array_keys($this->fields);
        $values = array_values($this->fields);

        $flattened = array_map(function ($k, $v) {
            return "$k=$v";
        }, $keys, $values);

        return join('/', $flattened);
    }
}
