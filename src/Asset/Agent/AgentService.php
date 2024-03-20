<?php

namespace Datto\Asset\Agent;

use Datto\Asset\Agent\Agentless\AgentlessSystem;
use Datto\Asset\Asset;
use Datto\Asset\AssetServiceInterface;
use Datto\Asset\AssetType;
use Datto\Asset\Repository;
use Datto\Cloud\JsonRpcClient;
use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;

/**
 * Service to create, list and destroy agents.
 *
 * The service uses the AgentRepository to retrieve and store agent config files.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class AgentService implements AssetServiceInterface
{
    const CREATE_IN_PROGRESS = 'creating';
    const CREATE_NOT_IN_PROGRESS = '';
    const CREATE_IN_PROGRESS_FORMAT = '/tmp/agent-create-%s.tmp';
    const WINDOWS_AGENT_PORT = 25568;
    const SHADOW_SNAP_PORT = 25566;
    const LINUX_AGENT_PORT = 25567;
    const MAC_AGENT_PORT = 25569;
    const PING_MACHINE_GOOD = 1;
    const OS_FAMILY_DATTO_WINDOWS = 'DattoWindows';
    const OS_FAMILY_SHADOWSNAP_WINDOWS = 'Windows';
    const OS_FAMILY_MAC = 'MacOS';
    const OS_FAMILY_LINUX = 'Linux';

    /** @var Repository Agent repository to read/write config files */
    protected $repository;

    /** @var Filesystem */
    protected $filesystem;

    private ProcessFactory $processFactory;

    /** @var JsonRpcClient */
    private $client;

    public function __construct(
        AgentRepository $repository = null,
        Filesystem $filesystem = null,
        ProcessFactory $processFactory = null,
        JsonRpcClient $client = null
    ) {
        $this->repository = $repository ?: new AgentRepository();
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->processFactory = $processFactory ?: new ProcessFactory();
        $this->client = $client ?: new JsonRpcClient();
    }

    // Note:
    //   If you are looking to implement AgentService->create() and/or AgentService->delete(),
    //   please check: http://github-server.datto.lan/gist/pheckel/1353f1c549d4922c9f89

    /**
     * Check to see if an agent exists
     *
     * @return bool True if it exists, false otherwise
     */
    public function exists($name)
    {
        return $this->repository->exists($name);
    }

    /**
     * @inheritdoc
     * @return Agent the agent with the given agent name
     */
    public function get($agentName)
    {
        return $this->repository->get($agentName);
    }

    /**
     * @inheritdoc
     * @return Agent[] list of agents on the device.
     */
    public function getAll(string $type = null)
    {
        return $this->repository->getAll(true, true, $type ?? AssetType::AGENT);
    }

    /**
     * @inheritdoc
     * @return Agent[] list of agents on the device. Not including replicated agents.
     */
    public function getAllLocal(string $type = null)
    {
        return $this->repository->getAll(false, true, $type ?? AssetType::AGENT);
    }

    /**
     * @inheritdoc
     * @return Agent[] list of agents on the device. Not including archived agents.
     */
    public function getAllActive(string $type = null)
    {
        return $this->repository->getAll(true, false, $type ?? AssetType::AGENT);
    }

    /**
     * @inheritdoc
     * @return Agent[] list of agents on the device. Not including replicated or archived agents.
     */
    public function getAllActiveLocal(string $type = null)
    {
        return $this->repository->getAll(false, false, $type ?? AssetType::AGENT);
    }

    /**
     * Get an array of asset keyNames.
     * This is significantly faster than calling getAll()
     *
     * @param string|null $type An AssetType or null for all agents
     * @return string[]
     */
    public function getAllKeyNames(string $type = null): array
    {
        return $this->repository->getAllNames(true, true, $type ?? AssetType::AGENT);
    }

    /**
     * Get an array of active asset keyNames.
     * This is significantly faster than calling getAllActive()
     *
     * @param string|null $type An AssetType or null for all agents
     * @return string[]
     */
    public function getAllActiveKeyNames(string $type = null): array
    {
        return $this->repository->getAllNames(true, false, $type ?? AssetType::AGENT);
    }

    /**
     * Get an array of active, local asset keyNames.
     * This is significantly faster than calling getAllActiveLocal()
     *
     * @param string|null $type An AssetType or null for all agents
     * @return string[]
     */
    public function getAllActiveLocalKeyNames(string $type = null): array
    {
        return $this->repository->getAllNames(false, false, $type ?? AssetType::AGENT);
    }

    /**
     * Get an array of active replicated assets.
     * @return Agent[]
     */
    public function getAllActiveReplicated(): array
    {
        return array_filter($this->getAllActive(), function (Asset $asset) {
            return $asset->getOriginDevice()->isReplicated();
        });
    }

    /**
     * Get the create status of a given agent.
     *
     * @param string $agentName Agent name
     * @return string Status code
     */
    public function getCreateStatus($agentName)
    {
        $inProgressFile = $this->getCreateInProgressFile($agentName);

        return $this->filesystem->exists($inProgressFile)
            ? self::CREATE_IN_PROGRESS
            : self::CREATE_NOT_IN_PROGRESS;
    }

    /**
     * Store an Agent on the disk using the repository provided in the constructor.
     *
     * @param Asset $agent
     */
    public function save(Asset $agent): void
    {
        $agent->commit();
        $this->repository->save($agent);

        if ($agent->getLocal()->hasPausedChanged()) {
            $assets = [
                $agent->getKeyName() => $agent->getLocal()->isPaused()
            ];
            $this->client->notifyWithId('v1/device/asset/updatePaused', ['assets' => $assets]);
        }
    }

    /**
     * Determine if the specified agent can have it's video controller
     * changed or not.  This is needed as ubuntu and windows 2008/vista
     * agents have to have specific video controllers which the user should
     * not be allowed to change.  Note that this is specific to virtualization
     * using kvm.
     *
     * @param string $agentName
     * @return bool
     */
    public function canChangeVideoController($agentName)
    {
        $canChangeVideoController = true;
        $agentRepo = $this->get($agentName);
        $operatingSystem = $agentRepo->getOperatingSystem();
        $operatingSystemName = $operatingSystem->getName();
        $operatingSystemVersion = $operatingSystem->getVersion();

        // Update: After removing RDP support from the device, we are still maintaining these restrictions, because
        // we don't want to have to test all of the other combinations of graphics models and OS versions

        // To allow RDP connections to Ubuntu agents we need to keep the video mode as QXL
        if (isset($operatingSystemName) && preg_match('/ubuntu/i', $operatingSystemName)) {
            $canChangeVideoController = false;
        }

        // Windows 2008/Vista
        // https://en.wikipedia.org/wiki/Comparison_of_Microsoft_Windows_versions#Windows_NT
        // To allow RDP connections to broken Windows agents we need to keep the video mode as CIRRUS
        $isWindowsWithBrokenVga = preg_match('/windows/i', $operatingSystemName)
            && version_compare($operatingSystemVersion, '6.0', '>=')
            && version_compare($operatingSystemVersion, '6.1', '<');

        if ($isWindowsWithBrokenVga) {
            $canChangeVideoController = false;
        }

        return $canChangeVideoController;
    }

    /**
     * Does an agent exist with the given fqdn?
     *
     * @param string $domainName
     * @return bool
     */
    // TODO: Can we remove this, or move it somewhere else?
    public function isDomainPaired($domainName)
    {
        foreach ($this->getAll() as $agent) {
            $sameDomainName = $agent->getFullyQualifiedDomainName() == $domainName;
            if ($sameDomainName && !$agent->isRescueAgent() && !$agent->getLocal()->isArchived()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Does an agentless system exist with the same connection and moRef?
     *
     * @param string $connectionName
     * @param string $moRef
     * @param Agent[] $agentlessSystems
     * @return bool
     */
    // TODO: Can we remove this, or move it somewhere else?
    public function isAgentlessSystemPaired(string $connectionName, string $moRef, array $agentlessSystems = []) : bool
    {
        if (empty($agentlessSystems)) {
            $agentlessSystems = $this->getAllActiveLocal(AssetType::AGENTLESS);
        }

        foreach ($agentlessSystems as $agent) {
            /** @var AgentlessSystem $agent */
            $esxInfo = $agent->getEsxInfo();

            if ($esxInfo->getMoRef() === $moRef && $esxInfo->getConnectionName() === $connectionName) {
                return true;
            }
        }
        return false;
    }

    /**
     * Checks if a ip/hostname is a valid name (ie. contains only valid characters)
     *
     * @param string $hostname
     * @return bool
     */
    public function isValidName(string $hostname): bool
    {
        return !preg_match("/[^a-zA-Z0-9\\._\\-]+/", $hostname);
    }

    /**
     * @param $agent
     * @return array|bool Returns false on failure. Otherwise, an array of ping information
     */
    public function pingAgent(string $agent)
    {
        try {
            $portsToCheck = array(
                self::OS_FAMILY_DATTO_WINDOWS => self::WINDOWS_AGENT_PORT,
                self::OS_FAMILY_SHADOWSNAP_WINDOWS => self::SHADOW_SNAP_PORT,
                self::OS_FAMILY_LINUX => self::LINUX_AGENT_PORT,
                self::OS_FAMILY_MAC => self::MAC_AGENT_PORT
            );

            foreach ($portsToCheck as $osFamily => $port) {
                $process = $this->processFactory->get(['nc', '-v', '-z', '-w', '1', trim($agent), $port]);

                $process->run();
                if ($process->isSuccessful()) { //We found the agent!
                    //NOTICE WE EXIT THE FUNCTION HERE IF WE FIND THE AGENT!!!!
                    return [
                        'osFamily' => $osFamily,
                        'address' => $agent,
                        'ping' => self::PING_MACHINE_GOOD
                    ];
                }
            }
        } catch (\Throwable $e) {
            // bad hostname
            return false;
        }
        return false;
    }

    /**
     * Return the temporary agent creation file (indicates if agent is being created).
     *
     * @param string $agentName Agent name
     * @return string File name
     */
    private function getCreateInProgressFile($agentName)
    {
        return sprintf(self::CREATE_IN_PROGRESS_FORMAT, $agentName);
    }
}
