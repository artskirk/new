<?php

namespace Datto\Mercury;

use JsonSerializable;

class MercuryFTPEndpoint implements Jsonserializable
{
    private string $hostName;
    private string $ipAddress;
    private bool $isTlsCertValid;

    public function __construct(string $hostName, string $ipAddress, bool $isTlsCertValid = false)
    {
        $this->hostName = $hostName;
        $this->ipAddress = $ipAddress;
        $this->isTlsCertValid = $isTlsCertValid;
    }

    public function getHostName(): string
    {
        return $this->hostName;
    }

    public function getIpAddress(): string
    {
        return $this->ipAddress;
    }

    public function isTlsCertificateValid(): bool
    {
        return $this->isTlsCertValid;
    }

    public function setIsTlsCertificateValid(bool $isTlsCertValid): void
    {
        $this->isTlsCertValid = $isTlsCertValid;
    }

    public function jsonSerialize(): array
    {
        return [
            'hostName' => $this->hostName,
            'ipAddress' => $this->ipAddress,
            'isTlsCertValid' => $this->isTlsCertValid
        ];
    }

    public static function fromArray(array $array): self
    {
        return new self(
            $array['hostName'],
            $array['ipAddress'],
            $array['isTlsCertValid']
        );
    }
}
