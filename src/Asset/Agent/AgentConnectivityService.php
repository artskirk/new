<?php

namespace Datto\Asset\Agent;

use Datto\Agent\PairHandler;
use Datto\Asset\Agent\Agentless\Api\AgentlessProxyApi;
use Datto\Asset\Agent\Api\AgentApiFactory;
use Datto\Asset\Agent\Api\DattoAgentApi;
use Datto\Asset\Agent\Linux\Api\LinuxAgentApi;
use Datto\Asset\Agent\Mac\Api\MacAgentApi;
use Datto\Asset\Agent\Windows\Api\WindowsAgentApi;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\AgentConfigFactory;
use Datto\Config\DeviceConfig;
use Datto\Common\Utility\Filesystem;
use Datto\Log\LoggerFactory;
use Datto\Verification\VerificationService;
use Exception;
use Datto\Log\DeviceLoggerInterface;

class AgentConnectivityService
{
    const DEFAULT_SHADOWSNAP_PORT = 25566;

    const STATE_AGENT_ACTIVE = 1;
    const STATE_AGENT_INACTIVE = 2;
    const STATE_HOST_UNREACHABLE = 3;
    const STATE_CONNECTION_TIMEOUT = 4;
    const STATE_AGENT_DNS_LOOKUP_FAILED = 5;
    const STATE_INVERSE_HOST_LOOKUP_FAILED = 6;
    const STATE_UNKNOWN = 7;

    /**
     * @var array 'statuses' that are acceptable
     */
    private $acceptableStatuses = array(1, 2);

    private ProcessFactory $processFactory;

    /**
     * @var DeviceConfig
     */
    private $deviceConfig;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var AgentService
     */
    private $agentService;

    /**
     * @var AgentConfigFactory
     */
    private $agentConfigFactory;

    /**
     * @var DeviceLoggerInterface $logger only used in retargetAgent function
     */
    private $logger;

    /** @var AgentApiFactory */
    private $agentApiFactory;

    /** @var VerificationService */
    private $verificationService;

    public function __construct(
        ProcessFactory $processFactory,
        DeviceConfig $deviceConfig,
        Filesystem $filesystem,
        AgentService $agentService,
        AgentConfigFactory $agentConfigFactory,
        DeviceLoggerInterface $logger,
        AgentApiFactory $agentApiFactory,
        VerificationService $verificationService
    ) {
        $this->deviceConfig = $deviceConfig;
        $this->filesystem = $filesystem;
        $this->agentService = $agentService;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->processFactory = $processFactory;
        $this->logger = $logger;
        $this->agentApiFactory = $agentApiFactory;
        $this->verificationService = $verificationService;
    }

    /**
     * Check new fqdn and set fqdn if agent responds as expected
     * @param string $agentKey - key-string to identify agent
     * @param string $domainName - FullyQualifiedDomainName to check and retarget
     */
    public function retargetAgent($agentKey, $domainName): void
    {
        $logger = $this->logger ?: LoggerFactory::getAssetLogger($agentKey);
        if ($this->verificationService->hasInProgressVerification($agentKey)) {
            $errorMessage = "Operation is not allowed while a screenshot is in progress";
            $logger->error('ACS0007 ' . $errorMessage);
            throw new Exception($errorMessage);
        }
        if ($this->agentService->isDomainPaired($domainName)) {
            $errorMessage = 'A system with this domain name has already been paired with this device';
            $logger->error('ACS0006 ' . $errorMessage);
            throw new Exception($errorMessage);
        }
        $agent = $this->agentService->get($agentKey);
        $this->clearPairingInfoIfPresent($agentKey);
        $port = $this->getDefaultPort($agent->getPlatform());

        $logger->info('ACS0001 Checking Agent status of domain name.', ['domainName' => $domainName]);
        $state = $this->updateConnectivity($domainName, $port, $logger, $agentKey);
        if (!$this->isConnectedState($state)) {
            throw new Exception("Could not reach agent at $domainName");
        }
        $agent->setFullyQualifiedDomainName($domainName);
        $this->agentService->save($agent);
        $this->updateAgentParameters($agent, $logger);
        $logger->info('ACS0004 domain name has been saved and will be used for future agent communications', ['domainName' => $domainName]); // log code is used by device-web see DWI-2252
        $logger->info('ACS0005 Sending updated domain name to the device-web');
    }

