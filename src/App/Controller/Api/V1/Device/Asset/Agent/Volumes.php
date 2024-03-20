<?php
namespace Datto\App\Controller\Api\V1\Device\Asset\Agent;

use Datto\Asset\Agent\AgentDataUpdateStatus;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\EncryptionService;
use Datto\Asset\Agent\VolumeInclusionService;
use Datto\Asset\Agent\VolumesService;
use Datto\Backup\BackupManagerFactory;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\AgentShmConfigFactory;
use Datto\User\WebUser;
use Exception;

/**
 * This class contains the API endpoints for working with agent communications.
 *
 * Important note:
 *   This is an API endpoint, and NOT the actual library class.
 *   Do NOT add actual functionality to this class. Use library
 *   classes (and functions, if you must) instead!
 *
 * How to call this API?
 *   Please refer to the /api/api.php file and/or the
 *   Datto\API\Server class for details on how to call this API.
 */
class Volumes extends AbstractAgentEndpoint
{
    /** The error code for when a volume dataset fails to delete due to a running backup */
    const DELETE_FAILURE = 78381;

    private AgentShmConfigFactory $agentShmConfigFactory;

    private ProcessFactory $processFactory;

    private VolumesService $volumesService;

    private BackupManagerFactory $backupManagerFactory;

    private VolumeInclusionService $volumeInclusionService;

    private EncryptionService $encryptionService;

    private WebUser $user;

    /**
     * @param AgentService $agentService
     * @param AgentShmConfigFactory $agentShmConfigFactory
     * @param ProcessFactory $processFactory
     * @param VolumesService $volumesService
     * @param BackupManagerFactory $backupManagerFactory
     */
    public function __construct(
        AgentService $agentService,
        AgentShmConfigFactory $agentShmConfigFactory,
        ProcessFactory $processFactory,
        VolumesService $volumesService,
        BackupManagerFactory $backupManagerFactory,
        VolumeInclusionService $volumeInclusionService,
        EncryptionService $encryptionService,
        WebUser $webUser
    ) {
        parent::__construct($agentService);
        $this->agentShmConfigFactory = $agentShmConfigFactory;
        $this->processFactory = $processFactory;
        $this->volumesService = $volumesService;
        $this->backupManagerFactory = $backupManagerFactory;
        $this->volumeInclusionService = $volumeInclusionService;
        $this->encryptionService = $encryptionService;
        $this->user = $webUser;
    }


    /**
     * Enable backup on a volume
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     * @param string $agentName name of agent
     * @param string $volumeGuid unique volume identifier
     * @return array with keys 'agentName', 'guid', and 'backupsExist'
     */
    public function includeVol(string $agentName, string $volumeGuid): array
    {
        if ($this->encryptionService->isAgentSealed($agentName)) {
            throw new Exception("Settings cannot be changed on a sealed asset");
        }

        $agent = $this->agentService->get($agentName);
        $this->volumesService->includeByGuid($agent->getKeyName(), $volumeGuid);
        $backupsExist = (count($agent->getLocal()->getRecoveryPoints()->getAll())
                + count($agent->getOffsite()->getRecoveryPoints()->getAll())) > 0;

        return array(
            'agentName' => $agentName,
            'guid' => $volumeGuid,
            'backupsExist' => $backupsExist
        );
    }

    /**
     * Disable backup on a volume
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     * @param string $agentName name of agent
     * @param string $volumeGuid unique volume identifier
     * @param bool $deleteDataset
     * @return array
     * @throws Exception
     */
    public function excludeVol(string $agentName, string $volumeGuid, bool $deleteDataset): array
    {
        if ($this->encryptionService->isAgentSealed($agentName)) {
            throw new Exception("Settings cannot be changed on a sealed asset");
        }
        $this->volumesService->excludeByGuid($agentName, $volumeGuid);
        if ($this->user->isRemoteWebUser() && $deleteDataset === true) {
            $this->deleteDataset($agentName, $volumeGuid);
        }
        return [
            'agentName' => $agentName,
            'guid' => $volumeGuid,
            'deleteDataset' => $deleteDataset
        ];
    }

    /**
     * Update volume included/excluded state.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     *
     * @param string $agentName name of agent.
     * @param array $includedGuidData array with keys 'volumeGuid' and value boolean to
     *                                 indicate if the volume was included or not.
     * @return bool true on success, false on failure.
     */

    public function setIncludedGuids(string $agentName, array $includedGuidData): bool
    {
        if ($this->encryptionService->isAgentSealed($agentName)) {
            throw new Exception("Settings cannot be changed on a sealed asset");
        }

        $agent = $this->agentService->get($agentName);
        $this->volumeInclusionService->setIncludedGuids($agent, $includedGuidData);

        return true;
    }

    /**
     * Check whether this agent volume is currently being deleted
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     *
     * @param string $agentName name of agent
     * @param string $volumeGuid unique volume identifier
     * @return bool whether the volume dataset is currently being deleted
     */
    public function isDatasetDeletionInProgress(string $agentName, string $volumeGuid): bool
    {
        return $this->volumesService->isDatasetDeletionInProgress($agentName, $volumeGuid);
    }

    /**
     * Delete the dataset of a volume
     * (A user might do this to free up data if the volume is excluded from backup,
     * or to force a full backup because something is wrong.)
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_DELETE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     * @param string $agentName name of agent
     * @param string $volumeGuid unique volume identifier
     * @return string[] with keys 'agentName' and 'guid'
     */
    public function deleteDataset(string $agentName, string $volumeGuid): array
    {
        if ($this->encryptionService->isAgentSealed($agentName)) {
            throw new Exception("Data cannot be deleted on a sealed asset");
        }

        $agent = $this->agentService->get($agentName);

        // If a backup is occurring the dataset should not be deleted
        $backupManager = $this->backupManagerFactory->create($agent);
        if ($backupManager->isRunning()) {
            throw new Exception('Cannot delete volume dataset while a backup is running.', self::DELETE_FAILURE);
        }

        $this->volumesService->destroyVolumeDatasetByGuid($agent, $volumeGuid);

        return array(
            'agentName' => $agentName,
            'guid' => $volumeGuid
        );
    }

    /**
     * Request to refresh drive info from the agent
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     * @param string $agentName name of agent
     * @return bool
     */
    public function refresh(string $agentName): bool
    {
        $process = $this->processFactory->get(['snapctl', 'agent:update', $agentName, '--background']);
        return $process->run() !== 0;
    }

    /**
     * Retrieve the status of the current volume refresh job.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     * @param string $agentName name of agent
     * @return string that describes the status of the volume refresh job.
     */
    public function getRefreshStatus(string $agentName): string
    {
        $shmConfig = $this->agentShmConfigFactory->create($agentName);
        $refreshStatus = new AgentDataUpdateStatus();
        $shmConfig->loadRecord($refreshStatus);
        return $refreshStatus->getStatus();
    }

    /**
     * Retrieve the updated volume info.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     * @param string $agentName name of agent
     * @return array[] An array of associative arrays, each of which contains the following keys: guid, path, space,
     * isOs, isSys, isRemovable, filesystem, included, backupsExist, isMissing
     */
    public function getVolumes(string $agentName): array
    {
        $agent = $this->agentService->get($agentName);
        return $this->volumesService->getVolumeParameters($agent);
    }
}
