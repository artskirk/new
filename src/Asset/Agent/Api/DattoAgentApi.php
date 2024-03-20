<?php

namespace Datto\Asset\Agent\Api;

use Datto\AppKernel;
use Datto\Asset\Agent\Certificate\CertificateSetStore;
use Datto\Asset\Agent\Job\BackupJobStatus;
use Datto\Asset\Agent\Job\BackupJobVolumeDetails;
use Datto\Asset\Agent\PairingDeniedException;
use Datto\Asset\Agent\PairingFailedException;
use Datto\Backup\File\BackupImageFile;
use Datto\Cloud\JsonRpcClient;
use Datto\Config\DeviceConfig;
use Datto\Core\Network\DeviceAddress;
use Datto\Mercury\MercuryFtpService;
use Datto\Mercury\MercuryFTPTLSService;
use Datto\Restore\PushFile\PushFileRestoreContext;
use Datto\Restore\PushFile\PushFileRestoreStatus;
use Datto\Util\ArraySanitizer;
use Datto\Util\RetryHandler;
use Exception;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * Base class to interface with Datto Agent APIs.
 *
 * This class should not rely on Agent or AgentConfig because the api is usable before pairing.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
abstract class DattoAgentApi extends BaseAgentApi
{
    /** Placeholder agent port. This should be overridden in derived classes */
    const AGENT_PORT = 0;

    /** Regex to check if `/getBackupStatus` returns backup does not exist. */
    const BACKUP_NOT_EXIST_REGEX = '/A backup with ID [a-zA-Z0-9-]{36} does not exist/';

    /**  Agent feature name to test if datto agent can accept asset keyname and IP for agent checkin  */
    const AGENT_FEATURE_SET_KEY_NAME_AND_IP = 'siris_asset_id';

    /**  Agent feature name to test if datto agent can accept push file restore requests  */
    const AGENT_FEATURE_PUSH_FILE_RESTORE = 'push_file_restore';

    protected DeviceConfig $deviceConfig;
    private DeviceAddress $deviceAddress;
    private ArraySanitizer $arraySanitizer;
    private MercuryFTPTLSService $mercuryFTPTLSService;

    public function __construct(
        string $agentFqdn,
        DeviceLoggerInterface $logger,
        DeviceConfig $deviceConfig = null,
        RetryHandler $retryHandler = null,
        AgentRequest $agentRequest = null,
        DeviceAddress $deviceAddress = null,
        ArraySanitizer $arraySanitizer = null,
        JsonRpcClient $cloudClient = null,
        CertificateSetStore $certificateSetStore = null,
        MercuryFTPTLSService $mercuryFTPTLSService = null
    ) {
        $this->deviceConfig = $deviceConfig ?? new DeviceConfig();
        $this->deviceAddress = $deviceAddress ??
            AppKernel::getBootedInstance()->getContainer()->get(DeviceAddress::class);
        $this->arraySanitizer = $arraySanitizer ?? new ArraySanitizer();
        $this->mercuryFTPTLSService = $mercuryFTPTLSService ??
            AppKernel::getBootedInstance()->getContainer()->get(MercuryFTPTLSService::class);

        parent::__construct($agentFqdn, $logger, $agentRequest, $cloudClient, $certificateSetStore, $retryHandler);
    }

    public function initialize()
    {
        $certificateSets = $this->certificateSetStore->getCertificateSets();
        $this->agentRequest->includeCertificateSet($certificateSets);

        // The following two lines fix our SSL version at TLSv1.1, and allow the use of the
        // associated ciphers. This is required to restore the behavior present in the 16.04
        // device, and restores communication with several old agent/OS combinations, but it
        // prevents negotiation to TLSv1.2 or TLSv1.3 until these older agents are fixed or
        // deprecated and these lines can be removed (See https://kaseya.atlassian.net/browse/BCDR-21539)
        $this->agentRequest->setSslVersion(CURL_SSLVERSION_TLSv1_1 | CURL_SSLVERSION_MAX_TLSv1_1);
        $this->agentRequest->setSslCipherList('DEFAULT@SECLEVEL=1');
    }

    public function cleanup()
    {
        // Empty cleanup by default. Override if needed.
    }