    /**
     * Check the connectivity for a new agent.
     *
     * @param string $domainName
     * @param AgentPlatform $platform
     * @param string|null $agentKey
     * @return bool
     */
    public function checkNewAgentConnectivity(string $domainName, AgentPlatform $platform, string $agentKey = null): bool
    {
        $logger = $this->logger ?: LoggerFactory::getDeviceLogger();
        $port =  $this->getDefaultPort($platform);

        try {
            $logger->debug('ASC0006 Checking connectivity for new agent.', ['domainName' => $domainName, 'port' => $port]);
            $code = $this->updateConnectivity($domainName, $port, $logger, $agentKey);
            $success = $code === self::STATE_AGENT_ACTIVE;
        } catch (Exception $e) {
            $success = false;
        }
        return $success;
    }

    /**
     * Determine the agent platform for a system
     *
     * @param string $domainName
     * @param string|null $agentKey
     * @return AgentPlatform
     */
    public function determineAgentPlatform(string $domainName, string $agentKey = null): AgentPlatform
    {
        $logger = $this->logger ?: LoggerFactory::getDeviceLogger();
        $platforms = [
            AgentPlatform::DATTO_WINDOWS_AGENT(),
            AgentPlatform::DATTO_LINUX_AGENT(),
            AgentPlatform::SHADOWSNAP(),
            AgentPlatform::DATTO_MAC_AGENT()
        ];
        foreach ($platforms as $platform) {
            if ($this->checkNewAgentConnectivity($domainName, $platform, $agentKey)) {
                return $platform;
            }
        }
        $logger->error('ASC0002 Could not connect to the agent.');
        throw new Exception('Could not connect to the agent.');
    }

    /**
     * Check the connectivity for an existing agent.
     *
     * @param Agent $agent
     * @return int
     */
    public function checkExistingAgentConnectivity(Agent $agent): int
    {
        $domainName = $agent->getFullyQualifiedDomainName();
        $port = $this->getDefaultPort($agent->getPlatform());
        $agentKey = $agent->getKeyName();
        $logger = $this->logger ?: LoggerFactory::getAssetLogger($agentKey);

        try {
            $logger->info('ASC0007 Checking connectivity for existing agent', ['domainName' => $domainName, 'port' => $port]);
            $state = $this->updateConnectivity($domainName, $port, $logger, $agentKey);
            return $state;
        } catch (Exception $e) {
            return self::STATE_UNKNOWN;
        }
    }

    /**
     * Returns whether or not an existing agent now
     *  seems to have a new agent type.
     *
     * @param Agent $agent
     * @return bool
     */
    public function existingAgentHasNewType(Agent $agent): bool
    {
        $agentHasNewType = false;
        $agentKey = $agent->getKeyName();
        $domainName = $agent->getFullyQualifiedDomainName();
        $logger = $this->logger ?: LoggerFactory::getAssetLogger($agentKey);
        try {
            $logger->info("ASC0010 Checking for agent type change");

            $existingPlatform = $agent->getPlatform();
            $newPlatform = $this->determineAgentPlatform($domainName);

            if ($newPlatform !== $existingPlatform) {
                $agentHasNewType = true;
                $logger->info('ASC0008 Agent platform changed', ['oldAgentPlatform' => $existingPlatform->value(), 'newAgentPlatform' => $newPlatform->value()]);
            }
        } catch (Exception $e) {
            $logger->debug("ASC0009 Couldn't connect to agent, unable to determine agent type", ['agentKey' => $agentKey, 'domainName' => $domainName]);
        }

        return $agentHasNewType;
    }

    /**
     * Returns if the given state is present in the array of acceptable statuses
     * @param int $state
     * @return bool
     */
    public function isConnectedState(int $state): bool
    {
        return in_array($state, $this->acceptableStatuses);
    }

    /**
     * @param string $domainName
     * @param int $port
     * @param DeviceLoggerInterface $logger
     * @param string|null $agentKey
     * @return int
     */
    private function updateConnectivity(
        string $domainName,
        int $port,
        DeviceLoggerInterface $logger,
        string $agentKey = null
    ): int {
        $statusOutput = $this->agentStatus($domainName, $port);
        $code = (int)key($statusOutput);
        $message = $statusOutput[$code];
        $logger->debug('ACS0008 Agent status: ' . $message);
        if ($this->isConnectedState($code)) {
            if ($agentKey) {
                $fileName = Agent::KEYBASE . $agentKey . '.connectivity';
                $this->filesystem->filePutContents($fileName, $code . ':' . $message);
            }
            $logger->debug('ACS0002 Agent is responding on given port', ['domainName' => $domainName, 'port' => $port]);
        } else {
            $logger->debug('ACS0003 Agent is NOT responding on given port', ['domainName' => $domainName, 'port' => $port]);
        }
        return $code;
    }

