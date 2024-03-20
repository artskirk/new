<?php

namespace Datto\App\Controller\Api\V1\Device\Asset\Agent;

use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Backup\AgentSnapshotService;
use Datto\Asset\Agent\Backup\Serializer\AgentSnapshotSerializer;
use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\DiffMergeService;
use Datto\Asset\Agent\Windows\WindowsAgent;
use Datto\Asset\Agent\Windows\BackupSettings;
use Datto\Audit\AgentTypeAuditor;
use Datto\Samba\SambaManager;
use Exception;

/**
 * Backup JSON-RPC endpoint. Handles all agent backup options from the api
 *
 * @author John Fury Christ <jchrist@datto.com>
 */
class Backup extends AbstractAgentEndpoint
{
    private BackupSettings $backup;

    private SambaManager $sambaManager;

    private AgentTypeAuditor $agentTypeAuditor;

    private AgentSnapshotService $agentSnapshotService;

    private AgentSnapshotSerializer $agentSnapshotSerializer;

    private DiffMergeService $diffMergeService;

    public function __construct(
        BackupSettings $backup,
        AgentTypeAuditor $agentTypeAuditor,
        AgentSnapshotService $agentSnapshotService,
        AgentSnapshotSerializer $agentSnapshotSerializer,
        SambaManager $sambaManager,
        AgentService $agentService,
        DiffMergeService $diffMergeService
    ) {
        parent::__construct($agentService);

        $this->backup = $backup;
        $this->agentTypeAuditor = $agentTypeAuditor;
        $this->agentSnapshotService = $agentSnapshotService;
        $this->agentSnapshotSerializer = $agentSnapshotSerializer;
        $this->sambaManager = $sambaManager;
        $this->diffMergeService = $diffMergeService;
    }

    /**
     * Return detailed information for a specific agent and a specific snapshot.
     *
     * By default, this endpoint will return all known fields of an agent.
     * If $fields is non-empty, it is interpreted as a filter for agent keys.
     *
     * To return all agent information:
     *   $agent = $endpoint->get('backup1', '123456789');
     *
     * To return only name, hostname and volume information:
     *   $agent = $endpoint->get('backup1', '123456789', array('include', 'volumes'));
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~"),
     *   "snapshot" = @Symfony\Component\Validator\Constraints\Type(type = "numeric"),
     * })
     * @param string $agentName Name of the agent
     * @param string $snapshot Epoch time of agent
     * @param array $fields List of fields to include in the return array
     * @return array
     */
    public function getBackup($agentName, $snapshot, array $fields = array())
    {
        $agentSnapshot = $this->agentSnapshotService->get($agentName, $snapshot);
        $agentSnapshotArray = $this->agentSnapshotSerializer->serialize($agentSnapshot);

        return $this->filter($agentSnapshotArray, $fields);
    }

    /**
     * Get Backup Engine Options
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $agentName name of agent
     * @return array
     */
    public function getEngineOptions($agentName)
    {
        /** @var WindowsAgent $agent */
        $agent = $this->agentService->get($agentName);
        $backup = $agent->getBackupSettings();
        return array(
            'engineOption' => $backup->getBackupEngine()
        );
    }

    /**
     * Set Backup Engine Options for agent
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Datto\App\Security\Constraints\AssetExists(type="windows"),
     *   "engineOptions" = {
     *     @Symfony\Component\Validator\Constraints\NotBlank(),
     *     @Symfony\Component\Validator\Constraints\Choice(choices = {"both", "VSS", "DBD", "STC"})
     *   }
     * })
     */
    public function setEngineOptions(string $agentName, string $engineOptions): array
    {
        /** @var WindowsAgent $agent */
        $agent = $this->agentService->get($agentName);
        $backup = $agent->getBackupSettings();
        $backup->setBackupEngine($engineOptions);
        $this->agentService->save($agent);

        return ['agentName' => $agentName, 'engineOptions' => $backup->getBackupEngine()];
    }

