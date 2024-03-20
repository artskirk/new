<?php

namespace Datto\Agentless\Proxy;

use Datto\Log\DeviceLoggerInterface;
use Vmwarephp\Extensions\VirtualMachine;
use Vmwarephp\ManagedObject;
use Vmwarephp\Vhost;

/**
 * Data object that encapsulates all agentless session information.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class AgentlessSession
{
    public const TRANSFER_METHOD_MERCURY_FTP = "mercury-ftp";
    public const TRANSFER_METHOD_HYPER_SHUTTLE = "hyper-shuttle";

    private AgentlessSessionId $agentlessSessionId;
    private string $transferMethod;
    private string $host;
    private string $user;
    private string $password;
    private Vhost $virtualizationHost;
    private VirtualMachine $virtualMachine;
    private ManagedObject $snapshot;
    private string $vmMoRefId;
    private string $snapshotMoRefId;
    /** @var string[][] */
    private array $esxVmInfo;
    /** @var string[][] */
    private array $agentVmInfo;
    private bool $fullDiskBackup;
    private bool $forceNbd;
    private DeviceLoggerInterface $logger;

    /**
     * @param AgentlessSessionId $agentlessSessionId
     * @param string $transferMethod
     * @param string $host
     * @param string $user
     * @param string $password
     * @param Vhost $virtualizationHost
     * @param VirtualMachine $virtualMachine
     * @param ManagedObject $snapshot
     * @param string $vmMoRefId
     * @param string $snapshotMoRefId
     * @param string[][] $esxVmInfo
     * @param string[][] $agentVmInfo
     * @param bool $fullDiskBackup
     * @param bool $forceNbd
     * @param DeviceLoggerInterface $logger
     */
    public function __construct(
        AgentlessSessionId $agentlessSessionId,
        string $transferMethod,
        string $host,
        string $user,
        string $password,
        Vhost $virtualizationHost,
        VirtualMachine $virtualMachine,
        ManagedObject $snapshot,
        string $vmMoRefId,
        string $snapshotMoRefId,
        array $esxVmInfo,
        array $agentVmInfo,
        bool $fullDiskBackup,
        bool $forceNbd,
        DeviceLoggerInterface $logger
    ) {
        $this->agentlessSessionId = $agentlessSessionId;
        $this->transferMethod = $transferMethod;
        $this->host = $host;
        $this->user = $user;
        $this->password = $password;
        $this->virtualizationHost = $virtualizationHost;
        $this->virtualMachine = $virtualMachine;
        $this->snapshot = $snapshot;
        $this->vmMoRefId = $vmMoRefId;
        $this->snapshotMoRefId = $snapshotMoRefId;
        $this->esxVmInfo = $esxVmInfo;
        $this->agentVmInfo = $agentVmInfo;
        $this->fullDiskBackup = $fullDiskBackup;
        $this->forceNbd = $forceNbd;
        $this->logger = $logger;
    }

    public function getAgentlessSessionId(): AgentlessSessionId
    {
        return $this->agentlessSessionId;
    }

    public function getTransferMethod(): string
    {
        return $this->transferMethod;
    }

    public function isUsingHyperShuttle(): bool
    {
        return $this->transferMethod === self::TRANSFER_METHOD_HYPER_SHUTTLE;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getUser(): string
    {
        return $this->user;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getVirtualizationHost(): Vhost
    {
        return $this->virtualizationHost;
    }

    public function getVirtualMachine(): VirtualMachine
    {
        return $this->virtualMachine;
    }

    public function getSnapshot(): ManagedObject
    {
        return $this->snapshot;
    }

    /**
     * @return string[][]
     */
    public function getEsxVmInfo(): array
    {
        return $this->esxVmInfo;
    }

    /**
     * @return string[][]
     */
    public function getAgentVmInfo(): array
    {
        return $this->agentVmInfo;
    }

    public function getSnapshotMoRefId(): string
    {
        return $this->snapshotMoRefId;
    }

    public function getVmMoRefId(): string
    {
        return $this->vmMoRefId;
    }

    public function isFullDiskBackup(): bool
    {
        return $this->fullDiskBackup;
    }

    public function getLogger(): DeviceLoggerInterface
    {
        return $this->logger;
    }

    public function isForceNbd(): bool
    {
        return $this->forceNbd;
    }
}