    public function pairAgent(string $agentKeyName = null)
    {
        $parameters = ['deviceID' => (int)$this->deviceConfig->get('deviceID')];
        $response = $this->agentRequest->post('pair', json_encode($parameters));

        if (isset($response['getPairTicket'])) {
            $oldDeviceId = $response['getPairTicket']['oldDevice'] ?? '';
            $ticket = $this->requestPairingTicket($oldDeviceId);

            try {
                $pairResponse = $this->sendAgentPairTicket($ticket);
            } catch (Exception $e) {
                $this->logger->error('DAA5018 Agent pair ticket request failed', ['exception' => $e]);
                throw new PairingFailedException();
            }

            if (!isset($pairResponse['success'])) {
                $this->logger->error('DAA5014 Error re-pairing, agent sent invalid pair ticket response', ['oldDeviceId' => $oldDeviceId]);
                throw new PairingFailedException();
            }

            if (!$pairResponse['success']) {
                $message = $pairResponse['error'] ?? 'error message not found';
                $this->logger->error('DAA5015 Error re-pairing, agent rejected request', ['oldDeviceId' => $oldDeviceId, 'error' => $message]);
                throw new PairingDeniedException();
            }
        }

        $this->logger->debug('DAA5016 Pairing request accepted by agent');
    }

    public function startPushFileRestore(PushFileRestoreContext $pushFileRestoreContext): array
    {
        try {
            $requestParams = $this->getPushFileRestoreRequestParams($pushFileRestoreContext);

            $sanitizedRequestParams = $this->arraySanitizer->sanitizeParams($requestParams);
            $this->logger->debug(
                'DAA1020 Datto Agent API request: Start push file restore',
                ['requestParams' => $sanitizedRequestParams]
            );

            $jsonEncodedRequestParams = json_encode($requestParams);
            $response = $this->agentRequest->post('v2/restore/file', $jsonEncodedRequestParams);
            if ($response) {
                $this->logger->debug(
                    'DAA1021 Datto Agent API startPushFileRestore response received',
                    ['response' => $response]
                );
                return $response;
            }

            throw new Exception('Did not receive a response from the agent to the push file restore request.');
        } catch (Throwable $e) {
            $this->logger->error('DAA1025 Datto Agent API startPushFileRestore request failed', ['exception' => $e]);
            throw $e;
        }
    }

    public function cancelPushFileRestore(string $restoreID): ?array
    {
        try {
            $this->logger->debug(
                'DAA1022 Datto Agent API request: Cancel push file restore',
                ['restoreID' => $restoreID]
            );
            $response = $this->agentRequest->delete("v2/restore/file/$restoreID");

            if ($response) {
                return $response;
            }

            throw new Exception('Did not receive a response from the agent to the push file restore request.');
        } catch (Throwable $e) {
            $this->logger->error('DAA1023 Datto Agent API cancelPushFileRestore request failed', ['exception' => $e]);
            throw $e;
        }
    }

    public function getPushFileRestoreStatus(string $restoreID): PushFileRestoreStatus
    {
        try {
            $response = $this->retryHandler->executeAllowRetry(
                function () use ($restoreID) {
                    return $this->agentRequest->get("v2/restore/file/$restoreID");
                },
                AgentApi::RETRIES,
                AgentApi::RETRY_WAIT_TIME_SECONDS
            );

            if (is_null($response)) {
                throw new Exception('Did not receive a response to the status request');
            }

            $pushFileRestoreStatus = new PushFileRestoreStatus();
            $pushFileRestoreStatus->setRestoreID($restoreID);
            self::processPushFileRestoreStatus($pushFileRestoreStatus, $response);
            return $pushFileRestoreStatus;
        } catch (Throwable $e) {
            $this->logger->error(
                'DAA1024 Datto Agent API pushFileRestoreStatus request failed',
                ['exception' => $e, 'response' => $response ?? '']
            );

            throw $e;
        }
    }

    public function startBackup(BackupApiContext $backupContext)
    {
        $requestParams = $this->getBackupRequestParams($backupContext);
        $backupTransportParams = $backupContext->getBackupTransport()->getApiParameters();
        $requestParams = array_merge($requestParams, $backupTransportParams);

        $sanitizedRequestParams = $this->arraySanitizer->sanitizeParams($requestParams);
        $this->logger->debug(
            'DAA1001 Datto Agent API request: Start backup',
            ['requestParams' => $sanitizedRequestParams]
        );

        $jsonEncodedRequestParams = json_encode($requestParams);
        $response = $this->agentRequest->post('backup', $jsonEncodedRequestParams, false);
        if ($response) {
            return trim($response);
        }

        return null;
    }