    /**
     * Set Backup Engine Options for all agents of the specified type
     *
     * Note: The agentDriverType is passed in to make sure we don't accidentally update an agent with a backup engine option
     * that it doesn't support. The agent types used here are defined in AgentTypeAuditor
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @param string $engineOptions type of backup
     * @param string $agentDriverType The agent type to set the option for.
     * @return array with number of agents changed
     */
    public function setEngineOptionsAll($engineOptions, $agentDriverType)
    {
        $agents = $this->agentService->getAllActiveLocal();
        $agentCount = 0;
        foreach ($agents as $agent) {
            /** @var Agent $agent */
            try {
                $platform = $agent->getPlatform();
            } catch (Exception $e) {
                // If we can't get the agent type for whatever reason, continue past the current agent.
                continue;
            }
            if ($platform->value() == $agentDriverType) {
                $this->setEngineOptions($agent->getKeyName(), $engineOptions);
                $agentCount++;
            }
        }
        return array(
            'length' => $agentCount
        );
    }

    /**
     * Force the next backup to be a differential merge
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $agentName name of the agent
     * @param bool $osOnly true to perform diffmerge on only the os volume
     * @return array
     */
    public function forceDiffmerge(string $agentName, bool $osOnly = false)
    {
        /** @var Agent $agent */
        $agent = $this->agentService->get($agentName);
        if ($osOnly) {
            $this->diffMergeService->setDiffMergeOsVolume($agent);
        } else {
            $this->diffMergeService->setDiffMergeAllVolumes($agentName);
        }

        $status[] = array(
            'agentName' => $agentName,
            'forceDiffmerge' => $this->diffMergeService->getDiffMergeSettings($agentName)->isAnyVolume()
        );
        return $status;
    }

    /**
     * Reset the force diff merge flag.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     * @param string $agentName
     * @return array
     */
    public function resetDiffmerge(string $agentName)
    {
        $this->diffMergeService->clearDoDiffMerge($agentName);

        return [
            'agentName' => $agentName,
            'forceDiffmerge' => $this->diffMergeService->getDiffMergeSettings($agentName)->isAnyVolume()
        ];
    }

    /**
     * Is the next backup to be a forced differential merge
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $agentName name of the agent
     * @return array
     */
    public function isForceDiffmerge($agentName)
    {
        $status[] = array(
            'agentName' => $agentName,
            'forceDiffmerge' => $this->diffMergeService->getDiffMergeSettings($agentName)->isAnyVolume()
        );
        return $status;
    }

    /**
     * Sets the number of consecutive screenshot failures that will initiate a
     * diff merge.  This applies to the specified agent only.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Datto\App\Security\Constraints\AssetExists(type="agent"),
     *   "count" = @Symfony\Component\Validator\Constraints\Range(min=0, max=10)
     * })
     * @param string $agentName Agent key name
     * @param int $count
     */
    public function setDiffmergeScreenshots(string $agentName, int $count)
    {
        $this->diffMergeService->setMaxBadScreenshotCount($agentName, $count);
    }

    /**
     * Sets the number of consecutive screenshot failures that will initiate a
     * diff merge.  This applies to all agents on the device.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "count" = @Symfony\Component\Validator\Constraints\Range(min=0, max=10)
     * })
     * @param int $count
     */
    public function setDiffmergeScreenshotsAll(int $count)
    {
        $agents = $this->agentService->getAllActiveLocal();
        foreach ($agents as $agent) {
            $this->diffMergeService->setMaxBadScreenshotCount($agent->getKeyname(), $count);
        }
    }

    /**
     * Reduces a full backup array to the list of keys given in
     * the $fields array.
     *
     * @param array $serializedBackup
     * @param array $fields
     * @return array
     */
    private function filter(array $serializedBackup, array $fields)
    {
        $hasFilter = !empty($fields);

        if ($hasFilter) {
            $filteredBackup = array();

            foreach ($serializedBackup as $field => $value) {
                if (in_array($field, $fields)) {
                    $filteredBackup[$field] = $value;
                }
            }

            return $filteredBackup;
        } else {
            return $serializedBackup;
        }
    }
}
