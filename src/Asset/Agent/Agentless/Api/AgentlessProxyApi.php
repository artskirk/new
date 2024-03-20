<?php

namespace Datto\Asset\Agent\Agentless\Api;

use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\Api\AgentApiException;
use Datto\Asset\Agent\Api\BackupApiContext;
use Datto\Asset\Agent\Api\DattoAgentApi;
use Datto\Connection\Libvirt\EsxConnection;
use Datto\Resource\DateTimeService;
use Datto\Common\Resource\Sleep;
use Datto\Log\DeviceLoggerInterface;

/**
 * Interfaces with the Agentless Proxy API.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class AgentlessProxyApi extends DattoAgentApi
{
    private const DEFAULT_PROXY_FQDN = '127.0.0.1';
    public const URL_FORMAT = "http://%s:%d/api/v1/agentless/esx/";
    public const AGENT_PORT = 80;

    private const API_TIMEOUT = 600;
    private const API_INITIALIZE_STATUS_READY = 'ready';
    private const API_INITIALIZE_STATUS_FAILED = 'failed';
    private const API_INITIALIZE_STATUS_UNKNOWN = 'unknown';
    private const API_INITIALIZE_TIMEOUT_IN_SECS = 7200;

    private const API_CLEANUP_STATUS_CLEANED_UP = 'cleaned_up';
    private const API_CLEANUP_TIMEOUT_IN_SECS = 7200;

    private ?string $agentlessSessionId;

    private string $vmMoRefId;
    private AgentPlatform $agentPlatform;
    private string $keyName;
    private bool $forceNbd;
    private bool $isFullDiskBackup;
    private EsxConnection $esxConnection;
    private ?string $agentlessProxyUser;
    private ?string $agentlessProxyPassword;
    private Sleep $sleep;
    private DateTimeService $dateTimeService;

    public function __construct(
        string $vmMoRefId,
        EsxConnection $esxConnection,
        AgentPlatform $agentPlatform,
        string $keyName,
        DeviceLoggerInterface $logger,
        bool $forceNbd = false,
        bool $isFullDiskBackup = false,
        string $agentlessProxyFqdn = self::DEFAULT_PROXY_FQDN,
        ?string $agentlessProxyUser = null,
        ?string $agentlessProxyPassword = null,
        Sleep $sleep = null,
        DateTimeService $dateTimeService = null
    ) {
        parent::__construct($agentlessProxyFqdn, $logger);

        $this->vmMoRefId = $vmMoRefId;
        $this->esxConnection = $esxConnection;
        $this->agentPlatform = $agentPlatform;
        $this->keyName = $keyName;
        $this->forceNbd = $forceNbd;
        $this->isFullDiskBackup = $isFullDiskBackup;
        $this->agentlessProxyUser = $agentlessProxyUser;
        $this->agentlessProxyPassword = $agentlessProxyPassword;
        $this->sleep = $sleep ?: new Sleep();
        $this->dateTimeService = $dateTimeService ?: new DateTimeService();
        $this->agentlessSessionId = null;
    }

    public function getPlatform(): AgentPlatform
    {
        // We have to use a variable for this unlike other Api classes because AGENTLESS and
        // AGENTLESS_GENERIC both use the same api class.
        return $this->agentPlatform;
    }

    public function isInitialized(): bool
    {
        return $this->agentlessSessionId !== null;
    }

    public function initialize(): void
    {
        if ($this->isInitialized()) {
            return;
        }

        $this->agentlessProxyUser = $this->agentlessProxyUser ?: "secretKey";
        $this->agentlessProxyPassword = $this->agentlessProxyPassword ?: $this->deviceConfig->get('secretKey');

        $this->agentRequest->includeBasicAuthorization($this->agentlessProxyUser, $this->agentlessProxyPassword);
        $this->agentRequest->setTimeout(self::API_TIMEOUT);

        $params = json_encode([
            'host' => $this->esxConnection->getPrimaryHost(),
            'user' => $this->esxConnection->getUser(),
            'password' => $this->esxConnection->getPassword(),
            'vmName' => $this->vmMoRefId,
            'agentlessKeyName' => $this->keyName,
            'forceNbd' => $this->forceNbd,
            'fullDiskBackup' => $this->isFullDiskBackup
        ], JSON_PRETTY_PRINT);
        $response = $this->agentRequest->post(
            'sessions',
            $params,
            false
        );

        if (!is_string($response)) {
            $this->logger->error('LES0008 Invalid response from API request to /sessions');
            throw new \Exception("Invalid response from API request to /sessions");
        }

        $this->agentlessSessionId = trim($response);
        $this->agentRequest->includeHeader("X-AGENTLESS-SESSION-ID", $this->agentlessSessionId);

        $this->monitorInitializationStatus($this->agentlessSessionId);
    }

    public function cleanup(): void
    {
        $this->logger->setAssetContext($this->keyName);
        if ($this->agentlessSessionId !== null) {
            $this->logger->setAgentlessSessionContext($this->agentlessSessionId);
            $this->logger->info("LES0002 Cleaning up agentless session...");
            $this->agentRequest->delete("sessions/{$this->agentlessSessionId}");
            $this->monitorCleanupStatus($this->agentlessSessionId);
            $this->agentlessSessionId = null;
        } else {
            $this->logger->info("LES0003 Agentless session already cleaned up.");
        }
    }

    public function needsReboot(): bool
    {
        return false;
    }

    public function wantsReboot(): bool
    {
        return false;
    }

    public function pairAgent(string $agentKeyName = null)
    {
        throw new AgentApiException('Method not implemented!');
    }

    public function sendAgentPairTicket(array $ticket)
    {
        throw new AgentApiException('Method not implemented!');
    }

    public function getAgentLogs(int $severity = 3, int $limit = null)
    {
        throw new AgentApiException('Method not implemented yet!');
    }

    public function getBasicHost()
    {
        throw new AgentApiException('Method not implemented yet!');
    }

    protected function getVolumeArray(BackupApiContext $backupContext): array
    {
        $volumesParameters = $backupContext->getBackupTransport()->getVolumeParameters();

        return $volumesParameters;
    }

    /**
     * Monitor initializing status with a 2 hour timeout
     */
    private function monitorInitializationStatus(string $agentlessSessionId): void
    {
        $this->logger->setAgentlessSessionContext($agentlessSessionId);
        $this->logger->setAssetContext($this->keyName);

        $ready = false;
        $prevContext = [];
        $startTime = $this->dateTimeService->getTime();

        while (!$ready) {
            if (($this->dateTimeService->getTime() - $startTime) > self::API_INITIALIZE_TIMEOUT_IN_SECS) {
                throw new \Exception("Timeout occurred during AgentlessProxyApi initialization.");
            }

            $response = $this->agentRequest->get('sessions/' . $agentlessSessionId);
            $status = $response['status'] ?? self::API_INITIALIZE_STATUS_UNKNOWN;
            $statusDetail = $response['status_detail'] ?? '';
            $vmdksMounted = $response['vmdks_mounted'] ?? false;
            $hostVersion = $response['hostVersion'] ?? '';
            $vmdks = $response['vmdks'] ?? [];
            $error = $response['error'] ?? '';
            $bypassingManagementServer = $response['bypassingManagementServer'] ?? false;

            $context = [
                'agentlessSessionId' => $this->agentlessSessionId,
                'status' => $status,
                'statusDetail' => $statusDetail,
                'hostVersion' => $hostVersion,
                'vddkMounted' => ($vmdksMounted ? 'yes' : 'no'),
                'error' => $error
            ];

            if ($context !== $prevContext) {
                $this->logger->info('LES0004 Initializing agentless api', $context);
                $prevContext = $context;
            }
            if ($vmdks) {
                $this->logger->info('LES0005 VMDKs', ['vmdks' => $vmdks]);
            }

            if ($status === self::API_INITIALIZE_STATUS_FAILED) {
                throw new \Exception("Api initialization failed. Error: $error");
            }

            if ($status === self::API_INITIALIZE_STATUS_READY) {
                if ($bypassingManagementServer) {
                    $this->logger->warning('LES0007 Warning: You are bypassing vCenter server, this is not recommended or supported!');
                }
                $this->logger->info("LES0006 Api ready.");
                $ready = true;
            } else {
                $this->sleep->sleep(1);
            }
        }
    }

    /**
     * Monitor cleanup status with a 2 hour timeout window
     */
    private function monitorCleanupStatus(string $agentlessSessionId): void
    {
        $isCleanedUp = false;
        $startTime = $this->dateTimeService->getTime();

        while (!$isCleanedUp) {
            if (($this->dateTimeService->getTime() - $startTime) > self::API_CLEANUP_TIMEOUT_IN_SECS) {
                throw new \Exception("Timeout occurred during AgentlessProxyApi cleanup.");
            }

            $response = $this->agentRequest->get('sessions/' . $agentlessSessionId);
            $status = $response['status'] ?? '';

            if ($status === self::API_CLEANUP_STATUS_CLEANED_UP) {
                $isCleanedUp = true;
            }

            $this->sleep->sleep(1);
        }
    }

    public function needsOsUpdate(): bool
    {
        return false;
    }

    public function isAgentVersionSupported(): bool
    {
        return true;
    }
}
