<?php

namespace Datto\Restore\Virtualization;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\EncryptionService;
use Datto\Asset\Agent\Config\VmStatus;
use Datto\Asset\Agent\Encryption\TempAccessService;
use Datto\Config\AgentConfigFactory;
use Datto\Connection\ConnectionType;
use Datto\Log\LoggerFactory;
use Datto\Resource\DateTimeService;
use Datto\Restore\AgentVirtualizationRestore;
use Datto\Restore\RestoreService;
use Datto\Utility\Security\SecretString;
use Datto\Virtualization\VirtualMachine;
use Datto\Virtualization\Hypervisor\Config\AbstractVmSettings;
use Datto\Virtualization\Hypervisor\Config\VmSettingsFactory;
use RuntimeException;

/**
 * Methods to support Agent Virtualization.
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class VirtualizationRestoreTool
{
    const STATUS_START_VM = 'startVirtualMachine';
    const STATUS_STOP_VM = 'stopVirtualMachine';
    const STATUS_DESTROY_VM = 'destroyVirtualMachine';

    private AgentConfigFactory $agentConfigFactory;
    private DateTimeService $dateTimeService;
    private RestoreService $restoreService;
    private LoggerFactory $loggerFactory;
    private EncryptionService $encryptionService;
    private TempAccessService $tempAccessService;

    public function __construct(
        AgentConfigFactory $agentConfigFactory,
        DateTimeService $dateTimeService,
        RestoreService $restoreService,
        LoggerFactory $loggerFactory,
        EncryptionService $encryptionService,
        TempAccessService $tempAccessService
    ) {
        $this->agentConfigFactory = $agentConfigFactory;
        $this->dateTimeService = $dateTimeService;
        $this->restoreService = $restoreService;
        $this->loggerFactory = $loggerFactory;
        $this->encryptionService = $encryptionService;
        $this->tempAccessService = $tempAccessService;
    }

    /**
     * Create a new UI Restore instance
     */
    public function createRestore(
        string $agentKey,
        string $snapshot,
        string $suffix,
        string $connectionName
    ): AgentVirtualizationRestore {
        $restore = new AgentVirtualizationRestore(
            $agentKey,
            $snapshot,
            $suffix,
            strval($this->dateTimeService->getTime()),
            ['connectionName' => $connectionName]
        );
        $restore->updateVmPoweredOnOption(false);
        $this->restoreService->getAll();
        $this->restoreService->add($restore);
        $this->restoreService->save();

        return $restore;
    }

    /**
     * Delete the UI Restore instance
     */
    public function deleteRestore(string $agentKey, string $suffix): void
    {
        $logger = $this->loggerFactory->getAsset($agentKey);
        $restore = $this->restoreService->findMostRecent($agentKey, $suffix);
        if (is_null($restore)) {
            $logger->warning('VRT0003 Could not find restore for agent for deletion.', ['suffix' => $suffix]);
            return;
        }

        $this->restoreService->remove($restore);
        $this->restoreService->save();
    }

    /**
     * Update the restore power state for this agent
     */
    public function updateRestorePowerState(string $agentKey, string $suffix, bool $poweredOn): void
    {
        $logger = $this->loggerFactory->getAsset($agentKey);

        /** @var ?AgentVirtualizationRestore $restore */
        $restore = $this->restoreService->findMostRecent($agentKey, $suffix);
        if (is_null($restore)) {
            $logger->warning('VRT0004 Could not find virtualization restore', ['virtualizationSuffix' => $suffix]);
        }

        if (!is_null($restore)) {
            $restore->updateVmPoweredOnOption($poweredOn);
            $this->restoreService->update($restore);
            $this->restoreService->save();
        }
    }

    public function getVmSettings(Agent $agent, ConnectionType $connectionType): AbstractVmSettings
    {
        $settings = VmSettingsFactory::create($connectionType);
        $this->agentConfigFactory
            ->create($agent->getKeyName())
            ->loadRecord($settings);

        return $settings;
    }

    /**
     * Update the vm status with the message percent completion and optional error.
     * Percentage completion is calculated from currentStep/totalSteps
     */
    public function updateVmStatus(string $agentKey, int $currentStep, int $totalSteps, string $message, ?string $error = null): void
    {
        $totalSteps = max($totalSteps, 1);
        $agentConfig = $this->agentConfigFactory->create($agentKey);
        $percentComplete = round(($currentStep / $totalSteps) * 100);
        $vmStatus = new VmStatus($message, intval($percentComplete), $error);
        $agentConfig->saveRecord($vmStatus);
    }

    /**
     * Update the vm status with the starting status
     */
    public function updateVmStatusStart(string $agentKey, int $currentStep, int $totalSteps, ?string $error = null): void
    {
        $this->updateVmStatus($agentKey, $currentStep, $totalSteps, static::STATUS_START_VM, $error);
    }

    /**
     * Update the vm status with the stopping status
     */
    public function updateVmStatusStop(string $agentKey, int $currentStep, int $totalSteps, ?string $error = null): void
    {
        $this->updateVmStatus($agentKey, $currentStep, $totalSteps, static::STATUS_STOP_VM, $error);
    }

    /**
     * Update the vm with the destroying status
     */
    public function updateVmStatusDestroy(string $agentKey, int $currentStep, int $totalSteps, ?string $error = null): void
    {
        $this->updateVmStatus($agentKey, $currentStep, $totalSteps, static::STATUS_DESTROY_VM, $error);
    }

    /**
     * Clear the VM status record for this agent
     */
    public function clearVmStatus(string $agentKey): void
    {
        $agentConfig = $this->agentConfigFactory->create($agentKey);
        $agentConfig->clearRecord(new VmStatus());
    }

    /**
     * If necessary, ensure agent key is decrypted using given passphrase
     */
    public function decryptAgentKey(string $agentKey, SecretString $passphrase): void
    {
        if ($this->encryptionService->isEncrypted($agentKey) &&
            !$this->tempAccessService->isCryptTempAccessEnabled($agentKey)) {
            $this->encryptionService->decryptAgentKey($agentKey, $passphrase);
        }
    }

    public function assertVmNotNull(string $agentKey, ?VirtualMachine $vm): void
    {
        if (is_null($vm)) {
            throw new RuntimeException("Could not find vm for agentKey '$agentKey'");
        }
    }

    /**
     * @return bool whether the agent is sealed
     */
    public function isAgentSealed(string $agentKey): bool
    {
        return $this->encryptionService->isAgentSealed($agentKey);
    }
}
