<?php

namespace Datto\Asset\Agent;

use Datto\Agentless\Proxy\AgentlessSessionId;
use Datto\Agentless\Proxy\AgentlessSessionService;
use Datto\Asset\Agent\Agentless\Api\AgentlessProxyApi;
use Datto\Asset\Agent\Api\AgentApi;
use Datto\Asset\Agent\Windows\Api\ShadowSnapAgentApi;
use Datto\Config\AgentConfig;
use Datto\Config\AgentConfigFactory;
use Datto\Connection\Libvirt\EsxConnection;
use Datto\Connection\Service\ConnectionService;
use Datto\Common\Resource\Sleep;
use Datto\Util\WindowsOsHelper;
use Exception;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * Responsible for performing transaction of updating agent data for both
 * agent-ed and agentless systems.
 * @author Devon Welcheck <dwelcheck@datto.com>
 *
 * REFACTOR REQUIRED!
 *
 */
class AgentDataUpdateService
{
    public const WMIC_MAX_ATTEMPTS = 5;
    public const WMIC_SLEEP_BETWEEN_ATTEMPTS_IN_SECONDS = 5;
    public const SKIP_ZFS_UPDATE = false;

    private AgentConfigFactory $agentConfigFactory;
    private AgentInfoBuilderService $agentInfoBuilderService;
    private AgentlessSessionService $agentlessSessionService;
    private ConnectionService $connectionService;
    private WindowsOsHelper $windowsOsHelper;
    private Sleep $sleep;
    private DeviceLoggerInterface $logger;

    public function __construct(
        AgentConfigFactory $agentConfigFactory,
        AgentInfoBuilderService $agentInfoBuilderService,
        AgentlessSessionService $agentlessSessionService,
        ConnectionService $connectionService,
        WindowsOsHelper $windowsOsHelper,
        Sleep $sleep,
        DeviceLoggerInterface $logger
    ) {
        $this->agentConfigFactory = $agentConfigFactory;
        $this->agentInfoBuilderService = $agentInfoBuilderService;
        $this->agentlessSessionService = $agentlessSessionService;
        $this->connectionService = $connectionService;
        $this->windowsOsHelper = $windowsOsHelper;
        $this->sleep = $sleep;
        $this->logger = $logger;
    }

    /**
     * Queries the agent for updated agent info, normalizes it, and saves it.
     */
    public function updateAgentInfo(string $agentKey, AgentApi $api, bool $updateZfsInfo = true): AgentRawData
    {
        $this->logger->info('ADU0001 Starting agent info update');
        $agentConfig = $this->agentConfigFactory->create($agentKey);

        try {
            $hostResponse = $this->getHost($agentKey, $agentConfig, $api);

            $updateData = $this->getUpdateData($agentKey, $hostResponse, $api);
            $agentInfo = $this->agentInfoBuilderService->buildAgentInfo($updateData, $updateZfsInfo);
            $updateData->setAgentInfo($agentInfo);

            $this->agentInfoBuilderService->saveNewAgentInfo($updateData);
        } catch (Throwable $e) {
            $this->logger->error('ADU0003 Failed to update agentInfo', ['exception' => $e]);
            throw $e;
        }

        $this->logger->info('ADU0002 Agent info update complete.');
        return $updateData;
    }

