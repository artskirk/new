<?php

namespace Datto\Restore;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Backup\AgentSnapshotService;
use Datto\Asset\AssetService;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\Virtualization\VirtualDisksFactory;
use Datto\Connection\ConnectionType;
use Datto\Connection\Libvirt\AbstractLibvirtConnection;
use Datto\Connection\Libvirt\EsxConnection;
use Datto\Connection\Libvirt\HvConnection;
use Datto\Connection\Libvirt\KvmConnection;
use Datto\Connection\Service\ConnectionService;
use Datto\Log\DeviceLoggerInterface;
use Datto\Log\LoggerFactory;
use Datto\Restore\Virtualization\VirtualMachineService;
use Datto\Util\DateTimeZoneService;
use Datto\Virtualization\RemoteHypervisorStorageFactory;
use RuntimeException;

/**
 * @author Rixhers Ajazi <rajazi@datto.com>
 * @author Mark Blakley <mblakley@datto.com>
 */
class AgentVirtualizationRestore extends Restore
{
    private ConnectionService $connectionService;
    private AgentSnapshotService $agentSnapshotService;
    private RemoteHypervisorStorageFactory $remoteStorageFactory;
    private DeviceLoggerInterface $logger;
    private VirtualDisksFactory $virtualDisksFactory;
    private VirtualMachineService $virtualMachineService;

    public function __construct(
        string $assetKey,
        string $point,
        string $suffix,
        string $activationTime,
        array $options,
        string $html = null,
        AssetService $assetService = null,
        ProcessFactory $processFactory = null,
        ConnectionService $connectionService = null,
        AgentSnapshotService $agentSnapshotService = null,
        RemoteHypervisorStorageFactory $remoteStorageFactory = null,
        DeviceLoggerInterface $logger = null,
        DateTimeZoneService $dateTimeZoneService = null,
        VirtualDisksFactory $virtualDisksFactory = null,
        VirtualMachineService $virtualMachineService = null
    ) {
        $this->connectionService = $connectionService ?: new ConnectionService();
        $this->agentSnapshotService = $agentSnapshotService ?: new AgentSnapshotService();
        $this->remoteStorageFactory = $remoteStorageFactory ?: new RemoteHypervisorStorageFactory();
        $this->virtualDisksFactory = $virtualDisksFactory ?? new VirtualDisksFactory($this->agentSnapshotService);
        $this->virtualMachineService = $virtualMachineService ?? new VirtualMachineService();
        $this->logger = $logger ?: LoggerFactory::getAssetLogger($assetKey);
        parent::__construct($assetKey, $point, $suffix, $activationTime, $options, $html, $assetService, $processFactory, $dateTimeZoneService);
    }

    /**
     * Repair the restore
     */
    public function repair()
    {
        /** @var Agent $agent */
        $agent = $this->assetService->get($this->assetKey);

        $isEncrypted = $agent->getEncryption()->isEnabled();
        if (!$isEncrypted) {
            // Figure out if the connection name is "Local KVM" or something else
            $connectionName = $this->getOptions()['connectionName'];
            if (KvmConnection::CONNECTION_NAME !== $connectionName) {
                // If it's something else, it's a remote virt, so figure out if it's using iscsi
                // and if it is, then recreate the targets and loops
                $connection = $this->connectionService->get($connectionName);
                if (is_null($connection)) {
                    throw new RuntimeException("Cannot find hypervisor connection with name '$connectionName'");
                }

                if (ConnectionType::LIBVIRT_ESX() === $connection->getType()) {
                    /** @var EsxConnection $esxConnection */
                    $esxConnection = $connection;
                    // It's an esx connection, so could possibly use iscsi
                    if (EsxConnection::OFFLOAD_ISCSI === $esxConnection->getOffloadMethod()) {
                        $this->repairIscsiOffload($agent, $esxConnection);
                    }
                } elseif (ConnectionType::LIBVIRT_HV() === $connection->getType()) {
                    /** @var HvConnection $hvConnection */
                    $hvConnection = $connection;
                    // It's a hyper-v connection, so it does use iscsi
                    $this->repairIscsiOffload($agent, $hvConnection);
                }
            }

            // Rescue Agent boot state reconciliation is handled by datto-rescueagent-reboot.service
            // The code below will start up any active virtualizations that were running before the reboot,
            // but will not start up Rescue Agents that were running before the reboot
            if ($this->isOnBeforeReboot() && !$agent->isRescueAgent()) {
                $process = $this->processFactory
                    ->get(['snapctl', 'virtualization:start', $this->assetKey]);
                $process->run();

                if (!$process->isSuccessful()) {
                    $this->logger->error('AVR0002 Unable to start virtualization as part of repairing the restore', ['assetKey' => $this->assetKey, 'output' => $process->getOutput(), 'errorOutput' => $process->getErrorOutput(), 'exitCode' => $process->getExitCode()]);
                }
            }
        }
    }

    /**
     * @param bool $poweredOn
     */
    public function updateVmPoweredOnOption(bool $poweredOn)
    {
        $this->options['vmPoweredOn'] = $poweredOn;
    }

    /**
     * @return bool whether or not the VM was powered on before the reboot
     */
    private function isOnBeforeReboot()
    {
        $options = $this->getOptions();

        return $options['vmPoweredOn'];
    }

    /**
     * Recreates the iscsi target and loop backing stores for a Hyper-V or ESX iscsi offload
     *
     * @param Agent $agent
     * @param AbstractLibvirtConnection $connection
     */
    private function repairIscsiOffload(Agent $agent, AbstractLibvirtConnection $connection)
    {
        if ($agent->isRescueAgent()) {
            $cloneSpec = CloneSpec::fromRescueAgent($agent);
        } else {
            $cloneSpec = CloneSpec::fromAsset($agent, $this->getPoint(), RestoreType::ACTIVE_VIRT);
        }

        $vmVirtualDisks = $this->virtualDisksFactory->getVirtualDisks($cloneSpec);

        $remoteStorage = $this->remoteStorageFactory->create(
            $connection,
            $this->logger
        );
        $vmName = $this->virtualMachineService->generateVmName(
            $agent,
            $cloneSpec->getSuffix()
        );

        $this->logger->info('AVR0001 Attempting to re-offload agent to repair iSCSI targets', ['assetKey' => $this->assetKey]);
        // Recreate the targets and loops
        $remoteStorage->offload($vmName, $cloneSpec->getTargetMountpoint(), false, $vmVirtualDisks);
    }
}
