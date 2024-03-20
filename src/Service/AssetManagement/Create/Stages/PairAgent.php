<?php

namespace Datto\Service\AssetManagement\Create\Stages;

use Datto\Asset\Agent\AgentConnectivityService;
use Datto\Asset\Agent\AgentDataUpdateService;
use Datto\Asset\Agent\Agentless\AgentlessSystem;
use Datto\Asset\Agent\Agentless\EsxInfo;
use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\Api\AgentApi;
use Datto\Asset\Agent\Api\AgentApiFactory;
use Datto\Asset\Agent\PairingDeniedException;
use Datto\Asset\AssetRemovalService;
use Datto\Asset\AssetType;
use Datto\Config\AgentConfig;
use Datto\Config\AgentConfigFactory;
use Datto\Config\AgentStateFactory;
use Datto\Connection\ConnectionType;
use Datto\Connection\Service\EsxConnectionService;
use Datto\Resource\DateTimeService;
use Datto\Common\Resource\Sleep;
use Datto\Samba\SambaManager;
use Datto\Service\AssetManagement\Create\CreateAgentProgress;
use Datto\Util\RetryHandler;
use Datto\Virtualization\Hypervisor\Config\VmSettingsFactory;
use Exception;
use Vmwarephp\Vhost;

/**
 * Responsible for pairing the agent/agentless system.
 * After this stage the Agent will be unserializable by AgentService.
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class PairAgent extends AbstractCreateStage
{
    public const MAX_AGENT_DATA_WRITE_ATTEMPTS = 3;
    public const MAX_AGENT_PAIR_ATTEMPTS = 3;
    public const DELAY_AFTER_PAIRING_SECONDS = 5;
    public const DELAY_AFTER_PAIRING_FAILURE_SECONDS = 10;

    private AgentConnectivityService $agentConnectivityService;
    private AgentApiFactory $agentApiFactory;
    private AgentDataUpdateService $agentDataUpdateService;
    private DateTimeService $dateTimeService;
    private EsxConnectionService $esxConnectionService;
    private Sleep $sleep;
    private RetryHandler $retryHandler;
    private AgentApi $agentApi;
    private AgentStateFactory $agentStateFactory;
    private AgentConfigFactory $agentConfigFactory;
    private SambaManager $sambaManager;

    public function __construct(
        AgentConnectivityService $agentConnectivityService,
        AgentApiFactory $agentApiFactory,
        AgentDataUpdateService $agentDataUpdateService,
        DateTimeService $dateTimeService,
        EsxConnectionService $esxConnectionService,
        Sleep $sleep,
        RetryHandler $retryHandler,
        AgentStateFactory $agentStateFactory,
        AgentConfigFactory $agentConfigFactory,
        SambaManager $sambaManager
    ) {
        $this->agentConnectivityService = $agentConnectivityService;
        $this->agentApiFactory = $agentApiFactory;
        $this->agentDataUpdateService = $agentDataUpdateService;
        $this->dateTimeService = $dateTimeService;
        $this->esxConnectionService = $esxConnectionService;
        $this->sleep = $sleep;
        $this->retryHandler = $retryHandler;
        $this->agentStateFactory = $agentStateFactory;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->sambaManager = $sambaManager;
    }

    /**
     * Attempts to execute this stage
     */
    public function commit()
    {
        $logger = $this->context->getLogger();
        $agentConfig = $this->agentConfigFactory->create($this->context->getAgentKeyName());

        $agentState = $this->agentStateFactory->create($this->context->getAgentKeyName());
        $createProgress = new CreateAgentProgress();
        $createProgress->setState(CreateAgentProgress::PAIR);
        $agentState->saveRecord($createProgress);

        $platform = $this->determinePlatform();
        $logger->info('PAR0101 Agent platform', ['platform' => $platform]);

        if ($platform === AgentPlatform::SHADOWSNAP() && $this->sambaManager->getServerProtocolMinimumVersion() !== 1) {
            if (!$this->context->isForce()) {
                throw new Exception("SMB minimum version must be set to 1 for ShadowSnap agents. Either change the setting or pair with the 'force' option to automatically change it to 1.");
            }

            $logger->info('PAR0103 Setting SMB protocol minimum version to 1 to accommodate pairing');
            if ($this->sambaManager->setServerProtocolMinimumVersion(1)) {
                $this->sambaManager->sync();
            }
        }

        if ($this->context->isAgentless()) {
            $this->agentApi = $this->agentApiFactory->createAgentlessApi($this->context->getMoRef(), $this->context->getConnectionName(), $platform, $this->context->getAgentKeyName());
            $this->agentApi->initialize();
        } else {
            $this->agentApi = $this->agentApiFactory->create($this->context->getDomainName(), $platform, $logger);
            $response = $this->retryHandler->executeAllowRetry(
                function () {
                    return $this->agentApi->pairAgent($this->context->getAgentKeyName());
                },
                self::MAX_AGENT_PAIR_ATTEMPTS,
                self::DELAY_AFTER_PAIRING_FAILURE_SECONDS
            );
        }

        if ($platform === AgentPlatform::AGENTLESS_GENERIC()) {
            // BCDR-16709: Generic agentless are backed up via the full disk backup method
            $agentConfig->touch('fullDiskBackup'); // todo confirm it is safe to touch instead of set true
        } elseif ($platform === AgentPlatform::SHADOWSNAP()) {
            $agentConfig->touch('shadowSnap');

            $this->persistShadowSnapKeyInfo($agentConfig, $response);

            // Sleep to give the ShadowSnap agent webserver time to reboot, which it does after pairing
            $this->sleep->sleep(self::DELAY_AFTER_PAIRING_SECONDS);
        }

        $createProgress->setState(CreateAgentProgress::HOST);
        $agentState->saveRecord($createProgress);

        if ($this->context->isAgentless()) {
            // the rest of the esx info file gets filled in by updateAgentInfo() below
            $agentConfig->set(EsxInfo::KEY_NAME, serialize(['connectionName' => $this->context->getConnectionName()]));
        }

        // Once this runs, the agent will be unserializable since it creates a valid agentInfo file
        $this->updateAgentInfoWithRetry();

        // We correct the uuid and fqdn fields after so we avoid having a partially filled agentInfo file
        $agentInfo = unserialize($agentConfig->get('agentInfo'), ['allowed_classes' => false]);
        if (!is_array($agentInfo)) {
            $logger->error('PHD0003 Could not retrieve agent info; pairing failed');
            throw new Exception('Failed to create agentInfo file');
        }

        //BCDR-29858 - win2012/2012r2 have problems with virtio nic which is default
        if ($agentInfo['type'] == AssetType::WINDOWS_AGENT && strpos($agentInfo['os'], '2012')) {
            $kvmSettings = VmSettingsFactory::create(ConnectionType::LIBVIRT_KVM());
            $kvmSettings->setNetworkController('e1000');
            $agentConfig->set('kvmSettings', json_encode($kvmSettings->jsonSerialize()));
        }

        $agentInfo['uuid'] = $this->context->getUuid();
        if (!$this->context->isAgentless()) {
            $agentInfo['fqdn'] = $this->context->getDomainName();
        }

        $agentConfig->set('agentInfo', serialize($agentInfo));
        $agentConfig->set('dateAdded', $this->dateTimeService->getTime());
        $agentConfig->clear(AssetRemovalService::REMOVING_KEY);
    }

    /**
     * Clean up artifacts left behind in the commit stage
     */
    public function cleanup()
    {
        /**
         * Psalm believes this is not null, but cleanup could be called before agentApi
         * is assigned in commit
         * @psalm-suppress RedundantCondition
         */
        if ($this->agentApi !== null) {
            $this->agentApi->cleanup();
        }
    }

    /**
     * Rolls back any committed changes
     */
    public function rollback()
    {
        $logger = $this->context->getLogger();
        $logger->info('PAR0301 Removing keys');

        $keysToKeep = ['createProgress', 'log'];

        $agentConfig = $this->agentConfigFactory->create($this->context->getAgentKeyName());
        $agentConfig->deleteAllKeys($keysToKeep);

        $agentState = $this->agentStateFactory->create($this->context->getAgentKeyName());
        $agentState->deleteAllKeys($keysToKeep);
    }

    public function isOsFullySupported()
    {
        // Unfortunately the information in the host response is different from the information
        // we used to present the user with the list of VMs to choose from during the add
        // agent wizard. These calls are made so that we can get the guestFullName of the VM
        // in order to decide whether or not this OS is supported in the same way we determined it
        //  when presenting the list.
        /* @var Vhost $vHost */
        $vHost = $this->esxConnectionService->getVhostForConnectionName($this->context->getConnectionName());
        $vm = $vHost->findOneManagedObject('VirtualMachine', $this->context->getMoRef(), ['config']);
        $guestFullName = $vm->config->guestFullName;
        $guestFullName = is_string($guestFullName) ? $guestFullName : '';
        return AgentlessSystem::isOperatingSystemFullySupported($guestFullName);
    }

    public function determinePlatform(): AgentPlatform
    {
        if ($this->context->isAgentless()) {
            //Handle differences required for generic agentless VMs
            if ($this->isOsFullySupported() && !$this->context->isFullDisk()) {
                return AgentPlatform::AGENTLESS();
            }

            return AgentPlatform::AGENTLESS_GENERIC();
        }

        $platform = $this->agentConnectivityService->determineAgentPlatform(
            $this->context->getDomainName(),
            $this->context->getAgentKeyName() // todo stop requiring assetkey
        );

        return $platform;
    }

    private function persistShadowSnapKeyInfo(AgentConfig $agentConfig, array $response)
    {
        // We used to just directly serialize the response to the key file, but lets do some extra checking
        // to prevent https://www.owasp.org/index.php/PHP_Object_Injection
        if (!isset($response['code'], $response['dattoKey'], $response['message'], $response['success']) ||
            !is_string($response['code']) ||
            !is_string($response['dattoKey']) ||
            !is_string($response['message']) ||
            !is_int($response['success'])
        ) {
            throw new PairingDeniedException('SSP4050 Response from ShadowSnap pair request did not contain properly formatted information');
        }
        $agentConfig->set(
            'key',
            serialize([
                'code' => $response['code'],
                'dattoKey' => $response['dattoKey'],
                'message' => $response['message'],
                'success' => $response['success']
            ])
        );
    }

    /**
     * ShadowSnap might need a couple of tries to update the agent info
     */
    private function updateAgentInfoWithRetry()
    {
        $this->retryHandler->executeAllowRetry(
            function () {
                $this->agentDataUpdateService->updateAgentInfo(
                    $this->context->getAgentKeyName(),
                    $this->agentApi
                );
            },
            self::MAX_AGENT_DATA_WRITE_ATTEMPTS,
            2
        );
    }
}
