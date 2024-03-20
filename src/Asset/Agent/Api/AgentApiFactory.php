<?php

namespace Datto\Asset\Agent\Api;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Agentless\Api\AgentlessProxyApi;
use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\Linux\Api\LinuxAgentApi;
use Datto\Asset\Agent\Mac\Api\MacAgentApi;
use Datto\Asset\Agent\Windows\Api\ShadowSnapAgentApi;
use Datto\Asset\Agent\Windows\Api\WindowsAgentApi;
use Datto\Config\AgentConfigFactory;
use Datto\Connection\Libvirt\EsxConnection;
use Datto\Connection\Service\EsxConnectionService;
use Datto\Log\LoggerFactory;
use Exception;
use Datto\Log\DeviceLoggerInterface;

/**
 * @author Matthew Cheman <mcheman@datto.com>
 */
class AgentApiFactory
{
    private AgentConfigFactory $agentConfigFactory;
    private EsxConnectionService $esxConnectionService;

    public function __construct(
        AgentConfigFactory $agentConfigFactory = null,
        EsxConnectionService $esxConnectionService = null
    ) {
        $this->agentConfigFactory = $agentConfigFactory ?: new AgentConfigFactory();
        $this->esxConnectionService = $esxConnectionService ?: new EsxConnectionService();
    }

    /**
     * Create an AgentApi instance when an Agent is not available (such as before pairing).
     *
     * $shadowSnapKey Used by shadowsnap, the 'code' and 'dattoKey' fields from the /datto/config/keys/<keyName>.key file
     * $apiVersion Used by shadowsnap, the api version of the agent
     */
    public function create(
        string $fqdn,
        AgentPlatform $platform,
        DeviceLoggerInterface $logger = null,
        array $shadowSnapKey = [],
        string $apiVersion = null
    ): AgentApi {
        $logger = $logger ?: LoggerFactory::getDeviceLogger();

        switch ($platform) {
            case AgentPlatform::DATTO_WINDOWS_AGENT():
                $agentApi = new WindowsAgentApi($fqdn, $logger);
                break;
            case AgentPlatform::DATTO_LINUX_AGENT():
                $agentApi = new LinuxAgentApi($fqdn, $logger);
                break;
            case AgentPlatform::DATTO_MAC_AGENT():
                $agentApi = new MacAgentApi($fqdn, $logger);
                break;
            case AgentPlatform::SHADOWSNAP():
                $agentApi = new ShadowSnapAgentApi($fqdn, $logger, $apiVersion, $shadowSnapKey);
                break;
            default:
                throw new Exception('Agent platform does not match any api classes: ' . $platform->value());
        }

        $agentApi->initialize();
        return $agentApi;
    }

    /**
     * Create an AgentApi instance for the specified agent
     */
    public function createFromAgent(Agent $agent): AgentApi
    {
        $fqdn = $agent->getFullyQualifiedDomainName();
        $platform = $agent->getPlatform();

        $logger = LoggerFactory::getAssetLogger($agent->getKeyName());

        if ($platform === AgentPlatform::SHADOWSNAP()) {
            $apiVersion = $agent->getDriver()->getApiVersion();
            $agentConfig = $this->agentConfigFactory->create($agent->getKeyName());
            $key = unserialize($agentConfig->get('key'), ['allowed_classes' => false]);
            $key = is_array($key) ? $key : [];
            return $this->create($fqdn, $platform, $logger, $key, $apiVersion);
        }

        if ($platform->isAgentless()) {
            $agentConfig = $this->agentConfigFactory->create($agent->getKeyName());
            $esxInfoRaw = $agentConfig->get('esxInfo');
            $esxInfo = unserialize($esxInfoRaw, ['allowed_classes' => false]);

            $moRef = $esxInfo['moRef'];
            $connectionName = $esxInfo['connectionName'];
            return $this->createAgentlessApi($moRef, $connectionName, $platform, $agent->getKeyName(), $logger);
        }

        return $this->create($fqdn, $platform, $logger);
    }

    /**
     * Creates an AgentApi instance for an agentless system
     * Unfortunately, the AgentlessProxyApi doesn't match how the other api classes work closely enough to abstract
     * over the creation of it. We need a separate function.
     */
    public function createAgentlessApi(
        string $moRef,
        string $connectionName,
        AgentPlatform $platform,
        string $keyName,
        DeviceLoggerInterface $logger = null
    ): AgentApi {
        $esxConnection = $this->esxConnectionService->get($connectionName);
        if (!$esxConnection || !($esxConnection instanceof EsxConnection)) {
            if ($logger) {
                $logger->error("AAF0000 ESX connection not found", [
                'connectionName' => $connectionName
                ]);
            }
            $msg = "ESX connection with name '$connectionName' not found.";
            throw new Exception($msg);
        }
        return new AgentlessProxyApi(
            $moRef,
            $esxConnection,
            $platform,
            $keyName,
            $logger ?: LoggerFactory::getDeviceLogger()
        );
    }
}
