<?php

namespace Datto\App\Controller\Api\V1\Device\Asset\Agent\Rescue;

use Datto\Asset\Agent\ArchiveService;
use Datto\Asset\Agent\Encryption\AbstractPassphraseException;
use Datto\Asset\Agent\Rescue\RescueAgentService;
use Datto\Log\SanitizedException;
use Datto\Utility\Security\SecretString;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Throwable;

/**
 * API endpoints for dealing with rescue agents.
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
 * @author Christopher R. Wicks <cwicks@datto.com>
 */
class RescueAgent extends AbstractController
{
    /** @var RescueAgentService */
    private $rescueAgentService;

    /** @var ArchiveService */
    private $archiveService;

    public function __construct(
        RescueAgentService $rescueAgentService,
        ArchiveService $archiveService
    ) {
        $this->rescueAgentService = $rescueAgentService;
        $this->archiveService = $archiveService;
    }

    /**
     * Create a rescue agent for the given agent, using the snapshot at the given epoch time.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESCUE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_LOCAL_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentKeyName" = @Symfony\Component\Validator\Constraints\Regex(pattern="~^[A-Za-z\d\-\_\.]+$~"),
     *   "connectionName" = @Datto\App\Security\Constraints\ConnectionExists()
     * })
     * @param string $agentKeyName
     * @param int $snapshotEpoch
     * @param bool $pauseSourceBackups
     * @param string $connectionName
     * @param string|null $passphrase encryption passphrase for the agent, if any
     * @param bool $hasNativeConfiguration
     * @return string[] with key 'redirect'
     */
    public function create(
        string $agentKeyName,
        int $snapshotEpoch,
        bool $pauseSourceBackups,
        string $connectionName,
        string $passphrase = null,
        bool $hasNativeConfiguration = false
    ) {
        try {
            $passphrase = $passphrase ? new SecretString($passphrase) : null;
            $rescueAgent = $this->rescueAgentService->create(
                $agentKeyName,
                $snapshotEpoch,
                $pauseSourceBackups,
                $connectionName,
                $hasNativeConfiguration,
                $passphrase
            );
            $rescueAgentKeyName = $rescueAgent->getKeyName();

            $newSnapshotEpoch = $rescueAgent->getLocal()->getRecoveryPoints()->getLast()->getEpoch();
            $parameters = [
                'agentKey' => $rescueAgentKeyName,
                'point' => $newSnapshotEpoch,
                'hypervisor' => $connectionName
            ];
            $redirectUrl = $this->generateUrl('restore_virtualize_configure', $parameters);

            return ['redirect' => $redirectUrl];
        } catch (AbstractPassphraseException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SanitizedException($e, [$passphrase]);
        }
    }

    /**
     * Stop a rescue agent.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESCUE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_LOCAL_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentKeyName" = @Symfony\Component\Validator\Constraints\Regex(pattern="~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $agentKeyName
     * @return boolean True on success.
     */
    public function stop($agentKeyName)
    {
        $this->rescueAgentService->stop($agentKeyName);
        return true;
    }

    /**
     * Start a rescue agent.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESCUE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_LOCAL_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentKeyName" = @Symfony\Component\Validator\Constraints\Regex(pattern="~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $agentKeyName
     * @param string $passphrase
     * @return boolean True on success.
     */
    public function start(string $agentKeyName, string $passphrase = null)
    {
        try {
            $passphrase = $passphrase ? new SecretString($passphrase) : null;
            $this->rescueAgentService->start($agentKeyName, $passphrase);
            return true;
        } catch (Throwable $e) {
            throw new SanitizedException($e, [$passphrase]);
        }
    }

    /**
     * Archives a rescue agent
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESCUE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_LOCAL_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentKeyName" = @Symfony\Component\Validator\Constraints\Regex(pattern="~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $agentKeyName
     * @return boolean True on success.
     */
    public function archive($agentKeyName)
    {
        $this->archiveService->archive($agentKeyName);
        return true;
    }
    /**
     * Get whether or not a given agent has rescue agents based on it.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESCUE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_LOCAL_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "name" = @Symfony\Component\Validator\Constraints\Regex(pattern="~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $name Name of the asset to check
     * @return bool
     */
    public function hasRescues($name)
    {
        return $this->rescueAgentService->hasRescueAgents($name);
    }

   /**
     * Get list of all souce agents that have rescue agents.
     *
    * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
    * @Datto\App\Security\RequiresFeature("FEATURE_RESCUE_AGENTS")
    * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_LOCAL_READ")
     * @return string[]
     */
    public function getAllSources()
    {
        return $this->rescueAgentService->getAllSourceAgents();
    }
}
