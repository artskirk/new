<?php

namespace Datto\System;

class ServerCertificateConfiguration
{
    private string $trustedRootCertificateName;
    private string $serviceName;
    private string $caBundlePath;
    private bool $supportsReload;
    private string $extraCertificates;

    public function __construct(
        string $trustedRootCertificateName,
        string $serviceName,
        string $caBundlePath,
        bool $supportsReload,
        string $extraCerts = ''
    ) {
        $this->trustedRootCertificateName = $trustedRootCertificateName;
        $this->serviceName = $serviceName;
        $this->caBundlePath = $caBundlePath;
        $this->supportsReload = $supportsReload;
        $this->extraCertificates = $extraCerts;
    }

    public function getTrustedRootCertificateName(): string
    {
        return $this->trustedRootCertificateName;
    }

    public function getServiceName(): string
    {
        return $this->serviceName;
    }

    public function getCaBundlePath(): string
    {
        return $this->caBundlePath;
    }

    public function getSupportsReload(): bool
    {
        return $this->supportsReload;
    }

    public function getExtraCertificates(): string
    {
        return $this->extraCertificates;
    }
}
