<?php

namespace Datto\Https;

use Exception;

/**
 * Represents a small subset of an x509 certificate for
 * the purpose of retrieving the subject/issuer CN
 * and to determine if it is self-signed.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class Certificate
{
    /** @var Principal */
    private $issuer;

    /** @var Principal */
    private $subject;

    /** @var int */
    private $validTo;

    /** @var array */
    private $fields;

    /**
     * A private constructor to prevent instantiation. Use
     * the fromPem() function to create an instance.
     *
     * @param array $fields
     */
    private function __construct(array $fields)
    {
        $this->fields = $fields;

        // No need to check for existence of these fields,
        // because we do that in the fromPem() function.

        $this->issuer = new Principal($this->fields['issuer']);
        $this->subject = new Principal($this->fields['subject']);
        $this->validTo = $this->fields['validTo_time_t'];
    }

    /**
     * @return Principal
     */
    public function getIssuer(): Principal
    {
        return $this->issuer;
    }

    /**
     * @return Principal
     */
    public function getSubject(): Principal
    {
        return $this->subject;
    }

    /**
     * @return int
     */
    public function getValidTo(): int
    {
        return $this->validTo;
    }

    /**
     * @return bool
     */
    public function isSelfSigned(): bool
    {
        return json_encode($this->fields['issuer']) === json_encode($this->fields['subject']);
    }

    /**
     * Create an instance of this class from a PEM formatted
     * certificate.
     *
     * @param string $pem
     * @return Certificate
     */
    public static function fromPem($pem): Certificate
    {
        $fields = @openssl_x509_parse($pem);
        $validCert = $fields
            && isset($fields['validTo_time_t'])
            && isset($fields['subject']['CN'])
            && isset($fields['issuer']['CN']);

        if (!$validCert) {
            throw new Exception('Certificate cannot be parsed.');
        }

        return new Certificate($fields);
    }
}