    /**
     * Builds an API instance, gets the '/host' response, and performs Windows
     * version normalization and gets VSS writer updates as needed.
     */
    private function getUpdateData(
        string   $assetKey,
        array    $hostResponse,
        AgentApi $agentApi
    ): AgentRawData {
        $updateData = new AgentRawData($assetKey, $agentApi->getPlatform(), $hostResponse);
        $isOnPremWindowsAgent = ($agentApi->getPlatform() === AgentPlatform::DATTO_WINDOWS_AGENT());
        $isDtcWindowsAgent = ($agentApi->getPlatform() === AgentPlatform::DIRECT_TO_CLOUD()) && is_array($hostResponse['VSSWriters']);

        if ($isOnPremWindowsAgent || $isDtcWindowsAgent) {
            $newVssWriters = $this->agentInfoBuilderService->modifyVssWriterData($hostResponse['VSSWriters']);
            $updateData->setVssWriters($newVssWriters);
        } elseif ($agentApi->getPlatform() === AgentPlatform::SHADOWSNAP()) {
            /** @var ShadowSnapAgentApi $shadowSnapAgentApi */
            $shadowSnapAgentApi = $agentApi;
            $winData = $this->getWinData($shadowSnapAgentApi, $hostResponse);
            $updateData->setWinData($winData);
            // Query for new VSS writers and write them out.
            $newVssWriters = $shadowSnapAgentApi->getVssWriters();
            if (is_array($newVssWriters)) {
                $updateData->setVssWriters($newVssWriters);
            }
        }

        return $updateData;
    }

    /**
     * Gets the latest agent info from the agent via a '/host' call.
     */
    private function getHost(
        string      $agentKey,
        AgentConfig $agentConfig,
        AgentApi    $api
    ): array {
        try {
            // If the api has not been initialized, it's possible we could save the 5+ minutes it takes to do so
            if ($api instanceof AgentlessProxyApi && !$api->isInitialized()) {
                $esxInfo = $this->getEsxInfo($agentKey, $agentConfig);

                /** @var EsxConnection|null $esxConnection */
                $esxConnection = $this->connectionService->get($esxInfo['connectionName']);
                if (!$esxConnection) {
                    $this->logger->error('BTF0002 ESX connection with requested name not found.', ['connectionName' => $esxInfo['connectionName']]);
                    $message = 'ESX connection with requested name "' . $esxInfo['connectionName'] . '" not found.';
                    throw new Exception($message);
                }
                $agentlessSessionId = $this->getAgentlessSessionId($esxInfo['moRef'], $esxConnection, $agentKey);

                // session is already running so we can grab the agent info from it directly instead of waiting 5+ minutes
                if ($this->agentlessSessionService->isSessionRunning($agentlessSessionId)) {
                    $session = $this->agentlessSessionService->getSessionReadonly($agentlessSessionId);
                    $hostResponse = $session->getAgentVmInfo();
                    $hostResponse['esxInfo'] = $session->getEsxVmInfo();
                    return $hostResponse;
                }

                $this->agentlessSessionService->waitUntilSessionIsReleased($agentlessSessionId, $this->logger, 60 * 5);
                $api->initialize();
            }

            return $api->getHost();
        } catch (Throwable $e) {
            $this->logger->error('AGT4006 An error occurred retrieving latest agent info.');
            throw $e;
        }
    }

    /**
     * Retrieve the ESX info for an agentless system from its 'esxInfo' keyfile.
     *
     * @return bool|mixed
     */
    private function getEsxInfo(string $agentKey, AgentConfig $agentConfig)
    {
        $esxInfoRaw = $agentConfig->get('esxInfo');
        if ($esxInfoRaw === false) {
            $this->logger->info('AGL0001 Unable to read esxInfo file', ['agentKey' => $agentKey]);
            return false;
        }
        $esxInfo = unserialize($esxInfoRaw, ['allowed_classes' => false]);
        if (!is_array($esxInfo)) {
            $this->logger->info('AGL0002 Error unserializing esxInfo key file', ['agentKey' => $agentKey]);
            return false;
        }
        return $esxInfo;
    }

    /**
     * Generate an agentless session ID for a given ESX connection and ESX
     * VM identifier (or 'moRef').
     */
    private function getAgentlessSessionId(
        string        $moRef,
        EsxConnection $esxConnection,
        string        $agentKey
    ): AgentlessSessionId {

        $host = $esxConnection->getPrimaryHost();
        $user = $esxConnection->getUser();
        $password = $esxConnection->getPassword();
        if (is_null($host) || is_null($user) || is_null($password)) {
            $this->logger->error("BTF0003 ESX connection doesn't have host, user, or password", ['host' => $host, 'user' => $user, 'password' => $password]);
            $msg = "ESX connection doesn't have host (host: '$host'), user (user: '$user'), or password (password: '$password').";
            throw new Exception($msg);
        }

        return $this->agentlessSessionService->generateAgentlessSessionId(
            $host,
            $user,
            $password,
            $moRef,
            $agentKey
        );
    }