    /**
     * Check agent status using netcat, logic copied from snapFunctions agentStatus() function
     * @param string $domainName - Fully Qualified Domain Name to check with nc (netcat)
     * @param string|Int $port - port to check being open with nc (netcat)
     * @return array
     */
    private function agentStatus($domainName, $port)
    {
        $process = $this->processFactory->get(['nc', '-v', '-z', '-w', '1', $domainName, $port]);
        $process->run();
        $output = $process->getOutput();
        $retval = $process->getExitCode();

        if ($this->deviceConfig->has('logConnectivity')) {
            if (!$this->filesystem->exists("/var/log/datto")) {
                $this->filesystem->mkdir("/var/log/datto", true, 0777);
            }
            $logMsg = date("Y-m-d H:i:s", time()) . ":  ------------------------------------------------>\n";
            $logMsg .= "Ran netcat as part of agentStatus from Datto\Asset\Agent\AgentConnectivityService\n";
            $logMsg .= "Command: nc -v -z -w 1 $domainName $port 2>&1\n";
            $logMsg .= "Results: \n";
            $logMsg .= "    " . $output . "\n";
            $logMsg .= "Exit Code: " . $retval . "\n";
            $this->filesystem->filePutContents("/var/log/datto/connectivity.log", $logMsg, FILE_APPEND);
        }

        if ($retval === 0) {
            $status = self::STATE_AGENT_ACTIVE . ":Agent is active port and port $port is open";
        } elseif (preg_match('/Connection refused/', $output)) {
            $status = self::STATE_AGENT_INACTIVE . ":Host is reachable, Agent is not running";
        } elseif (preg_match('/No route to host/', $output)) {
            $status = self::STATE_HOST_UNREACHABLE . ":Host is not reachable";
        } elseif (preg_match('/Operation now in progress|Connection timed out/', $output)) {
            $status = self::STATE_CONNECTION_TIMEOUT . ":Connection attempt timed out.";
        } elseif (preg_match('/Name or service not known/', $output)) {
            $status = self::STATE_AGENT_DNS_LOOKUP_FAILED . ":DNS Lookup of Agent failed.";
        } elseif (preg_match('/inverse host lookup failed/', $output)) {
            $status = self::STATE_INVERSE_HOST_LOOKUP_FAILED . ":Inverse host lookup failed";
        } else {
            $status = self::STATE_UNKNOWN . ":Unknown State";
        }
        $status = explode(":", $status);

        return [$status[0] => $status[1]];
    }

    /**
     * Retrieve the default communications port for the given agent type
     *
     * @param AgentPlatform $platform
     * @return int
     */
    private function getDefaultPort(AgentPlatform $platform)
    {
        if ($platform === AgentPlatform::SHADOWSNAP()) {
            $port = static::DEFAULT_SHADOWSNAP_PORT;
        } elseif ($platform === AgentPlatform::DATTO_LINUX_AGENT()) {
            $port = LinuxAgentApi::AGENT_PORT;
        } elseif ($platform === AgentPlatform::DATTO_MAC_AGENT()) {
            $port = MacAgentApi::AGENT_PORT;
        } elseif ($platform === AgentPlatform::DATTO_WINDOWS_AGENT()) {
            $port = WindowsAgentApi::AGENT_PORT;
        } else {
            throw new Exception('Unexpected Agent Platform Type: ' . $platform);
        }
        return $port;
    }

    private function clearPairingInfoIfPresent($assetKey): void
    {
        $agentConfig = $this->agentConfigFactory->create($assetKey);
        $agentConfig->clear(PairHandler::PAIRING_INFO_KEYFILE);
    }

    /**
     * Send parameters needed for agent checkin to agent if the agent is a Datto agent
     * @param Agent $agent
     * @param DeviceLoggerInterface $logger
     */
    public function updateAgentParameters(Agent $agent, DeviceLoggerInterface $logger): void
    {
        try {
            $agentApi = $this->agentApiFactory->createFromAgent($agent);

            if ($agentApi instanceof DattoAgentApi && !($agentApi instanceof AgentlessProxyApi)) {
                $agentApi->sendAgentCheckinParams($agent->getFullyQualifiedDomainName(), $agent->getUuid());
            }
        } catch (Exception $e) {
            $logger->error("ASC0015 Unable to update Agent parameters");
        }
    }
}
