<?php

namespace Datto\App\Controller\Api\V1\Device\Asset;

use Datto\Asset\Agent\Agent as AgentObject;
use Datto\Asset\Agent\AgentConnectivityService;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Backup\AgentSnapshotService;
use Datto\Asset\Agent\DataProvider;
use Datto\Asset\Agent\DiffMergeService;
use Datto\Asset\Agent\DirectToCloud\ProtectedSystemConfigurationService;
use Datto\Asset\Agent\EncryptionService;
use Datto\Asset\Agent\ExtendedAgentService;
use Datto\Asset\Agent\RepairService;
use Datto\Asset\Agent\Rescue\RescueAgentService;
use Datto\Asset\Agent\Serializer\AgentSerializer;
use Datto\Asset\Agent\Windows\WindowsServiceRetriever;
use Datto\Asset\AssetRemovalService;
use Datto\Asset\AssetType;
use Datto\Backup\BackupManagerFactory;
use Datto\Cloud\SpeedSync;
use Datto\Config\AgentConfigFactory;
use Datto\Config\DeviceState;
use Datto\Log\AssetRecord;
use Datto\Replication\ReplicationDevices;
use Datto\Restore\Insight\InsightsService;
use Datto\Restore\RestoreService;
use Datto\Service\Backup\BackupQueueService;
use Datto\Utility\Screen;
use Datto\Utility\Security\SecretString;
use Exception;
use Throwable;

