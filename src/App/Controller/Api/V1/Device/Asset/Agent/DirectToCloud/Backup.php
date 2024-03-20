<?php

namespace Datto\App\Controller\Api\V1\Device\Asset\Agent\DirectToCloud;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\DattoImageFactory;
use Datto\Asset\Agent\Windows\WindowsAgent;
use Datto\Asset\AssetType;
use Datto\Backup\BackupErrorContext;
use Datto\Backup\AgentBackupErrorResumableState;
use Datto\Backup\BackupManager;
use Datto\Backup\BackupManagerFactory;
use Datto\Backup\SnapshotStatusService;
use Datto\Backup\ResumableBackupStateService;
use Datto\Cloud\JsonRpcClient;
use Datto\Config\DeviceState;
use Datto\Feature\FeatureService;
use Datto\Log\LoggerAwareTrait;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Datto\Resource\DateTimeService;
use Datto\Service\Alert\AlertService;
use Datto\System\Transaction\TransactionException;
use Datto\Service\Backup\BackupQueueService;
use Exception;
use InvalidArgumentException;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class Backup implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const DEFAULT_EXPIRY = 5 * 60;
    const DEFAULT_BACKUP_FAILURE_CODE_STR = 'none_given';
    const DEFAULT_BACKUP_FAILURE_CODE = -1;
    const DEFAULT_BACKUP_FAILURE_REASON = '';
    const ALERT_ON_RESUMABLE_BACKUP_COUNT_THRESHOLD = 10;
    const ALERT_ON_RESUMABLE_BACKUP_TIME_THRESHOLD = 60 * 60 * 24; // One Day of seconds

    private FeatureService $featureService;
    private AgentService $agentService;
    private BackupManagerFactory $backupManagerFactory;
    private BackupQueueService $backupQueueService;
    private DateTimeService $dateTimeService;
    private Collector $collector;
    private DattoImageFactory $dattoImageFactory;
    private SnapshotStatusService $snapshotStatusService;
    private JsonRpcClient $devicewebClient;
    private AlertService $alertService;
    private ResumableBackupStateService $resumableBackupStateService;

    public function __construct(
        FeatureService $featureService,
        AgentService $agentService,
        BackupManagerFactory $backupManagerFactory,
        BackupQueueService $backupQueueService,
        DateTimeService $dateTimeService,
        Collector $collector,
        DattoImageFactory $dattoImageFactory,
        SnapshotStatusService $snapshotStatusService,
        JsonRpcClient $devicewebClient,
        AlertService $alertService,
        ResumableBackupStateService $resumableBackupStateService
    ) {
        $this->featureService = $featureService;
        $this->agentService = $agentService;
        $this->backupQueueService = $backupQueueService;
        $this->backupManagerFactory = $backupManagerFactory;
        $this->dateTimeService = $dateTimeService;
        $this->collector = $collector;
        $this->dattoImageFactory = $dattoImageFactory;
        $this->snapshotStatusService = $snapshotStatusService;
        $this->devicewebClient = $devicewebClient;
        $this->alertService = $alertService;
        $this->resumableBackupStateService = $resumableBackupStateService;
    }

    /**
     * Set the backup status for a dtc agent.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_DIRECT_TO_CLOUD_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "agentUuid" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     *
     * @param string $agentUuid
     * @param array $status Status of the backup.
     *      - $status['state'] = state of the backup, eg. 'active', 'idle'. This is a required field.
     *      - $status['startTime'] = start time of the backup. This is optional.
     *      - $status['metadata'] = metadata about the backup. This is optional.
     *      - $status['expiry'] = how long the status is valid (seconds). This is optional.
     *
     * Full example request:
     *
     *  {
     *      "agentUuid": "a4b9abe050454f5990633d3a0bda0457",
     *      "metadata": {
     *          "backupLifetimeStats": {
     *              "bytesTotal": 757813248,
     *              "bytesTransferred": 82620416
     *          },
     *          "backupPassStats": {
     *              "bytesTotal": 757813248,
     *              "bytesTransferred": 82620416
     *          },
     *          "backupRateInBitsPerSecond": 563610,
     *          "backupStatus": "Active",
     *          "backupUuid": "046a78ff-822d-4f04-b2c5-a00dc8f98d81",
     *          "state": "active",
     *          "timestamp": 1612537560,
     *          "volumeState": {
     *              "ecb94765-aa5b-11e5-a03b-00016cd2f2d6": {
     *                  "backupType": "incremental",
     *                  "volumeActivelyTransferring": true
     *              }
     *          },
     *          "errorData": {
     *              "errorCode": 1,
     *              "errorCodeStr": "error",
     *              "errorMsg": "message for error",
     *              "errorParams": {
     *                  "volumeUUID": "volumeUUID"
     *              }
     *          }
     *      },
     *      "status": {
     *          "expiry": 300,
     *          "state": "active"
     *      }
     *  }
     *
     * @param array $metadata
     * @return bool
     */
    public function setStatus(string $agentUuid, array $status, array $metadata): bool
    {
        $agent = $this->agentService->get($agentUuid); // for DTC agents, the agent's keyName is always the uuid
        $this->assertFeatureSupported($agent);

        $startTime = $status['startTime'] ?? $this->dateTimeService->getTime();
        $state = $this->getBackupStatusState($status);
        $expiry = $this->getBackupStatusExpiry($status);

        $this->processBackupStatusMetrics($agent, $status, $metadata);

        $manager = $this->getBackupManager($agent);
        $manager->setBackupStatus(
            $startTime,
            $state,
            [],
            $this->getBackupStatusBackupType($metadata),
            $expiry
        );

        // log error if errorData is populated
        if (!empty($metadata['errorData'])) {
            $manager->logBackupError(new BackupErrorContext(
                $metadata['errorData']['errorCode'] ?? self::DEFAULT_BACKUP_FAILURE_CODE,
                $metadata['errorData']['errorCodeStr'] ?? self::DEFAULT_BACKUP_FAILURE_CODE_STR,
                $metadata['errorData']['errorMsg'] ?? self::DEFAULT_BACKUP_FAILURE_REASON,
                $metadata['errorData']['backupResumable'] ?? false
            ));
        }

        $this->trySendBackupProgressToCloud($agent, $status, $metadata);

        return true;
    }

    /**
     * Prepares the agent to take a backup. If there is a failure during this process the endpoint will throw
     * an exception.
     *
     * @param string $agentUuid
     * @param array $metadata Metadata related to the backup. Currently, there are two fields that we rely on:
     *      - $metadata['hostInfo'] = json decoded "GET /host" response from the agent.
     *      - $metadata['backupInfo'] = json decoded "GET /backup/..." response from the agent's backup job.
     *
     *      Example params:
     *       {
     *           "agentUuid": "f047a9ea-cba7-4eb2-83c7-69b08a91aaab",
     *           "metadata": {
     *               "hostInfo": {
     *                   "agentVersion": "0.5.0.0",
     *                   "archBits": 64,
     *                   "busDriverVersion": "1.12.10.0",
     *                   "cpus": 4,
     *                   "filterDriverVersion": "1.12.10.0",
     *                   "freeram": 18398060544,
     *                   "fsfDriverVersion": "1.12.10.0",
     *                   "hostname": "w",
     *                   "os": "Windows 10",
     *                   "os_arch": "x86_64",
     *                   "os_version": {
     *                       "build": 17134,
     *                       "major": 10,
     *                       "minor": 0
     *                   },
     *                   "ram": 25769803776,
     *                   "redistributableVersion": "14.16.27012 (x64)",
     *                   "scriptsPrePost": [],
     *                   "uptime": 1782962,
     *                   "volumes": [
     *                       ...
     *                   ]
     *               },
     *               "backupInfo": {
     *                   "connection_error": false,
     *                   "details": [{
     *                           "status": "finished",
     *                           "transfer": {
     *                               "bytesTransferred": 4628480,
     *                               "totalSize": 7979008
     *                       },
     *                       "type": "incremental",
     *                       "volume": "44341bb5-b6dd-49d1-b387-a3dd5eef4263"
     *                   }],
     *                   "elapsedTime": 34,
     *                   "snapshot_error": false,
     *                   "status": "finished"
     *               }
     *           }
     *       }
     * @return array
     * @Datto\App\Security\RequiresFeature("FEATURE_DIRECT_TO_CLOUD_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_BACKUP")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "agentUuid" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     */
    public function prepare(string $agentUuid, array $metadata): array
    {
        // Prepare agent / mount zfs dataset.
        $agent = $this->agentService->get($agentUuid); // for DTC agents, the agent's keyName is always the uuid
        $this->assertFeatureSupported($agent);

        // prepare for backup
        $backupManager = $this->getBackupManager($agent);
        try {
            $backupManager->prepareBackup($metadata);
        } catch (TransactionException $e) {
            $this->collector->increment(Metrics::DTC_AGENT_BACKUP_PREPARE_FAIL, [
                'agent_version' => $agent->getDriver()->getAgentVersion()
            ]);

            throw $e->getPrevious() ?? $e;
        }

        $this->collector->increment(Metrics::DTC_AGENT_BACKUP_PREPARE_SUCCESS, [
            'agent_version' => $agent->getDriver()->getAgentVersion()
        ]);

        $includedVolumes = [];
        $dattoImages = $this->dattoImageFactory->createImagesForLiveDataset($agent);
        foreach ($dattoImages as $dattoImage) {
            $includedVolumes[] = [
                'guid' => $dattoImage->getVolume()->getGuid(),
                'backupFileLocation' => $dattoImage->getImageFilePath(),
                'checksumFileLocation' => $dattoImage->getChecksumFilePath(),
                'exclusionsFileLocation' => $dattoImage->getFileExlusionFilePath()
            ];
        }

        /** @var WindowsAgent $agent */
        $vssExclusions = [];
        if ($agent->isType(AssetType::WINDOWS_AGENT)) {
            $vssExclusions = $agent->getVssWriterSettings()->getExcludedIds();
        }

        return [
            'includedVolumes' => $includedVolumes,
            'vssExclusions' => $vssExclusions
        ];
    }

    /**
     * Take a snapshot of the dataset for a dtc agent.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_DIRECT_TO_CLOUD_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_BACKUP")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "agentUuid" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     *
     * @param string $agentUuid
     * @param array $metadata Metadata related to the backup. Currently, there are two fields that we rely on:
     *      - $metadata['hostInfo'] = json decoded "GET /host" response from the agent.
     *      - $metadata['backupInfo'] = json decoded "GET /backup/..." response from the agent's backup job.
     *
     *      Example params:
     *       {
     *           "agentUuid": "f047a9ea-cba7-4eb2-83c7-69b08a91aaab",
     *           "metadata": {
     *               "hostInfo": {
     *                   "agentVersion": "0.5.0.0",
     *                   "archBits": 64,
     *                   "busDriverVersion": "1.12.10.0",
     *                   "cpus": 4,
     *                   "filterDriverVersion": "1.12.10.0",
     *                   "freeram": 18398060544,
     *                   "fsfDriverVersion": "1.12.10.0",
     *                   "hostname": "w",
     *                   "os": "Windows 10",
     *                   "os_arch": "x86_64",
     *                   "os_version": {
     *                       "build": 17134,
     *                       "major": 10,
     *                       "minor": 0
     *                   },
     *                   "ram": 25769803776,
     *                   "redistributableVersion": "14.16.27012 (x64)",
     *                   "scriptsPrePost": [],
     *                   "uptime": 1782962,
     *                   "volumes": [
     *                       ...
     *                   ]
     *               },
     *               "backupInfo": {
     *                   "connection_error": false,
     *                   "details": [{
     *                           "status": "finished",
     *                           "transfer": {
     *                               "bytesTransferred": 4628480,
     *                               "totalSize": 7979008
     *                       },
     *                       "type": "incremental",
     *                       "volume": "44341bb5-b6dd-49d1-b387-a3dd5eef4263"
     *                   }],
     *                   "elapsedTime": 34,
     *                   "snapshot_error": false,
     *                   "status": "finished"
     *               }
     *           }
     *       }
     *
     *
     * @return array
     */
    public function takeSnapshot(string $agentUuid, array $metadata): array
    {
        $agent = $this->agentService->get($agentUuid); // for DTC agents, the agent's keyName is always the uuid
        $this->assertFeatureSupported($agent);

        $this->getBackupManager($agent)
            ->startScheduledBackup($metadata);
        $recoveryPoint = $this->agentService->get($agent->getKeyName())
            ->getLocal()
            ->getRecoveryPoints()
            ->getLast();

        return [
            'snapshotEpoch' => $recoveryPoint ? $recoveryPoint->getEpoch() : null
        ];
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_DIRECT_TO_CLOUD_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_BACKUP")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "agentUuid" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     *
     * @param string $agentUuid
     * @param array $metadata Metadata related to the backup. Currently, there are two fields that we rely on:
     *      - $metadata['hostInfo'] = json decoded "GET /host" response from the agent.
     *      - $metadata['backupInfo'] = json decoded "GET /backup/..." response from the agent's backup job.
     *
     *      Example params:
     *       {
     *           "agentUuid": "f047a9ea-cba7-4eb2-83c7-69b08a91aaab",
     *           "metadata": {
     *               "hostInfo": {
     *                   "agentVersion": "0.5.0.0",
     *                   "archBits": 64,
     *                   "busDriverVersion": "1.12.10.0",
     *                   "cpus": 4,
     *                   "filterDriverVersion": "1.12.10.0",
     *                   "freeram": 18398060544,
     *                   "fsfDriverVersion": "1.12.10.0",
     *                   "hostname": "w",
     *                   "os": "Windows 10",
     *                   "os_arch": "x86_64",
     *                   "os_version": {
     *                       "build": 17134,
     *                       "major": 10,
     *                       "minor": 0
     *                   },
     *                   "ram": 25769803776,
     *                   "redistributableVersion": "14.16.27012 (x64)",
     *                   "scriptsPrePost": [],
     *                   "uptime": 1782962,
     *                   "volumes": [
     *                       ...
     *                   ]
     *               },
     *               "backupInfo": {
     *                   "connection_error": false,
     *                   "details": [{
     *                           "status": "finished",
     *                           "transfer": {
     *                               "bytesTransferred": 4628480,
     *                               "totalSize": 7979008
     *                       },
     *                       "type": "incremental",
     *                       "volume": "44341bb5-b6dd-49d1-b387-a3dd5eef4263"
     *                   }],
     *                   "elapsedTime": 34,
     *                   "snapshot_error": false,
     *                   "status": "finished"
     *               }
     *           }
     *       }
     *
     * @return array
     */
    public function queueSnapshot(string $agentUuid, array $metadata): array
    {
        $agent = $this->agentService->get($agentUuid); // for DTC agents, the agent's keyName is always the uuid
        $this->assertFeatureSupported($agent);

        /**
         * Increase ZFS timeouts per BCDR-29635
         */
        $metadata['snapshotTimeout'] = 3600;

        $this->backupQueueService->queueBackupsForAssets([$agent], true, [$agentUuid => $metadata]);
        $snapshotstatus = $this->snapshotStatusService->getSnapshotStatus($agentUuid);

        if ($snapshotstatus->getState() === SnapshotStatusService::STATE_SNAPSHOT_NO_STATUS) {
            throw new Exception("Could not retreive status of queued snapshot");
        }

        return $snapshotstatus->jsonSerialize();
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_DIRECT_TO_CLOUD_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_READ")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_BACKUP")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "agentUuid" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     *
     * @param string $agentUuid
     * @param string $backupId
     *
     * @return array
     */
    public function snapshotStatus(string $agentUuid, string $backupId): array
    {
        $agent = $this->agentService->get($agentUuid); // for DTC agents, the agent's keyName is always the uuid
        $this->assertFeatureSupported($agent);

        $snapshotstatus = $this->snapshotStatusService->getSnapshotStatus($agentUuid);

        if ($snapshotstatus->getBackupId() !== $backupId) {
            throw new Exception("Status backupId did not match, expected {$snapshotstatus->getBackupId()} but got $backupId");
        }

        return $snapshotstatus->jsonSerialize();
    }

    /**
     * Record a notification from a dtc agent about a start of a backup
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_DIRECT_TO_CLOUD_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "agentUuid" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     *
     * @param string $agentUuid
     * @param string $backupUuid
     * @return bool
     */
    public function reportStarted(string $agentUuid, string $backupUuid): bool
    {
        $agent = $this->agentService->get($agentUuid); // for DTC agents, the agent's keyName is always the uuid
        $this->assertFeatureSupported($agent);

        if (empty($backupUuid)) {
            throw new InvalidArgumentException("Backupuuid is not a string");
        }

        $this->collector->increment(Metrics::DTC_AGENT_BACKUP_STARTED, [
            'agent_version' => $agent->getDriver()->getAgentVersion()
        ]);

        return true;
    }

    /**
     * Record a notification from a dtc agent about the end of a backup that succeeded
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_DIRECT_TO_CLOUD_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "agentUuid" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     *
     * @param string $agentUuid
     * @param string $backupUuid
     * @return bool
     */
    public function reportSuccess(string $agentUuid, string $backupUuid): bool
    {
        $agent = $this->agentService->get($agentUuid); // for DTC agents, the agent's keyName is always the uuid
        $this->assertFeatureSupported($agent);

        if (empty($backupUuid)) {
            throw new InvalidArgumentException("Backupuuid is not a string");
        }

        $this->collector->increment(Metrics::DTC_AGENT_BACKUP_SUCCESS, [
            'agent_version' => $agent->getDriver()->getAgentVersion()
        ]);

        $this->resumableBackupStateService->resetResumableBackupFailureStateForAgent($agentUuid);

        return true;
    }

    /**
     * Record a notification from a dtc agent about the end of a backup that failed
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_DIRECT_TO_CLOUD_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "agentUuid" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     *
     * @param string $agentUuid
     * @param string $backupUuid
     * @param array $metadata json decoded with error details
     *      - $metadata['message'] = error message of what went wrong.
     * @return bool
     *
     *  Example request:
     *   {
     *       "id":24095,
     *       "jsonrpc":"2.0",
     *       "method":"v1/device/asset/agent/directtocloud/backup/reportFailure",
     *       "params":{
     *           "agentUuid":"0f35efd7-084a-4e51-b4b8-ab7d6b132a5c",
     *           "backupUuid":"d574af88-da5f-4b70-99d2-2d155fa23c29",
     *           "metadata":{
     *               "message":"Exception caught during backup run: Cannot backup an inaccessible device!"
     *               "errorData": {
     *                  "errorCode": 1,
     *                  "errorCodeStr": "error",
     *                  "message": "message for error",
     *                  "backupResumable": false,
     *           }
     *       }
     *   }
     */
    public function reportFailure(string $agentUuid, string $backupUuid, array $metadata): bool
    {
        $agent = $this->agentService->get($agentUuid); // for DTC agents, the agent's keyName is always the uuid
        $this->assertFeatureSupported($agent);

        if (empty($backupUuid)) {
            throw new InvalidArgumentException("Backupuuid is not a string");
        }

        $backupErrorCode = $metadata['errorData']['errorCode'] ?? self::DEFAULT_BACKUP_FAILURE_CODE;
        $backupErrorCodeStr = $metadata['errorData']['errorCodeStr'] ?? self::DEFAULT_BACKUP_FAILURE_CODE_STR;
        $backupFailureReason = $metadata['errorData']['message'] ?? $metadata["message"] ?? self::DEFAULT_BACKUP_FAILURE_REASON;
        $backupResumable =  $metadata['errorData']['backupResumable'] ?? false;

        $manager = $this->getBackupManager($agent);
        $manager->logBackupError(
            new BackupErrorContext(
                $backupErrorCode,
                $backupErrorCodeStr,
                $backupFailureReason,
                $backupResumable
            ),
            true
        );

        $this->collector->increment(
            Metrics::DTC_AGENT_BACKUP_FAIL,
            [
                'agent_version' => $agent->getDriver()->getAgentVersion(),
                'failure_reason' => $backupErrorCodeStr,
                'backup_resumable' => $backupResumable
            ]
        );

        if (!$backupResumable) {
            $this->alertService->sendBackupFailedAlert($agentUuid, $backupFailureReason);
        } else {
            $agentUuidResumableBackupAttempts = $this->resumableBackupStateService->getResumableBackupFailureState($agentUuid);
            $retries = $agentUuidResumableBackupAttempts->getRetries() + 1;
            $lastNotification = $agentUuidResumableBackupAttempts->getLastNotificationTimestamp();
            $agentUuidResumableBackupAttempts->setRetries($retries);

            if ($retries >= self::ALERT_ON_RESUMABLE_BACKUP_COUNT_THRESHOLD) {
                if (!$lastNotification || $this->dateTimeService->getElapsedTime($lastNotification) > self::ALERT_ON_RESUMABLE_BACKUP_TIME_THRESHOLD) {
                    $agentUuidResumableBackupAttempts->setLastNotificationTimestamp($this->dateTimeService->getTime());
                    $this->alertService->sendBackupFailedAlert($agentUuid, $backupFailureReason);
                }
            }

            $this->resumableBackupStateService->saveResumableBackupFailureStateForAgent($agentUuidResumableBackupAttempts);
        }

        return true;
    }
 
    /**
     * @param Agent $agent
     */
    private function assertFeatureSupported(Agent $agent): void
    {
        $this->featureService->assertSupported(
            FeatureService::FEATURE_DIRECT_TO_CLOUD_AGENTS,
            null,
            $agent
        );
    }

    /**
     * @param Agent $agent
     * @return BackupManager
     */
    private function getBackupManager(Agent $agent): BackupManager
    {
        return $this->backupManagerFactory->create($agent);
    }

    private function getBackupStatusState(array $status): string
    {
        if (empty($status['state']) || !is_string($status['state'])) {
            throw new InvalidArgumentException("Backup status is missing 'state' field");
        }

        return $status['state'];
    }

    private function getBackupStatusExpiry(array $status): int
    {
        if (isset($status['expiry']) && !is_int($status['expiry'])) {
            throw new InvalidArgumentException("Backup status 'expiry' field is not an int");
        }

        return $status['expiry'] ?? self::DEFAULT_EXPIRY;
    }

    private function getBackupStatusBackupType(array $metadata): string
    {
        /** @var string $backupType */
        $backupType = null;
        if (array_key_exists('volumeState', $metadata)) {
            $volumeState = $metadata['volumeState'];
            $activeVolume = array_filter($volumeState, function (array $var) {
                $status = $var['volumeActivelyTransferring'] ?? null;
                return $status === true;
            });

            if (!empty($activeVolume)) {
                $firstElement = reset($activeVolume);
                $backupType = $firstElement['backupType'];
            }
        }

        return $backupType ?? '';
    }

    private function processBackupStatusMetrics(Agent $agent, array $status, array $metadata): void
    {
        $state = $this->getBackupStatusState($status);
        $backupStatus = $metadata['backupStatus'] ?? null;

        if (array_key_exists('lifetime', $metadata)) {
            $lifetimeStats = $metadata['lifetime'];

            // get the disk size from the agent
            $totalSize = 0;
            foreach ($agent->getVolumes() as $volume) {
                if ($volume->isOsVolume()) {
                    $totalSize += $volume->getSpaceTotal();
                }
            }

            if ($totalSize > 0) {
                if (array_key_exists('bytesTransferred', $lifetimeStats)) {
                    $transferRatio = floatval(($lifetimeStats['bytesTransferred'])) / $totalSize;

                    $this->collector->measure(Metrics::DTC_AGENT_TRANSFER_RATIO, $transferRatio, [
                        'agent_version' => $agent->getDriver()->getAgentVersion(),
                        'backup_status' => $backupStatus
                    ]);
                }

                if (array_key_exists('bytesTotal', $lifetimeStats)) {
                    $backupRatio = floatval(($lifetimeStats['bytesTotal'])) / $totalSize;

                    $this->collector->measure(Metrics::DTC_AGENT_BACKUP_RATIO, $backupRatio, [
                        'agent_version' => $agent->getDriver()->getAgentVersion(),
                        'backup_status' => $backupStatus
                    ]);
                }
            }
        }

        $this->collector->increment(Metrics::DTC_AGENT_BACKUP_SET_STATUS, [
            'agent_version' => $agent->getDriver()->getAgentVersion(),
            'state' => $state,
            'backup_status' => $backupStatus
        ]);
    }

    private function trySendBackupProgressToCloud(Agent $agent, array $status, array $metadata): void
    {
        $state = $this->getBackupStatusState($status);
        $progress = null;

        $hasProgress = $state === 'active' &&
            isset($metadata['backupLifetimeStats']['bytesTotal'], $metadata['backupLifetimeStats']['bytesTransferred']);
        if ($hasProgress) {
            $progress = [
                'bytesTransferred' => $metadata['backupLifetimeStats']['bytesTransferred'] ?? null,
                'bytesTotal' => $metadata['backupLifetimeStats']['bytesTotal'] ?? null
            ];
        }

        $parameters = [
            'assetUuid' => $agent->getKeyName(),
            'state' => $state,
            'progress' => $progress
        ];

        try {
            $this->logger->info('CCB0001 Sending agent backup progress to cloud', [
                'parameters' => $parameters
            ]);

            $this->devicewebClient->queryWithId('v1/device/asset/agent/backup/setStatus', $parameters);
        } catch (Throwable $e) {
            $this->logger->warning('CCB0002 Failed to send agent backup progress to cloud', [
                'parameters' => $parameters,
                'exceptionMessage' => $e->getMessage()
            ]);
        }
    }
}
