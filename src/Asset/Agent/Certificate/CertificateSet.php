<?php

namespace Datto\Asset\Agent\Certificate;

/**
 * Class to represent a set of files that are needed to connect to an Agent. The three files are:
 *  - root certificate: currently, this is both the certificate that has signed our device certificate as
 *      well as the certificate that we use to validate the agent's server certificate signature.
 *  - device certificate: this is a certificate given to us by device-web in response to a CSR we send them. Its common
 *      name is the device id and it is signed by the private key of the root certificate.
 *  - device key: this is the device's private key which is cryptographically tied to the public key in the device
 *      certificate.
 *
 * @author Chad Barbe <cbarbe@datto.com>
 */
class CertificateSet
{
    /** @var string date (YYYYMMDD) that the root CA was retrieved for this set, used for sorting */
    private $date;

    /** @var string hash of the contents of the root CA, used to identify the exact CA being used */
    private $hash;

    /** @var string path to the root certificate file used to sign the device certificate and to validate the agent */
    private $rootCertificatePath;

    /** @var string path to the private key associated with the device certificate */
    private $deviceKeyPath;

    /** @var string path to the device certificate for this set */
    private $deviceCertPath;

    public function __construct(
        string $date,
        string $hash,
        string $rootCertificatePath,
        string $deviceKeyPath,
        string $deviceCertPath
    ) {
        $this->date = $date;
        $this->hash = $hash;
        $this->rootCertificatePath = $rootCertificatePath;
        $this->deviceKeyPath = $deviceKeyPath;
        $this->deviceCertPath = $deviceCertPath;
    }

    public function getDate(): string
    {
        return $this->date;
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    public function getRootCertificatePath(): string
    {
        return $this->rootCertificatePath;
    }

    public function getDeviceKeyPath(): string
    {
        return $this->deviceKeyPath;
    }

    public function getDeviceCertPath(): string
    {
        return $this->deviceCertPath;
    }
}
