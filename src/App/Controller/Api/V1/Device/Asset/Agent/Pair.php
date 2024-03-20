<?php

namespace Datto\App\Controller\Api\V1\Device\Asset\Agent;

use Datto\Agentless\EsxVirtualMachineManager;
use Datto\Asset\Agent\AgentConnectivityService;
use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\AgentService as NewAgentService;
use Datto\Asset\Agent\Api\AgentApiFactory;
use Datto\Asset\Agent\Config\CopyProgress;
use Datto\Asset\Agent\Config\PairProgress;
use Datto\Config\AgentConfig;
use Datto\Connection\ConnectionType;
use Datto\Connection\Service\ConnectionService;
use Datto\Feature\FeatureService;
use Datto\Log\SanitizedException;
use Datto\Service\AssetManagement\Create\CreateAgentService;
use Datto\Utility\Screen;
use Datto\Common\Utility\Filesystem;
use Datto\Utility\Security\SecretString;
use Datto\Wizard\AddAgent\Service\AgentService;
use Datto\Asset\Agent\Agentless\RetargetAgentlessService;
use Exception;
use RuntimeException;
use Throwable;

class Pair
{
    /**
     * The name of the worker screen and the PHP script without extension.
     */
    const PAIRING_WORKER_NAME = 'pairAssetsWorker';

    /**
     * The time to check if the worker is actually running. This is because we
     * start it in screen and have no other way to check the exit status of the
     * PHP script we're starting. So we rely on reasonable predictive timings
     * to heuristically determine success/failures. Here 0.5s (in µs) - the
     * worker sleeps for 1s as soon as it starts executing.
     */
    const WORKER_BOOTSTRAP_TIME = 500000;

    /** @var CreateAgentService */
    private $createAgentService;

    /** @var NewAgentService */
    private $agentService;

    /** @var EsxVirtualMachineManager */
    private $esxVirtualMachineManager;

    /** @var Filesystem */
    private $filesystem;

    /** @var Screen */
    private $screen;

    /** @var AgentConnectivityService */
    private $agentConnectivityService;

    /** @var AgentApiFactory */
    private $agentApiFactory;

    /** @var ConnectionService */
    private $connectionService;

    /** @var FeatureService */
    private $featureService;

    public function __construct(
        CreateAgentService $pairService,
        EsxVirtualMachineManager $esxVirtualMachineManager,
        NewAgentService $agentService,
        Filesystem $filesystem,
        Screen $screen,
        AgentConnectivityService $agentConnectivityService,
        AgentApiFactory $agentApiFactory,
        ConnectionService $connectionService,
        FeatureService $featureService
    ) {
        $this->createAgentService = $pairService;
        $this->esxVirtualMachineManager = $esxVirtualMachineManager;
        $this->agentService = $agentService;
        $this->filesystem = $filesystem;
        $this->screen = $screen;
        $this->agentConnectivityService = $agentConnectivityService;
        $this->agentApiFactory = $agentApiFactory;
        $this->connectionService = $connectionService;
        $this->featureService = $featureService;
    }