    public function cancelBackup(string $jobID)
    {
        try {
            $this->logger->debug(
                'DAA1011 Datto Agent API request: Cancel backup',
                ['jobID' => $jobID]
            );
            $response = $this->agentRequest->delete("backup/$jobID");

            if ($response) {
                return $response;
            }
        } catch (Throwable $e) {
            $this->logger->error('DAA1006 Datto Agent API jobCancel request failed', ['exception' => $e]);
            return null;
        }

        return null;
    }

    public function updateBackupStatus(string $jobID, BackupJobStatus $backupJobStatus = null)
    {
        try {
            $response = $this->retryHandler->executeAllowRetry(
                function () use ($jobID) {
                    if (empty($jobID)) {
                        return $this->agentRequest->get("backup");
                    }
                    return $this->agentRequest->get("backup/$jobID");
                },
                AgentApi::RETRIES,
                AgentApi::RETRY_WAIT_TIME_SECONDS
            );

            if ($response) {
                if (empty($jobID)) {
                    return $response;
                }
                $backupJobStatus = $backupJobStatus ?: new BackupJobStatus();
                self::processBackupStatus($backupJobStatus, $response);
                $backupJobStatus->setJobID($jobID);
                return $backupJobStatus;
            }
        } catch (Throwable $e) {
            $this->logger->error('DAA1004 Datto Agent API jobStatus request failed', ['exception' => $e]);

            // CP-15536: Cancel backup if agent reports backup transaction no longer exists, such as on restart.
            // In this scenario, the agent reports back a Bad Request (400).
            if ($e->getPrevious() instanceof AgentApiException) {
                /** @var AgentApiException $apiException */
                $apiException = $e->getPrevious();
                $errorMatch = $apiException->getHttpCode() === 400 &&
                    preg_match(self::BACKUP_NOT_EXIST_REGEX, $apiException->getMessage());
                if ($errorMatch) {
                    $backupJobStatus = new BackupJobStatus();
                    $backupJobStatus->setTransferResult(AgentTransferResult::FAILURE_BAD_REQUEST());
                    $backupJobStatus->setTransferState(AgentTransferState::FAILED());
                    return $backupJobStatus;
                }
            }

            throw $e;
        }

        return null;
    }

    public function getHost()
    {
        try {
            $response = $this->retryHandler->executeAllowRetry(
                function () {
                    $this->logger->debug('DAA1014 Datto Agent API request: Get host');
                    return $this->agentRequest->get('host');
                },
                AgentApi::RETRIES,
                AgentApi::RETRY_WAIT_TIME_SECONDS
            );

            return $response;
        } catch (Throwable $e) {
            $this->logger->error('DAA1002 Datto Agent API host request failed', ['exception' => $e]);
            throw $e;
        }
    }

    public function getBasicHost()
    {
        try {
            $this->logger->debug('DAA1015 Datto Agent API request: Get basichost');
            $response = $this->agentRequest->get('basichost');

            if ($response) {
                return $response;
            }
        } catch (Throwable $e) {
            $this->logger->error('DAA1012 Datto Agent API basichost request failed', ['exception' => $e]);
        }

        return false;
    }

    public function getAgentLogs(int $severity = self::DEFAULT_LOG_SEVERITY, ?int $limit = self::DEFAULT_LOG_LINES)
    {
        try {
            $response = $this->agentRequest->get('event', ['severity' => $severity]);
            if ($limit !== null) {
                $response['log'] = array_splice($response['log'], -$limit, $limit, true);
            }

            if ($response) {
                return $response;
            }
        } catch (Throwable $e) {
            $this->logger->error('DAA1007 Datto Agent API logs request failed', ['exception' => $e]);
            return null;
        }

        return null;
    }

    /**
     * This base class does not implement this function.
     */
    public function runCommand(
        string $command,
        array $commandArguments = [],
        string $directory = null
    ) {
        throw new AgentApiException('Method not implemented!');
    }

    public function needsReboot(): bool
    {
        try {
            $response = $this->agentRequest->get('v2/install');
        } catch (Throwable $e) {
            // This is a new agent endpoint and most do not support it yet. Since this is called frequently,
            // we do not log failures to avoid spamming the logs.
        }

        return isset($response['rebootRequired']) && $response['rebootRequired'] === true;
    }

