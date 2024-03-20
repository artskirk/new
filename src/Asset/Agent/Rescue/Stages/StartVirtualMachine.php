<?php

namespace Datto\Asset\Agent\Rescue\Stages;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Backup\AgentSnapshotService;
use Datto\Asset\Agent\Rescue\RescueAgentCreationContext;
use Datto\Connection\Service\ConnectionService;
use Datto\Restore\CloneSpec;
use Datto\Restore\RestoreType;
use Datto\Restore\Virtualization\VirtualizationRestoreTool;
use Datto\Restore\Virtualization\VirtualMachineService;
use Datto\Virtualization\VirtualMachine;
use Datto\Log\DeviceLoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Transactional stage for booting up a rescue agent's virtual machine.
 *
 * @author Christopher R. Wicks <cwicks@datto.com>
 * @author Jason Lodice <jlodice@datto.com>
 */
class StartVirtualMachine extends CreationStage
{
    const STATUS_MESSAGE = 'startVirtualMachine';

    /** @var VirtualMachineService */
    private $virtualMachineService;

    /** @var string */
    private $connectionName;

    /** @var bool */
    private $hasNativeConfiguration;

    /** @var ConnectionService */
    private $connectionService;

    /** @var VirtualizationRestoreTool */
    private $virtRestoreTool;

    /**
     * StartVirtualMachine constructor.
     *
     * @param RescueAgentCreationContext $context
     * @param string $connectionName
     * @param VirtualMachineService $virtualMachineService
     * @param ConnectionService $connectionService
     * @param VirtualizationRestoreTool $virtRestoreTool
     * @param DeviceLoggerInterface $logger
     */
    public function __construct(
        RescueAgentCreationContext $context,
        string $connectionName,
        bool $hasNativeConfiguration,
        VirtualMachineService $virtualMachineService,
        ConnectionService $connectionService,
        VirtualizationRestoreTool $virtRestoreTool,
        DeviceLoggerInterface $logger
    ) {
        parent::__construct($logger, $context);

        $this->connectionName = $connectionName;
        $this->hasNativeConfiguration = $hasNativeConfiguration;
        $this->virtualMachineService = $virtualMachineService;
        $this->connectionService = $connectionService;
        $this->virtRestoreTool = $virtRestoreTool;
    }

    /**
     * Start up the rescue agent's virtual machine.
     */
    public function commit(): void
    {
        $this->logger->setAssetContext($this->context->getRescueAgentUuid());

        $rescueAgent = $this->context->getRescueAgent();

        // dataset clone was created in a previous stage
        $cloneSpec = CloneSpec::fromRescueAgent($rescueAgent);

        // starting an existing vm should have called RescueAgentService::start
        $this->assertVmDoesNotExist($cloneSpec->getTargetMountpoint(), $this->logger);

        if (is_null($connection = $this->connectionService->get($this->connectionName))) {
            throw new RuntimeException("Cannot find hypervisor connection with name '$this->connectionName'");
        }

        $vmName = $this->virtualMachineService->generateVmName(
            $rescueAgent,
            $cloneSpec->getSuffix()
        );

        $this->logger->info('SVM0001 Creating Rescue agent vm', ['vmName' => $vmName]);

        $this->virtRestoreTool->createRestore(
            $rescueAgent->getKeyName(),
            $this->context->getRescueAgent()->getLocal()->getRecoveryPoints()->getLast()->getEpoch(),
            RestoreType::RESCUE,
            $this->connectionName
        );

        // Grab the latest rescue agent snapshot, taken before this stage, to use for retrieving the agentInfo file
        $rescueAgentEpoch = $this->context->getRescueAgent()->getLocal()->getRecoveryPoints()->getLast()->getEpoch();

        $vmSettings = $this->virtRestoreTool->getVmSettings($rescueAgent, $connection->getType());
        $vm = $this->virtualMachineService->createAgentVm(
            $rescueAgent,
            $vmName,
            $cloneSpec,
            $rescueAgentEpoch,
            $connection,
            $vmSettings,
            false,
            $this->hasNativeConfiguration
        );

        $vm->start();

        $this->virtRestoreTool->updateRestorePowerState($rescueAgent->getKeyName(), RestoreType::RESCUE, true);
    }

    /**
     * Rolling back this stage tears down the VM if it was successfully created.
     */
    public function rollback(): void
    {
        $rescueAgent = $this->context->getRescueAgent();

        $cloneSpec = CloneSpec::fromRescueAgent($rescueAgent);

        $storageDir = $cloneSpec->getTargetMountpoint();
        $vm = $this->virtualMachineService->getVm($storageDir, $this->logger);

        if (is_null($vm)) {
            $this->logger->warning('SVM0002 Rescue agent vm does not exist in expected directory, skipping destroy', ['storageDir' => $storageDir]);
        } else {
            $this->tryDeleteVm($rescueAgent, $vm);
        }
        $this->tryDeleteRestore($rescueAgent->getKeyName(), $this->logger);
    }

    /**
     * {@inheritdoc}
     */
    public function getStatusMessage(): string
    {
        return self::STATUS_MESSAGE;
    }

    /**
     * Throw an exception if the VM exists for this directory
     *
     * @param string $storageDir
     * @param DeviceLoggerInterface $logger
     */
    private function assertVmDoesNotExist(string $storageDir, DeviceLoggerInterface $logger): void
    {
        $vm = $this->virtualMachineService->getVm($storageDir, $logger);
        if (!is_null($vm)) {
            throw new RuntimeException("Rescue agent vm {$vm->getName()} already exists.");
        }
    }

    /**
     * Attempt to delete the vm and log any error
     *
     * @param Agent $agent
     * @param VirtualMachine $vm
     */
    private function tryDeleteVm(Agent $agent, VirtualMachine $vm): void
    {
        try {
            $this->logger->setAssetContext($agent->getKeyName());
            $this->virtualMachineService->destroyAgentVm($agent, $vm);
        } catch (Throwable $e) {
            $this->logger->error('SVM0003 Error occurred while trying to delete rescue vm', ['exception' => $e]);
        }
    }

    /**
     * Attempt to delete restore and log any error
     *
     * @param string $agentKey
     * @param DeviceLoggerInterface $logger
     */
    private function tryDeleteRestore(string $agentKey, DeviceLoggerInterface $logger): void
    {
        try {
            $this->logger->setAssetContext($agentKey);
            $this->virtRestoreTool->deleteRestore($agentKey, RestoreType::RESCUE);
        } catch (Throwable $e) {
            $logger->error('SVM0004 Error occurred while trying to delete active virt Restore', ['exception' => $e]);
        }
    }
}