/**
 * API endpoint query and change agent settings
 *
 * Important note:
 *   This is an API endpoint, and NOT the actual library class.
 *   Do NOT add actual functionality to this class. Use library
 *   classes (and functions, if you must) instead!
 *
 * How to call this API?
 *   Please refer to the /api/api.php file and/or the
 *   Datto\API\Server class for details on how to call this API.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class Agent
{
    const SORT_BY_HOSTNAME = 'hostname';
    const SORT_BY_IP = 'ip';
    const SORT_BY_OS_NAME = 'osName';
    const SORT_BY_ALERTS = 'errorString';
    const SORT_BY_LAST_BACKUP = 'lastBackupEpoch';
    const SORT_BY_LOCAL_USED = 'localUsed';

    const SORT_BY_DEFAULT = self::SORT_BY_HOSTNAME;
    const PAGE_DEFAULT = 1;
    const PER_PAGE_DEFAULT = 99999;
    const ASCENDING_DEFAULT = true;

    const EXCLUDE_ARCHIVED = 'archived';

    private AgentService $agentService;

    private DataProvider $agentDataProvider;

    private RepairService $repairService;

    private AgentSerializer $agentSerializer;

    private AgentConnectivityService $agentConnectivityService;

    private DeviceState $deviceState;

    private WindowsServiceRetriever $windowsServiceRetriever;

    private ExtendedAgentService $extendedAgentService;

    private EncryptionService $encryptionService;

    private BackupManagerFactory $backupManagerFactory;

    private RescueAgentService $rescueAgentService;

    private Screen $screen;

    private AgentSnapshotService $agentSnapshotService;

    private AssetRemovalService $assetRemovalService;

    private ProtectedSystemConfigurationService $configurationService;

    private BackupQueueService $backupQueueService;

    private AgentConfigFactory $agentConfigFactory;

    private DiffMergeService $diffMergeService;

    private RestoreService $restoreService;

    private InsightsService $insightsService;

    public function __construct(
        AgentService $agentService,
        AgentSerializer $agentSerializer,
        RepairService $repairService,
        AgentConnectivityService $agentConnectivityService,
        DeviceState $deviceState,
        WindowsServiceRetriever $windowsServiceRetriever,
        ExtendedAgentService $extendedAgentService,
        EncryptionService $encryptionService,
        BackupManagerFactory $backupManagerFactory,
        RescueAgentService $rescueAgentService,
        Screen $screen,
        AgentSnapshotService $agentSnapshotService,
        AssetRemovalService $assetRemovalService,
        ProtectedSystemConfigurationService $configurationService,
        BackupQueueService $backupQueueService,
        AgentConfigFactory $agentConfigFactory,
        RestoreService $restoreService,
        InsightsService $insightsService,
        DiffMergeService $diffMergeService,
        DataProvider $agentDataProvider
    ) {
        $this->agentService = $agentService;
        $this->agentSerializer = $agentSerializer;
        $this->repairService = $repairService;
        $this->agentConnectivityService = $agentConnectivityService;
        $this->deviceState = $deviceState;
        $this->windowsServiceRetriever = $windowsServiceRetriever;
        $this->extendedAgentService = $extendedAgentService;
        $this->encryptionService = $encryptionService;
        $this->backupManagerFactory = $backupManagerFactory;
        $this->rescueAgentService = $rescueAgentService;
        $this->screen = $screen;
        $this->agentSnapshotService = $agentSnapshotService;
        $this->assetRemovalService = $assetRemovalService;
        $this->configurationService = $configurationService;
        $this->backupQueueService = $backupQueueService;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->diffMergeService = $diffMergeService;
        $this->restoreService = $restoreService;
        $this->insightsService = $insightsService;
        $this->agentDataProvider = $agentDataProvider;
    }

    /**
     * Return detailed information for a specific agent.
     *
     * By default, this endpoint will return all known fields of an agent.
     * If $fields is non-empty, it is interpreted as a filter for agent keys.
     *
     * To return all agent information:
     *   $agent = $endpoint->get('agent1');
     *
     * To return only name, hostname and volume information:
     *   $agent = $endpoint->get('agent1', array('hostname', 'name', 'volumes'));
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Datto\App\Security\Constraints\AssetExists()
     * })
     * @param string $agentName Name of the agent
     * @param array $fields List of fields to include in the return array
     * @return array
     */
    public function get($agentName, array $fields = [])
    {
        $agent = $this->agentService->get($agentName);

        // General agent information
        $serializedAgent = $this->agentSerializer->serialize($agent);
        // With BCDR-20015 the volumes key is removed, so it should now always be loaded from the last snap
        try {
            $serializedAgent['volumes'] = $this->getVolumesFromLatestSnap($agent);
        } catch (Throwable $e) {
            // Replicated datasets can be deleted out from under us by speedsync so we can't rely on it existing
        }
        $filteredSerializedAgent = $this->filter($serializedAgent, $fields);
        $restores = $this->agentDataProvider->getRestores($agentName);
        $comparisons = $this->agentDataProvider->getComparison($agent);
        $filteredSerializedAgent['restores'] = $restores;
        $filteredSerializedAgent['comparisons'] = $comparisons;
        // Extended agent information
        if ($this->shouldAdd('extended', $fields)) {
            $extended = $this->extendedAgentService->getExtended($agent);

            $filteredSerializedAgent['extended'] = [
                "agentless" => $extended->isAgentless(),
                "alertsSuppressed" => $extended->isAlertsSuppressed(),
                "hasScripts" => $extended->isHasScripts(),
                "ipAddress" => $extended->getIpAddress(),
                "archived" => $extended->isArchived(),
                "removing" => $extended->isRemoving(), //TODO: deprecated?
                "lastBackup" => $extended->getLastBackup(),
                "lastScreenshot" => $extended->getLastScreenshot(),
                "logs" => AssetRecord::manyToArray($extended->getLogs()),
                "locked" => $extended->isLocked(),
                "protectedSize" => $extended->getProtectedSize(),
                "protectedVolumes" => $extended->getProtectedVolumes(),
                "screenshotSuccess" => $extended->isScreenshotSuccess(),
                "scriptFailure" => $extended->isScriptFailure(),
                "showScreenshots" => $extended->isShowScreenshots(),
                "osUpdatePending" => $extended->isOsUpdatePending(),
                "diskDrives" => $extended->getDiskDrives()
            ];
        }

        // Rescue agent information
        if ($this->shouldAdd('rescue', $fields)) {
            $rescueState = $this->rescueAgentService->getVirtualMachineState($agent);
            $rescueAgentSettings = $agent->getRescueAgentSettings();
            $filteredSerializedAgent['rescue'] = [
                'running' => $rescueState->isRunning(),
                'poweredOn' => $rescueState->isPoweredOn(),
                'snapshot' => $rescueState->getSnapshot(),
                'sourceAgentKeyName' => $rescueAgentSettings ? $rescueAgentSettings->getSourceAgentKeyName() : null
            ];
        }

        // Backup information
        if ($this->shouldAdd('backup', $fields)) {
            $backup = $this->backupManagerFactory->create($agent)->getInfo();
            $filteredSerializedAgent['backup'] = [
                'status' => [
                    'state' => $backup->getStatus()->getState(),
                    'additional' => $backup->getStatus()->getAdditional()
                ],
                'queued' => $backup->isQueued(),
                'doDiffMerge' => $this->diffMergeService->getDiffMergeSettings($agent->getKeyName())->isAnyVolume()
            ];
        }

        // Alerting information
        if ($this->shouldAdd('alerts', $fields)) {
            $filteredSerializedAgent['alerts'] = $this->extendedAgentService->getAlerts($agentName);
        }

        // Replication information
        if ($this->shouldAdd('replication', $fields)) {
            $filteredSerializedAgent['replication'] = [
                'deviceId' => $agent->getOriginDevice()->getDeviceId(),
                'resellerId' => $agent->getOriginDevice()->getResellerId(),
                'isReplicated' => $agent->getOriginDevice()->isReplicated(),
                'isOrphaned' => $agent->getOriginDevice()->isOrphaned()
            ];

            if ($agent->getOriginDevice()->isReplicated()) {
                $replicationDevices = ReplicationDevices::createInboundReplicationDevices();
                $this->deviceState->loadRecord($replicationDevices);
                $inboundDevice = $replicationDevices->getDevice($agent->getOriginDevice()->getDeviceId());
                //note that /var/lib/datto/device/inboundDevices will not exist until the first time the reconcile runs
                if ($inboundDevice) {
                    $filteredSerializedAgent['replication']['sourceDevice'] = $inboundDevice->toArray();
                }
            }
        }

        if ($this->shouldAdd('removal', $fields)) {
            $status = $this->assetRemovalService->getAssetRemovalStatus($agentName);
            $filteredSerializedAgent['removal'] = [
                'status' => $status->toArray()
            ];
        }

        if ($this->shouldAdd('retarget', $fields)) {
            // For DTC agents, this synthetic field determines if the agent should cancel a currently running backup
            // and return to mothership.

            $filteredSerializedAgent['retarget'] = $agent->getLocal()->isMigrationExpedited()
                || $agent->getOriginDevice()->isReplicated();
        }

        if (SpeedSync::isPeerReplicationTarget($agent->getOffsiteTarget())) {
            $replicationDevices = ReplicationDevices::createOutboundReplicationDevices();
            $this->deviceState->loadRecord($replicationDevices);

            $targetDevice = $replicationDevices->getDevice($agent->getOffsiteTarget());
            if ($targetDevice) {
                $filteredSerializedAgent['offsite']['targetDevice'] = $targetDevice->toArray();
            }
        }

        if ($this->shouldAdd('directToCloud', $fields)) {
            $request = $this->configurationService->getRequest($agent);
            $filteredSerializedAgent['directToCloud'] = [
                'setProtectedSystemAgentConfigRequest' => $request
            ];
        }

        return $filteredSerializedAgent;
    }

    /**
     * Get a list of all agent names on this device
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_READ")
     * @return string[] List of agent names
     */
    public function getAllNames()
    {
        $result = [];
        $agents = $this->agentService->getAll();

        foreach ($agents as $agent) {
            $result[] = $agent->getName();
        }

        return $result;
    }

    /**
     * Get a list of all agents on this device
     *
     * By default, this endpoint will get all agents and return all known fields.
     * If $fields is non-empty, it is interpreted as a filter for agent keys.
     *
     * To return all agent information:
     *   $agents = $endpoint->getAll();
     *
     * To return only name, hostname and volume information of all agents:
     *   $agents = $endpoint->getAll(array('hostname', 'name', 'volumes'));
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_READ")
     * @param array $fields List of fields to include in the return array
     * @param bool $excludeArchived Exclude archived agents
     * @return array JSON encoded array of agent data
     */
    public function getAll(array $fields = [], bool $excludeArchived = false)
    {
        $result = [];
        if ($excludeArchived) {
            $agentKeyNames = $this->agentService->getAllActiveKeyNames();
        } else {
            $agentKeyNames = $this->agentService->getAllKeyNames();
        }

        foreach ($agentKeyNames as $keyName) {
            try {
                $agentFields = $this->get($keyName, $fields);
                $result[] = $agentFields;
            } catch (Throwable $e) {
                // We still want to return the rest of the agents even if one of them doesn't work
            }
        }

        return $result;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_READ")
     * @param int|null $page
     * @param int|null $perPage
     * @param string|null $sortBy
     * @param bool|null $ascending
     * @param string[] $excludes A list of strings denoting which types of agents to exclude (eg. 'archived').
     * @param string[] $fields A list of strings denoting the agent fields to include.
     * @param string|null $search Hostname, IP, or FQDN (partial match works)
     * @return array
     */
    public function getAllFiltered(
        int $page = null,
        int $perPage = null,
        string $sortBy = null,
        bool $ascending = null,
        array $excludes = [],
        array $fields = [],
        string $search = null
    ): array {
        $page = $page ?? self::PAGE_DEFAULT;
        $perPage = $perPage ?? self::PER_PAGE_DEFAULT;
        $sortBy = $sortBy ?? self::SORT_BY_DEFAULT;
        $ascending = $ascending ?? self::ASCENDING_DEFAULT;
        $excludeArchived = in_array(self::EXCLUDE_ARCHIVED, $excludes, true);

        // To improve performance, we call getAllKeyNames() here instead of getAll()
        $allAgentKeyNames = $this->agentService->getAllKeyNames();
        $totalUnfiltered = count($allAgentKeyNames);

        $filteredAgentKeyNames = [];
        $totalUnfilteredArchived = 0;
        foreach ($allAgentKeyNames as $agentKeyName) {
            $agentConfig = $this->agentConfigFactory->create($agentKeyName);
            if ($agentConfig->isArchived()) {
                $totalUnfilteredArchived++;
                if ($excludeArchived) {
                    continue;
                }
            }
            if ($search === null || $search === '' || $agentConfig->searchAgentInfo(['hostname', 'fqdn'], $search)) {
                $filteredAgentKeyNames[] = $agentKeyName;
            }
        }

        $total = count($filteredAgentKeyNames);

        $sortedKeyNames = $this->fastSort($filteredAgentKeyNames, $sortBy, $ascending);
        $filteredAgentKeyNames = $this->paginate($sortedKeyNames, $page, $perPage);

        $serializedAgents = [];
        foreach ($filteredAgentKeyNames as $agentKeyName) {
            try {
                $agentFields = $this->get($agentKeyName, $fields);
                $serializedAgents[] = $agentFields;
            } catch (Throwable $e) {
                // We still want to return the rest of the agents even if one of them doesn't work
            }
        }

        return [
            'page' => $page,
            'pages' => ceil($total / $perPage),
            'total' => $total,
            'agents' => $serializedAgents,
            'totalUnfiltered' => $totalUnfiltered,
            'totalUnfilteredArchived' => $totalUnfilteredArchived
        ];
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_READ")
     * @param bool $local
     * @param int|null $page
     * @param int|null $perPage
     * @param string|null $sortBy
     * @param bool|null $ascending
     * @param string[] $excludes A list of strings denoting which types of agents to exclude (eg. 'archived').
     * @param string[] $fields A list of strings denoting the agent fields to include.
     * @return array
     */
    public function getAllReplicatedFiltered(
        bool $local,
        int $page = null,
        int $perPage = null,
        string $sortBy = null,
        bool $ascending = null,
        array $excludes = [],
        array $fields = []
    ): array {
        $page = $page ?? self::PAGE_DEFAULT;
        $perPage = $perPage ?? self::PER_PAGE_DEFAULT;
        $info = $this->getAllFiltered(
            $page,
            $perPage,
            $sortBy,
            $ascending,
            $excludes,
            $fields
        );

        $serializedAgents = array_filter($info['agents'], function ($var) use ($local) {
            $x = $var['replication']['isReplicated'] === !$local;
            return $x;
        });

        $total = count($serializedAgents);

        $serializedAgents = $this->paginate($serializedAgents, $page, $perPage);

        return [
            'page' => $info['page'],
            'pages' => ceil($total / $perPage),
            'total' => $total,
            'agents' => $serializedAgents
        ];
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENT_BACKUPS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_BACKUP")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "assetKey" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     *
     * @param string $assetKey
     * @return bool
     */
    public function cancelQueuedBackup(string $assetKey): bool
    {
        return $this->backupQueueService->clearQueuedBackup($assetKey);
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_BACKUP")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "assetKey" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     *
     * @param string $assetKey
     * @return bool True if the backup has started, otherwise false
     */
    public function startBackup(string $assetKey): bool
    {
        $screenName = "forceStartBackup-$assetKey";
        if ($this->screen->isScreenRunning($screenName)) {
            return true;
        }

        return $this->screen->runInBackground(['snapctl', 'asset:backup:start', $assetKey], $screenName);
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENT_BACKUPS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_BACKUP")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "assetKey" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     *
     * @param string $assetKey
     */
    public function stopBackup(string $assetKey): void
    {
        $agent = $this->agentService->get($assetKey);
        $backupManager = $this->backupManagerFactory->create($agent);
        $backupManager->cancelBackup();
    }

    /**
     * Repair agent communications
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Datto\App\Security\Constraints\AssetExists()
     * })
     * @param string $agentName Name of the agent
     * @return array
     */
    public function repair($agentName)
    {
        return $this->repairService->repair($agentName);
    }

    /**
     * Retarget an agent from its old target to a new fqdn
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Datto\App\Security\Constraints\AssetExists(),
     *   "domainName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $agentName Current/old agent target
     * @param string $domainName New agent target
     */
    public function retarget($agentName, $domainName): void
    {
        $this->agentConnectivityService->retargetAgent($agentName, $domainName);
    }

    /**
     * Attempts to unseal an agent using the given passphrase.
     *
     * Optionally attempts to unseal any other
     * agents which share the same passphrase.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_UNSEAL")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "assetKey" = @Datto\App\Security\Constraints\AssetExists()
     * })
     * @param string $assetKey
     * @param string $passphrase
     * @param bool $enableBulkUnseal
     */
    public function unseal(string $assetKey, string $passphrase, bool $enableBulkUnseal = false): void
    {
        $passphrase = new SecretString($passphrase);
        $this->encryptionService->decryptAgentKey($assetKey, $passphrase);

        if ($enableBulkUnseal) {
            $otherAgents = $this->agentService->getAll();
            foreach ($otherAgents as $otherAgent) {
                if (!$this->encryptionService->isEncrypted($otherAgent->getKeyName())) {
                    continue;
                }
                if ($this->encryptionService->isAgentMasterKeyLoaded($otherAgent->getKeyName())) {
                    continue;
                }
                try {
                    // Unless all of the agents on this device use the same passphrase,
                    // we're going to trigger a lot of invalid password attempts doing this.
                    // So do not audit log any of these attempts, as that could needlessly set off alarm bells.
                    $auditable = false;
                    $this->encryptionService->decryptAgentKey($otherAgent->getKeyName(), $passphrase, $auditable);
                } catch (\Exception $ex) {
                    continue;
                }
            }
        }
    }

    /**
     * Refreshes the running services cache and returns the updated running services.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentKey" = @Datto\App\Security\Constraints\AssetExists()
     * })
     * @param string $agentKey
     * @return string[][] Services running currently on the agent.
     */
    public function refreshRunningServices(string $agentKey): array
    {
        $agent = $this->agentService->get($agentKey);
        if (!$agent->isType(AssetType::WINDOWS_AGENT) && !$agent->isType(AssetType::AGENTLESS_WINDOWS)) {
            throw new \Exception("$agentKey is not a Windows agent-based or agentless system");
        }

        $runningServices = $this->windowsServiceRetriever->refreshCachedRunningServices($agentKey);

        $result = [];
        foreach ($runningServices as $service) {
            $result[] = [
                'displayName' => $service->getDisplayName(),
                'serviceName' => $service->getServiceName(),
                'id' => $service->getId()
            ];
        }

        return [
            'runningServices' => $result,
            'backupRequired' => $this->windowsServiceRetriever->isBackupRequired($agentKey)
        ];
    }

    /**
     * Reduces a full agent array to the list of keys given in
     * the $fields array.
     *
     * @param array $serializedAgent
     * @param string[] $fields
     * @return array
     */
    private function filter(array $serializedAgent, array $fields): array
    {
        $hasFilter = !empty($fields);

        if ($hasFilter) {
            $filteredAgent = [];

            foreach ($serializedAgent as $field => $value) {
                if (in_array($field, $fields)) {
                    $filteredAgent[$field] = $value;
                }
            }

            return $filteredAgent;
        } else {
            return $serializedAgent;
        }
    }

    /**
     * Fast sort a list of agent key names.
     * Fast sort works by reading only the files necessary to sort by the passed field for each agent (such as
     * 'agentInfo' or 'recoveryPoints') so it's not necessary to read and deserialize the entire agent object.
     *
     * @param string[] $agentKeyNames
     * @param string $sortBy
     * @param bool $ascending
     * @return string[]|null Sorted key names or null if can't be fast sorted
     */
    private function fastSort(array $agentKeyNames, string $sortBy, bool $ascending)
    {
        $agentFields = [];
        foreach ($agentKeyNames as $agentKeyName) {
            $agentConfig = $this->agentConfigFactory->create($agentKeyName);
            switch ($sortBy) {
                case self::SORT_BY_HOSTNAME:
                    $value = strtolower($agentConfig->getAgentInfo()['hostname'] ?? '');
                    break;
                case self::SORT_BY_IP:
                    $value = strtolower($agentConfig->getAgentInfo()['fqdn'] ?? '');
                    break;
                case self::SORT_BY_OS_NAME:
                    $value = strtolower($agentConfig->getAgentInfo()['os_name'] ?? '');
                    break;
                case self::SORT_BY_LOCAL_USED:
                    $value = $agentConfig->getAgentInfo()['localUsed'] ?? '';
                    break;
                case self::SORT_BY_LAST_BACKUP:
                    $recoveryPoints = explode("\n", $agentConfig->getRaw('recoveryPoints', ''));
                    $value = end($recoveryPoints);
                    break;
                case self::SORT_BY_ALERTS:
                    $value = $this->extendedAgentService->getAlerts($agentKeyName);
                    break;
                default:
                    throw new Exception('Specified sortBy method is not supported: ' . $sortBy);
            }
            $agentFields[$agentKeyName] = $value;
        }

        if ($ascending) {
            asort($agentFields);
        } else {
            arsort($agentFields);
        }

        return array_keys($agentFields);
    }

    /**
     * Handle pagination of serialized agents.
     *
     * @param array $serializedAgents
     * @param int $page
     * @param int $perPage
     * @return array
     */
    private function paginate(array $serializedAgents, int $page, int $perPage): array
    {
        // Trim array based on page and per page
        $serializedAgents = array_slice($serializedAgents, ($page - 1) * $perPage, $perPage);

        return $serializedAgents;
    }

    /**
     * Get volumes from latest snapshot
     *
     * @param AgentObject $agent
     * @return array
     */
    private function getVolumesFromLatestSnap(AgentObject $agent): array
    {
        $lastSnap = $agent->getLocal()->getRecoveryPoints()->getLast();
        return $lastSnap ? $this->agentSnapshotService->get(
            $agent->getKeyName(),
            $lastSnap->getEpoch()
        )->getVolumes()->toArray() : [];
    }

    /**
     * Check if we should add a field to a serialized agent.
     *
     * @param string $field
     * @param string[] $fields
     * @return bool
     */
    private function shouldAdd(string $field, array $fields): bool
    {
        return empty($fields) || in_array($field, $fields);
    }
}