    public function wantsReboot(): bool
    {
        try {
            $response = $this->agentRequest->get('v2/install');
        } catch (Throwable $e) {
            // This is a new agent endpoint and most do not support it yet. Since this is called frequently,
            // we do not log failures to avoid spamming the logs.
        }

        return isset($response['rebootRecommended']) && $response['rebootRecommended'] === true;
    }

    /**
     * Send the agent a pairing ticket issued by device-web.
     * Pairing tickets are used to securely notify the agent that this device is authorized to pair with the agent.
     * They originate from device web and are sent, securely, to the agent via the device.
     *
     * @param array $ticket
     * @return mixed
     */
    public function sendAgentPairTicket(array $ticket)
    {
        $ticketData = ['ticket' => $ticket];
        $response = $this->agentRequest->post('agentPairTicket', json_encode($ticketData));
        return $response;
    }

    /**
     * Create a backup status object from a agent backup response array.
     *
     * @param array $response
     * @return BackupJobStatus
     */
    public static function getBackupStatusFromResponse(array $response): BackupJobStatus
    {
        $status = new BackupJobStatus();
        self::processBackupStatus($status, $response);
        return $status;
    }

    /**
     * Send parameters to agent that are required for agent checkin
     *
     * @param string $ip
     * @param string $uuid
     */
    public function sendAgentCheckinParams(string $ip, string $uuid): void
    {
        if ($this->featureExists(self::AGENT_FEATURE_SET_KEY_NAME_AND_IP)) {
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $this->sendIpToAgent($ip);
            }
            $this->sendUuidToAgent($uuid);
        }
    }

    /**
     * Check if the agent supports specific features.
     *
     * @param string $feature The feature to check. Ex: "siris_asset_id"
     * @return bool True if the agent supports the feature. Otherwise false
     */
    public function featureExists(string $feature): bool
    {
        try {
            $features = $this->agentRequest->get('v2/feature');
            if (is_array($features)) {
                foreach ($features as $existingFeature) {
                    if ($existingFeature['name'] === $feature) {
                        return true;
                    }
                }
            }
        } catch (Throwable $e) {
            // This is a new agent endpoint and most do not support it yet. Since this is called frequently,
            // we do not log failures to avoid spamming the logs.
        }
        return false;
    }

    /**
     * Send ip to agent. Required for agent checkin
     * @param string $ip
     */
    public function sendIpToAgent(string $ip): void
    {
        try {
            $this->agentRequest->post('v2/siris/localIPAddress', json_encode(['ipAddress' => $ip]));
        } catch (Throwable $e) {
            $this->logger->error('ABA1020 Datto Agent API post localIPAddress failed', ['exception' => $e]);
            throw $e;
        }
    }

    /**
     * Send uuid to agent. Required for agent checkin
     * @param string $uuid
     */
    public function sendUuidToAgent(string $uuid): void
    {
        try {
            $this->agentRequest->post('v2/siris/assetID', json_encode(['assetID' => $uuid]));
        } catch (Throwable $e) {
            $this->logger->error('ABA1022 Datto Agent API post assetID failed error: ', ['exception' => $e]);
            throw $e;
        }
    }

    protected function getPushFileRestoreRequestParams(PushFileRestoreContext $pushFileRestoreContext): array
    {
        $destinationIp = $this->getDestinationIP($pushFileRestoreContext->getHostOverride());
        $verifyCertificate = false;
        $destinationHost = $this->mercuryFTPTLSService->getMercuryFtpHost($destinationIp, $verifyCertificate);
        $requestParams = [
            'transport' => [
                'target' => $destinationHost . ':' . MercuryFtpService::MERCURYFTP_TRANSFER_PORT,
                'qualifiedName' => $pushFileRestoreContext->getTargetInfo()->getName(),
                'lun' => $pushFileRestoreContext->getLun(),
                'username' => '',
                'password' => $pushFileRestoreContext->getTargetInfo()->getPassword(),
                'checksum' => $pushFileRestoreContext->getChecksum(),
                'verifyCertificate' => $verifyCertificate,
            ],
            'restoreType' => $pushFileRestoreContext->getPushFileRestoreType()->value(),
            'size' => $pushFileRestoreContext->getSize(),
            'decompressedSize' => $pushFileRestoreContext->getDecompressedSize(),
            'fileExistsBehavior' => $pushFileRestoreContext->getKeepBoth() ? 'keep-both-copies' : 'skip',
            'restoreACLs' => $pushFileRestoreContext->getRestoreAcls(),
        ];
        if (strlen($pushFileRestoreContext->getDestination()) > 0) {
            $requestParams['destination'] = $pushFileRestoreContext->getDestination();
        }

        return $requestParams;
    }

    /**
     * Get the backup request parameters
     *
     * @param BackupApiContext $backupContext
     * @return array
     */
    protected function getBackupRequestParams(BackupApiContext $backupContext): array
    {
        $requestParams = [
            "waitBetweenVols" => false,
            "forceDiffMerge" => $backupContext->isForceDiffMerge(),
            "writeSize" => 0,
            "cacheWrites" => $backupContext->isForceCopyFull(),
            "volumes" => $this->getVolumeArray($backupContext)
        ];

        return $requestParams;
    }

    /**
     * Translate the volume information into the agent api request format.
     *
     * @param BackupApiContext $backupContext
     * @return array
     */
    protected function getVolumeArray(BackupApiContext $backupContext): array
    {
        $backupTransport = $backupContext->getBackupTransport();
        $volumeParameters = $backupTransport->getVolumeParameters();
        $destinationIp = $this->getDestinationIP($backupContext->getHostOverride());
        $verifyCertificate = false;
        $destinationHost = $backupTransport->getDestinationHost($destinationIp, $verifyCertificate);
        $qualifiedName = $backupTransport->getQualifiedName();
        $port = $backupTransport->getPort();
        $quiescingScripts = $backupContext->getQuiescingScripts();
        $forceDiffMergeVolumeGuids = $backupContext->getForceDiffMergeVolumeGuids();
        $baseSectorOffset = $backupContext->getBackupImageFile()->getBaseSectorOffset();
        $offset = $baseSectorOffset * BackupImageFile::SECTOR_SIZE_IN_BYTES;
        $volumes = [];
        foreach ($volumeParameters as $guid => $parameters) {
            $lunId = $parameters['lun'];
            $checksumLunId = $parameters['lunChecksum'];
            $quiescingScriptsForVolume = $quiescingScripts[$guid] ?? [];
            $forceDiffMerge = in_array($guid, $forceDiffMergeVolumeGuids);
            $volumes[] = [
                "guid" => $guid,
                "offset" => $offset,
                "qualifiedName" => $qualifiedName,
                "target" => $destinationHost . ":" . $port,
                "lun" => $lunId,
                "username" => $parameters['username'] ?? '',
                "password" => $parameters['password'] ?? '',
                "scriptsPrePost" => $quiescingScriptsForVolume,
                "lunChecksum" => $checksumLunId,
                "forceDiffMerge" => $forceDiffMerge,
                "verifyCertificate" => $verifyCertificate
            ];
        }

        return $volumes;
    }

    private function getDestinationIP(string $hostOverride): string
    {
        if ($hostOverride) {
            return $hostOverride;
        }
        return $this->deviceAddress->getLocalIp($this->agentFqdn);
    }

    /**
     * Process the response from the backup status request.
     *
     * @param BackupJobStatus $backupJobStatus
     * @param array $response
     */
    private static function processBackupStatus(BackupJobStatus $backupJobStatus, array $response): void
    {
        switch ($response['status']) {
            case "active":
                self::processActiveStatus($backupJobStatus, $response);
                break;

            case "failed":
                self::processFailedStatus($backupJobStatus, $response);
                break;

            case "finished":
                self::processCompletedStatus($backupJobStatus, $response);
                break;

            default:
                throw new AgentApiException('Invalid response from agent!');
        }
    }

    private static function processPushFileRestoreStatus(
        PushFileRestoreStatus $pushFileRestoreStatus,
        array $response
    ): void {
        switch ($response['status']) {
            case 'active':
                $pushFileRestoreStatus->setStatus(AgentTransferState::ACTIVE());
                break;

            case 'failed':
                $pushFileRestoreStatus->setStatus(AgentTransferState::FAILED());
                $pushFileRestoreStatus->setErrorCode($response['error']['errorCode']);
                $pushFileRestoreStatus->setErrorCodeStr($response['error']['errorCodeStr']);
                $pushFileRestoreStatus->setErrorMsg($response['error']['errorMsg']);
                break;

            case 'finished':
                $pushFileRestoreStatus->setStatus(AgentTransferState::COMPLETE());
                break;

            default:
                throw new Exception('Invalid status received for push file restore!');
        }
        $pushFileRestoreStatus->setBytesTransferred($response['bytesTransferred']);
        $pushFileRestoreStatus->setTotalSize($response['totalSize']);
    }

    /**
     * Process the active status response from the backup status request.
     * Update the transfer state and transfer amounts.
     *
     * @param BackupJobStatus $backupJobStatus
     * @param array $response
     */
    private static function processActiveStatus(BackupJobStatus $backupJobStatus, array $response): void
    {
        $backupJobStatus->setTransferState(AgentTransferState::ACTIVE());
        self::updateVolumeDetails($backupJobStatus, $response);
        $backupJobStatus->setTransferResult(AgentTransferResult::NONE());
    }

    /**
     * Process the failed status response from the backup status request.
     * Update the transfer state and set the transfer result.
     *
     * @param BackupJobStatus $backupJobStatus
     * @param array $response
     */
    private static function processFailedStatus(BackupJobStatus $backupJobStatus, array $response): void
    {
        $backupJobStatus->setTransferState(AgentTransferState::FAILED());

        $connectionError = $response['connection_error'] ?? false;
        $snapshotError = $response['snapshot_error'] ?? false;
        $bothErrors = $connectionError && $snapshotError;
        if ($bothErrors) {
            $transferResult = AgentTransferResult::FAILURE_BOTH();
        } elseif ($connectionError) {
            $transferResult = AgentTransferResult::FAILURE_CONNECTION();
        } elseif ($snapshotError) {
            $transferResult = AgentTransferResult::FAILURE_SNAPSHOT();
        } else {
            $transferResult = AgentTransferResult::FAILURE_UNKNOWN();
        }
        self::updateVolumeDetails($backupJobStatus, $response);
        $backupJobStatus->setTransferResult($transferResult);

        $errorData = $response['errorData'] ?? [];
        if (!empty($errorData)) {
            $errorCode = $errorData['errorCode'] ?? 0;
            $errorCodeStr = $errorData['errorCodeStr'] ?? '';
            $errorMsg = $errorData['errorMsg'] ?? '';
            $backupJobStatus->setErrorData($errorCode, $errorCodeStr, $errorMsg);
        }
    }

    /**
     * Process the completed status response from the backup status request.
     * Update the transfer state and set the transfer result.
     *
     * @param BackupJobStatus $backupJobStatus
     * @param array $response
     */
    private static function processCompletedStatus(BackupJobStatus $backupJobStatus, array $response): void
    {
        $backupJobStatus->setTransferState(AgentTransferState::COMPLETE());
        self::updateVolumeDetails($backupJobStatus, $response);
        $backupJobStatus->setTransferResult(AgentTransferResult::SUCCESS());
    }

    private static function updateVolumeDetails(BackupJobStatus $backupJobStatus, array $response): void
    {
        $sent = 0;
        $total = 0;
        $lastUpdateTime = null;
        $volumeGuids = [];
        $volumeBackupTypes = [];

        if ($response['details'] && is_array($response['details'])) {
            foreach ($response['details'] as $volume) {
                $volumeGuid = $volume['volume'];
                $volumeGuids[] = $volumeGuid;
                $bytesSent = $volume['transfer']['bytesTransferred'] ?? 0;
                $bytesTotal = $volume['transfer']['totalSize'] ?? 0;
                $sent += $bytesSent;
                $total += $bytesTotal;
                $volumeType = null;
                if (!empty($volume['type'])) {
                    $volumeType = strtolower($volume['type']);
                    $volumeBackupTypes[$volumeGuid] = $volumeType;
                }

                $volumeDetails = new BackupJobVolumeDetails(
                    null,
                    $volume['status'],
                    $volumeGuid,
                    null,
                    $volumeType,
                    null,
                    $bytesTotal,
                    $bytesSent,
                    null,
                    null,
                    null
                );
                $backupJobStatus->setVolumeDetails($volumeDetails);
            }
        }

        $backupJobStatus->setVolumeGuids($volumeGuids);
        $backupJobStatus->updateAmountsSent($sent, $total);
        $backupJobStatus->setVolumeBackupTypes($volumeBackupTypes);
    }
}
