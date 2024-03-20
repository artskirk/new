<?php

namespace Datto\Service\AssetManagement\Create;

use Datto\Log\DeviceLoggerInterface;
use Datto\Utility\Security\SecretString;

/**
 * This class holds the context that is used by the agent creation stages.
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class CreateAgentContext
{
    private string $agentKeyName;
    private string $uuid;
    private string $offsiteTarget;
    private SecretString $password;
    private string $domainName;
    private string $moRef;
    private string $connectionName;
    private string $agentKeyToCopy;
    private bool $force;
    private bool $fullDisk;
    private DeviceLoggerInterface $logger;

    public function __construct(
        string $agentKeyName,
        string $uuid,
        string $offsiteTarget,
        SecretString $password,
        string $domainName,
        string $moRef,
        string $connectionName,
        string $agentKeyToCopy,
        bool $force,
        bool $fullDisk,
        DeviceLoggerInterface $logger
    ) {
        $this->agentKeyName = $agentKeyName;
        $this->uuid = $uuid;
        $this->offsiteTarget = $offsiteTarget;
        $this->password = $password;
        $this->domainName = $domainName;
        $this->moRef = $moRef;
        $this->connectionName = $connectionName;
        $this->agentKeyToCopy = $agentKeyToCopy;
        $this->force = $force;
        $this->fullDisk = $fullDisk;
        $this->logger = $logger;
    }

    /**
     * @return bool True if we're pairing an agentless system. False if we're pairing an agent based system.
     */
    public function isAgentless(): bool
    {
        return !empty($this->moRef) && !empty($this->connectionName);
    }

    public function needsEncryption(): bool
    {
        return !empty($this->password->getSecret());
    }

    public function getAgentKeyName(): string
    {
        return $this->agentKeyName;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getOffsiteTarget(): string
    {
        return $this->offsiteTarget;
    }

    public function setOffsiteTarget(string $offsiteTarget)
    {
        $this->offsiteTarget = $offsiteTarget;
    }

    public function getPassword(): SecretString
    {
        return $this->password;
    }

    public function getDomainName(): string
    {
        return $this->domainName;
    }

    public function getMoRef(): string
    {
        return $this->moRef;
    }

    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    public function getAgentKeyToCopy(): string
    {
        return $this->agentKeyToCopy;
    }

    public function isForce(): bool
    {
        return $this->force;
    }

    public function isFullDisk(): bool
    {
        return $this->isAgentless() && $this->fullDisk;
    }

    public function getLogger(): DeviceLoggerInterface
    {
        return $this->logger;
    }
}
