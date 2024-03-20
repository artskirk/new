<?php

namespace Datto\Backup;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Backup\AgentSnapshotRepository;
use Datto\Asset\Agent\Backup\AgentSnapshotService;
use Datto\Asset\Agent\Windows\WindowsAgent;
use Datto\Connection\Libvirt\EsxConnection;
use Datto\Connection\Service\EsxConnectionService;
use Datto\Common\Resource\CurlRequest;
use Datto\Common\Resource\Sleep;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Datto\Util\RetryHandler;
use Datto\Virtualization\VmwareApiClient;
use GuzzleHttp\Client;
use Datto\Log\DeviceLoggerInterface;
use Vmwarephp\Vhost;

/**
 * Service class responsible for dealing with VM configuration files.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class VmConfigurationBackupService
{
    const LIVE_DATASET_VMX_PATH_FORMAT = '/home/agents/%s/' . AgentSnapshotRepository::KEY_VMX_FILE_NAME;

    /** @var Filesystem */
    private $filesystem;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var EsxConnectionService */
    private $esxConnectionService;

    /** @var VmwareApiClient */
    private $vmwareApiClient;

    /** @var AgentSnapshotService */
    private $agentSnapshotService;

    /**
     * @param Filesystem $filesystem
     * @param DeviceLoggerInterface $logger
     * @param AgentSnapshotService $agentSnapshotService
     * @param EsxConnectionService $esxConnectionService
     * @param VmwareApiClient $vmwareApiClient
     */
    public function __construct(
        Filesystem $filesystem,
        DeviceLoggerInterface $logger,
        AgentSnapshotService $agentSnapshotService,
        EsxConnectionService $esxConnectionService,
        VmwareApiClient $vmwareApiClient
    ) {
        $this->filesystem = $filesystem;
        $this->logger = $logger;
        $this->agentSnapshotService = $agentSnapshotService;
        $this->esxConnectionService = $esxConnectionService;
        $this->vmwareApiClient = $vmwareApiClient;
    }

    /**
     * @param Agent $agent
     * @param string $vmxContent
     */
    public function saveVmConfigurationVmx(Agent $agent, string $vmxContent)
    {
        $vmxPath = sprintf(self::LIVE_DATASET_VMX_PATH_FORMAT, $agent->getKeyName());

        $this->logger->debug("BAK0020 Updating VMX file: $vmxPath");
        $this->filesystem->filePutContents($vmxPath, $vmxContent);
        $this->filesystem->chmod($vmxPath, 0644);
    }

    /**
     * @param Agent $agent
     * @param int $point
     * @return bool
     */
    public function hasVmConfiguration(Agent $agent, int $point): bool
    {
        return $this->getVmConfigurationPath($agent, $point) !== false;
    }

    /**
     * @param Agent $agent
     * @param int $point
     * @return string|false
     */
    public function getVmConfigurationPath(Agent $agent, int $point)
    {
        $snapshot = $this->agentSnapshotService->get($agent->getKeyName(), $point);
        $info = $snapshot->getKeyInfo(AgentSnapshotRepository::KEY_VMX_FILE_NAME);

        return $info->getRealPath();
    }

    /**
     * @param Agent $agent
     * @param string $biosSerialNumber
     */
    public function retrieveAndSaveVmxFromVm(Agent $agent, string $biosSerialNumber)
    {
        $this->logger->info('BAK1020 Backing up VM VMX file.');

        if (!$biosSerialNumber) {
            throw new \Exception("Cannot backup VMX file, necessary system ids are missing.");
        }

        $cachedConnectionName = null;
        if ($agent instanceof WindowsAgent && $agent->getVmxBackupSettings()->getConnectionName()) {
            $cachedConnectionName = $agent->getVmxBackupSettings()->getConnectionName();
            $this->logger->debug('BAK1024 Looking up VM using cached HV connection: ' . $cachedConnectionName);

            try {
                $cachedConnection = $this->esxConnectionService->get($cachedConnectionName);
                $this->attemptRetrieveAndSaveUsingEsxConnection($agent, $cachedConnection, $biosSerialNumber);
                return;
            } catch (\Throwable $throwable) {
                $this->logger->warning(
                    'BAK1025 Unable to retrieve VMX using cached connection',
                    ['connectionName' => $cachedConnectionName, 'exception' => $throwable]
                );
            }
        }

        foreach ($this->esxConnectionService->getAll() as $esxConnection) {
            if ($esxConnection->getName() === $cachedConnectionName) {
                continue;
            }

            $this->logger->debug('BAK1021 Looking up VM using HV connection: ' . $esxConnection->getName());

            try {
                $this->attemptRetrieveAndSaveUsingEsxConnection($agent, $esxConnection, $biosSerialNumber);
                return;
            } catch (\Throwable $throwable) {
                $this->logger->warning(
                    'BAK1030 Unable to retrieve vmx using connection',
                    ['connection' => $esxConnection->getName(), 'exception' => $throwable]
                );
            }
        }

        throw new \Exception("Couldn't find VM on any hypervisor connection. VMX backup not possible.");
    }

    /**
     * @param Agent $agent
     * @param EsxConnection $esxConnection
     * @param string $biosSerialNumber
     */
    private function attemptRetrieveAndSaveUsingEsxConnection(
        Agent $agent,
        EsxConnection $esxConnection,
        string $biosSerialNumber
    ) {
        try {
            $vHost = $esxConnection->getEsxApi()->getVhost();
            $vmMoRef = $this->attemptFindVmOnVhost($vHost, $biosSerialNumber);
        } catch (\Throwable $exception) {
            $exception = $exception->getPrevious() ?? $exception;

            throw new \Exception("Couldn't use HV connection: " . $exception->getMessage());
        }

        if (!$vmMoRef) {
            throw new \Exception('No matching VM found on esx connection');
        }

        $this->logger->debug('BAK1022 Found VM reference: ' . $vmMoRef);
        $vmxContent = $this->vmwareApiClient->retrieveVirtualMachineVmx($vHost, $vmMoRef);
        $this->saveVmConfigurationVmx($agent, $vmxContent);
        $this->updateSavedHypervisorConnection($agent, $esxConnection->getName());
    }

    /**
     * @param Agent $agent
     * @param string $connectionName
     */
    private function updateSavedHypervisorConnection(Agent $agent, string $connectionName)
    {
        if ($agent instanceof WindowsAgent) {
            $agent->getVmxBackupSettings()->setConnectionName($connectionName);
        }
    }

    /**
     * @param Vhost $vHost
     * @param string $biosSerialNumber
     * @return string
     */
    private function attemptFindVmOnVhost(Vhost $vHost, string $biosSerialNumber): string
    {
        $vmMoRef = '';

        try {
            $vmMoRef = $this->vmwareApiClient->findVmMoRefIdByBiosSerialNumber($vHost, $biosSerialNumber);
        } catch (\Throwable $exception) {
            $this->logger->warning('BAK1018 Error finding VM by serial number', ['exception' => $exception]);
        }

        return $vmMoRef;
    }
}