    /**
     * Starts the pairing process in the background.
     * Progress can be monitored by calling the getPairProgress() endpoint
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENT_CREATE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_CREATE")
     *
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "offsiteTarget" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^cloud|noOffsite|\d+$~"),
     *   "password" = @Symfony\Component\Validator\Constraints\Length(max = 1000),
     *   "domainName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~"),
     *   "moRef" = @Symfony\Component\Validator\Constraints\Length(max = 1000),
     *   "agentKeyToCopy" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~"),
     *   "useLegacyKeyName" = @Symfony\Component\Validator\Constraints\Type(type = "bool")
     * })
     *
     * @param string $offsiteTarget Where to send the offsite data. Either 'cloud', 'noOffsite',
     *     or a deviceid of a p2p device.
     * @param string $password If not blank, this password will be used to encrypt the data.
     * @param string $domainName The hostname or ip address where the system can be reached. Leave off for agentless.
     * @param string $moRef The identifier for the VM on an esx host/cluster. Leave off for agent based systems.
     * @param string $connectionName Identifies the esx connection to the host/cluster that contains the VM.
     *     Leave off for agent based systems.
     * @param string $agentKeyToCopy If not blank, we will copy settings from the agent referred to by this agentKey
     * @param bool $useLegacyKeyName True to create the agent with a legacy keyName (domainName/moRef) instead of a uuid
     * @param bool $fullDisk If true, will force agentless pairing to backup full disk images (UVM/generic)
     * @return string The assetKey of the agent that will be created
     */
    public function startPair(
        string $offsiteTarget,
        string $password = '',
        string $domainName = '',
        string $moRef = '',
        string $connectionName = '',
        string $agentKeyToCopy = '',
        bool $useLegacyKeyName = false,
        bool $fullDisk = false
    ): string {
        // todo use ConnectionExists constraint for connectionName above when it becomes available

        try {
            $password = new SecretString($password);
            return $this->createAgentService->startPair(
                $offsiteTarget,
                $password,
                $domainName,
                $moRef,
                $connectionName,
                $agentKeyToCopy,
                $useLegacyKeyName,
                $fullDisk
            );
        } catch (Exception $e) {
            throw new SanitizedException($e, [$password]);
        }
    }

    /**
     * Get pairing progress for processes started by the startPair() endpoint
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENT_CREATE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_CREATE")
     *
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "assetKey" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     *
     * @return array With keys
     *     'progress' => percent of the pairing process that has been completed (0 - 100)
     *     'state' => The current state of the pairing process. Will be one of the constants in CreateAgentProgress.php
     *     'errorMessage' => Full text error or exception messages. Empty string if there is no error
     */
    public function getPairProgress(string $assetKey): array
    {
        return $this->createAgentService->getPairProgress($assetKey);
    }

    /**
     * Handles asset pairing requests.
     *
     * Supports paring of both agentless and agent based systems. It can pair
     * one or more systems. Does not define whether the process is sequential
     * or parallel - it's up to worker implementation.
     *
     * FIXME This is horrible. Fix this!
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENT_CREATE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_CREATE")
     *
     * @param array $assetLocationInfo
     *  An array with any data that would help to locate the asset to pair, e.g.
     *  for agentless, it needs at least VM name and connection name, for agents
     *  ip or hostname.
     * @param array $data
     *  the settings data colleced from UI for use by all systems that were
     *  requested for pairing
     * @param string $passphrase
     *  If provided, all the requested systems will be protected with this
     *  passphrase.
     *
     * @return bool
     */
    public function createAssets(
        array $assetLocationInfo,
        array $data,
        string $passphrase = null
    ): bool {
        try {
            $pairingInfo = [
                'assetLocationInfo' => $assetLocationInfo,
                'data' => $data,
                'passphrase' => $passphrase,
            ];
            $argInfoFile = $this->filesystem->tempName('/dev/shm', 'pairing-info-');
            $this->filesystem->filePutContents($argInfoFile, json_encode($pairingInfo));

            if ($this->screen->isScreenRunning(self::PAIRING_WORKER_NAME)) {
                throw new RuntimeException('Pairing worker is already running.');
            }

            $command = [
                'php',
                sprintf('/datto/scripts/%s.php', self::PAIRING_WORKER_NAME),
                $argInfoFile
            ];
            $isLaunched = $this->screen->runInBackground(
                $command,
                self::PAIRING_WORKER_NAME
            );
            if (!$isLaunched) {
                throw new RuntimeException(
                    'Failed to start background pairing process.'
                );
            }
            usleep(self::WORKER_BOOTSTRAP_TIME);

            // The screen itself started fine but the php process failed.
            $isRunning = $this->screen->isScreenRunning(self::PAIRING_WORKER_NAME);
            if (!$isRunning) {
                throw new RuntimeException(
                    'The background pairing process is not running, ' .
                    'check the device log.'
                );
            }
            return true;
        } catch (Throwable $e) {
            throw new SanitizedException($e, [$passphrase]);
        }
    }