    /**
     * Creates the 'winData' property for ShadowSnap agentInfo files
     */
    private function getWinData(ShadowSnapAgentApi $shadowSnapAgentApi, array $agentInfo): array
    {
        $apiVersionRequiresWmic = substr($agentInfo["apiVersion"] ?? '', 0, 6) === '3.0.22';
        $windowsVersionMayBeWrong = (
            strpos($agentInfo['os'], 'Windows 8.1') !== false ||
            strpos($agentInfo['os'], "Windows Server 2016") !== false
        );

        if ($apiVersionRequiresWmic || $windowsVersionMayBeWrong) {
            $winData = $this->getWindowsVersionData($shadowSnapAgentApi);
        } else {
            $os = $agentInfo['os'];
            $osVersion = $agentInfo['os_version'] ?? null;
            $winData = $this->windowsOsHelper->windowsVersion($os, $osVersion);
        }

        return $winData;
    }

    /**
     * Determine the Windows version information.  Only used for ShadowSnap.
     */
    private function getWindowsVersionData(ShadowSnapAgentApi $shadowSnapAgentApi): array
    {
        $this->logger->info("AGT7200 Entering Windows Version name correction function");

        $wmicName = '';

        for ($attempt = 0; $attempt < self::WMIC_MAX_ATTEMPTS; $attempt++) {
            $wmicName = $shadowSnapAgentApi->runCommand('wmic', ['os', 'get', 'name']);
            $wmicName = is_array($wmicName) ? trim($wmicName['output'][0]) : '';
            if (!stristr($wmicName, "please wait while wmic")) {
                break;
            }
            $this->sleep->sleep(self::WMIC_SLEEP_BETWEEN_ATTEMPTS_IN_SECONDS);
        }

        if ($wmicName && !stristr($wmicName, "please wait while wmic")) {
            $wmicName = str_replace("\r", "", $wmicName);
            $wmicName = str_replace("\n", "", $wmicName);
            $wmicName = str_replace("Name", "", $wmicName);
            $wmicName = str_replace("Microsoft", "", $wmicName);
            $version = $shadowSnapAgentApi->runCommand('wmic', ['os', 'get', 'version']);
            $version = is_array($version) ? trim($version['output'][0]) : '';
            $version = str_replace("\r", "", $version);
            $version = str_replace("\n", "", $version);
            $version = str_replace("Version", "", $version);
            $version = trim($version);
            $servicePack = $shadowSnapAgentApi->runCommand('wmic', ['os', 'get', 'servicepackmajorversion']);
            $servicePack = is_array($servicePack) ? trim($servicePack['output'][0]) : '';
            $servicePack = str_replace("\r", "", $servicePack);
            $servicePack = str_replace("\n", "", $servicePack);
            $servicePack = str_replace("ServicePackMajorVersion", "", $servicePack);
            $servicePack = trim($servicePack);
            list($name, $junk) = explode("|", trim($wmicName));
        } else {
            $namever = $shadowSnapAgentApi->runCommand('ver');
            $namever = is_array($namever) ? trim($namever['output'][0]) : '';
            list($junk, $version) = explode("[Version ", $namever);
            $version = str_replace("]", "", trim($version));
            $name = $this->windowsOsHelper->lookupCommonWindowNames($version);
            $servicePack = '';
        }

        $osData = explode(".", $version);

        return [
            'long' => $name . " " . $version,
            'windows' => $name,
            'version' => $version,
            'servicePack' => $servicePack,
            'major' => $osData[0],
            'minor' => $osData[1],
            'build' => $osData[2]
        ];
    }
}