    /**
     * Return all existing virtual connections with their name, type, and any VMs associated with the connections
     *
     * NOTE: Currently it is only possible to obtain a list of VMs from ESX,
     * as the way to do this has not been implemented yet for HyperV.
     * Once HyperV is implemented this will be changed to allow for getting connection based on the type of connection
     *
     * FIXME This does not belong here at all
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_HYPERVISOR_CONNECTIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     *
     * @return array Returns an array of 'objects' (arrays) containing the name and type of each connection,
     * along with the VMs for that connection
     * When 'vms' is returned as null it means it's an unsupported hypervisor, and if empty, that means there was no VMs
     */
    public function getVirtualConnections()
    {
        $connections = $this->connectionService->getAll();
        $typedConnections = array();

        foreach ($connections as $connection) {
            if ($connection->getType() === ConnectionType::LIBVIRT_ESX()) {
                try {
                    $vms = $this->getAvailableVmsForConnection($connection->getName());
                } catch (\Throwable $throwable) {
                    $vms = null;
                }
            } else {
                $vms = null;
            }

            $typedConnections[] = array(
                'name' => $connection->getName(),
                'type' => $connection->getType()->value(),
                'vms' => (isset($vms) ? $vms[0]['VMs'] : null)
            );
        }

        return $typedConnections;
    }

    /**
     * Get asset pairing state.
     *
     * Returns paring progress, message among other things.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_CREATE")
     * @param array $assetLocationInfo
     * @return array
     */
    public function getPairingState(array $assetLocationInfo): array
    {
        $totalAssets = count($assetLocationInfo);
        $totalPercentage = 100 / $totalAssets;
        $assetsProcessed = 1;

        $progressInfo = [
            'agents' => [
                'success' => [],
                'fail' => [],
                'working' => [],
            ],
            'agentCount' => $totalAssets,
            'currentAgent' => 0,
            'progress' => 0,
            'message' => 'Pairing VM' . ($totalAssets === 1 ? '' : 's'),
            'status' => 'WORKING',
        ];

        foreach ($assetLocationInfo as $assetInfo) {
            $agentService = new AgentService($assetInfo['moRef'] ?? $assetInfo['keyName']);
            $assetKey = $agentService->getAssetKey();
            $agentConfig = new AgentConfig($assetKey);

            $createProgress = new PairProgress();
            $agentConfig->loadRecord($createProgress);

            $copyProgress = new CopyProgress();

            if ($agentConfig->loadRecord($copyProgress) !== false) {
                // Set the create progress to the lower percentage of the create
                // agent progress and the copy settings progress
                $createProgress->setProgress(min($createProgress->getProgress(), $copyProgress->getPercentage()));
            }

            $progressData = [
                'agent' => $assetInfo['name'],
                'message' => '',
                'errCode' => '',
                'createProgress' => $createProgress,
                'copyProgress' => $copyProgress
            ];

            switch ($createProgress->getCode()) {
                case PairProgress::CODE_CREATE_INIT:
                case PairProgress::CODE_CREATE_CREATING:
                    $progressInfo['progress'] +=
                        $totalPercentage / (100 / (float) max($createProgress->getProgress(), 1));
                    $progressInfo['agents']['working'][] = $progressData;

                    break;
                case PairProgress::CODE_CREATE_SUCCESS:
                case PairProgress::CODE_CREATE_EXISTS:
                    $progressInfo['progress'] += $totalPercentage;
                    $progressInfo['agents']['success'][] = $progressData;

                    break;

                case PairProgress::CODE_CREATE_FAIL:
                default:
                    $progressData['message'] = $createProgress->getStatus();
                    $progressInfo['progress'] += $totalPercentage;
                    $progressInfo['agents']['fail'][] = $progressData;

                    break;
            }
        }

        if (count($progressInfo['agents']['working']) === 0) {
            $progressInfo['status'] = 'COMPLETE';
        } else {
            $progressInfo['status'] = 'WORKING';
        }

        $progress = round($progressInfo['progress'], 2);

        if ($progress <= 0) {
            $progress = 5;
        } elseif ($progress > 100) {
            $progress = 100;
        }

        $progressInfo['progress'] = $progress;

        $working = count($progressInfo['agents']['working']);
        $progressInfo['currentAgent'] = $assetsProcessed;

        if ($working > 0) {
            $workingInfo = $progressInfo['agents']['working'][0];
            $progressInfo['message'] = sprintf(
                '%s for %s...',
                $workingInfo['createProgress']->getStatus(),
                $workingInfo['agent']
            );
        } else {
            $progressInfo['message'] = 'Working...';
        }

        return $progressInfo;
    }

    /**
     * Check information about whether an agent is pairable (ping, nmap results, agent version supported)
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_CREATE")
     * @param string $domainName agent's fully qualified domain name
     * @return array pairability results in an array
     */
    public function isPairable(string $domainName): array
    {
        $response['agent'] = $domainName;
        $response['validName'] = $this->agentService->isValidName($domainName);
        $response['domainAlreadyPaired'] = $this->agentService->isDomainPaired($domainName);

        if ($response['validName'] && !$response['domainAlreadyPaired']) {
            $pingResult = $this->agentService->pingAgent($domainName);

            if ($pingResult !== false) {
                $ping = $pingResult['ping'];
                $ip = $pingResult['address'];
                $osFamily = $pingResult['osFamily'];

                // Validate secure pairing only if the agent is actually online
                $goodMachinePing = $pingResult['ping'] === NewAgentService::PING_MACHINE_GOOD;
                if ($goodMachinePing) {
                    $platform = $this->agentConnectivityService->determineAgentPlatform($domainName);
                    $agentApi = $this->agentApiFactory->create($domainName, $platform);

                    if ($platform === AgentPlatform::SHADOWSNAP() &&
                        !$this->featureService->isSupported(FeatureService::FEATURE_SHADOWSNAP)) {
                        $shadowsnapDisabled = true;
                    }

                    $isAgentVersionSupported = $agentApi->isAgentVersionSupported();
                    $needsReboot = $agentApi->needsReboot();
                }

                $response['ping'] = $ping;
                $response['ip'] = $ip;
                $response['osFamily'] = $osFamily;
                $response['agentSupported'] = $isAgentVersionSupported ?? false;
                $response['shadowsnapDisabled'] = $shadowsnapDisabled ?? false;

                $response['needsReboot'] = $needsReboot ?? false;
            }
        }
        return ['agent' => $response];
    }

    /**
     * Retarget an agentless system from its old target to a new MoRef ID.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTLESS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentKeyName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~"),
     *   "connectionName" = @Datto\App\Security\Constraints\ConnectionExists()
     * })
     * @param string $agentKeyName Current/old agent target
     * @param string $moRef The moRefID of the new target system
     * @param string $connectionName The connection name of the hypervisor for the target system
     */
    public function retargetEsxAgentless(string $agentKeyName, string $moRef, string $connectionName): void
    {
        $retargetAgentlessService = new RetargetAgentlessService($agentKeyName);
        $retargetAgentlessService->retarget($moRef, $connectionName);
    }

    /**
     * Check if an ESX VM looks like the same system as a given agent.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTLESS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentKeyName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~"),
     *   "connectionName" = @Datto\App\Security\Constraints\ConnectionExists()
     * })
     * @param string $agentKeyName Current/old agent target
     * @param string $moRef The moRefID of the new target system
     * @param string $connectionName The connection name of the hypervisor for the target system
     * @return bool
     */
    public function verifyEsxAgentless(string $agentKeyName, string $moRef, string $connectionName): bool
    {
        $retargetAgentlessService = new RetargetAgentlessService($agentKeyName);
        $isSameHost = $retargetAgentlessService->verifyTargetIdentity($moRef, $connectionName);
        return $isSameHost;
    }

    /**
     * Get list of available VMs for a single connection
     *
     * @param string $name name of connection
     * @return array
     */
    private function getAvailableVmsForConnection($name)
    {
        return $this->esxVirtualMachineManager->getAvailableVirtualMachinesForConnection($name);
    }
}
