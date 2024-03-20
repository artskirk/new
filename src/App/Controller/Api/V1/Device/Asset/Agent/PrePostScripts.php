<?php

namespace Datto\App\Controller\Api\V1\Device\Asset\Agent;

use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Api\AgentApiFactory;
use Datto\Asset\Agent\Linux\LinuxAgent;
use Datto\Asset\Agent\Mac\MacAgent;
use Datto\Asset\Agent\Serializer\AgentApiPrePostScriptsSerializer;
use Datto\App\Controller\Web\Agents\ConfigureController;
use Exception;

/**
 * JSON-RPC endpoint for agents' pre-post scripts (also called quiescing scripts)
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
class PrePostScripts extends AbstractAgentEndpoint
{
    /** @var AgentApiFactory */
    private $agentApiFactory;

    /** @var AgentApiPrePostScriptsSerializer */
    private $agentApiPrePostScriptsSerializer;

    public function __construct(
        AgentService $agentService,
        AgentApiFactory $agentApiFactory,
        AgentApiPrePostScriptsSerializer $agentApiPrePostScriptsSerializer
    ) {
        parent::__construct($agentService);
        $this->agentApiFactory = $agentApiFactory;
        $this->agentApiPrePostScriptsSerializer = $agentApiPrePostScriptsSerializer;
    }

    /**
     * Enable a pre-post script for an agent's volume
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $agentName agent name
     * @param string $volume volume to enable the script for
     * @param string $script script to enable
     */
    public function enable($agentName, $volume, $script): void
    {
        /** @var LinuxAgent|MacAgent $agent */
        $agent = $this->agentService->get($agentName);
        if (!($agent instanceof LinuxAgent || $agent instanceof MacAgent)) {
            throw new Exception("$agentName has no PrePostScripts because it is not a Mac or Linux agent." . get_class($agent));
        }
        $agent->getPrePostScripts()->enableScript($volume, $script);
        $this->agentService->save($agent);
    }

    /**
     * Disable a pre-post script for an agent's volume
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $agentName agent name
     * @param string $volume volume to disable the script for
     * @param string $script script to disable
     */
    public function disable($agentName, $volume, $script): void
    {
        /** @var LinuxAgent|MacAgent $agent */
        $agent = $this->agentService->get($agentName);
        if (!($agent instanceof LinuxAgent || $agent instanceof MacAgent)) {
            throw new Exception("$agentName has no PrePostScripts because it is not a Mac or Linux agent.");
        }
        $agent->getPrePostScripts()->disableScript($volume, $script);
        $this->agentService->save($agent);
    }

    /**
     * Update and return an agent's scripts
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $agentName agent name
     * @return array
     */
    public function refresh($agentName)
    {
        /** @var LinuxAgent|MacAgent $agent */
        $agent = $this->agentService->get($agentName);
        if (!($agent instanceof LinuxAgent || $agent instanceof MacAgent)) {
            throw new Exception("$agentName has no PrePostScripts because it is not a Mac or Linux agent.");
        }

        $agentApi = $this->agentApiFactory->createFromAgent($agent);
        $apiInfo = $agentApi->getHost();

        if (!is_array($apiInfo)) {
            throw new Exception("System $agentName could not be reached.");
        }

        $apiScripts = $this->agentApiPrePostScriptsSerializer->unserialize($apiInfo['scriptsPrePost']);

        $agent->getPrePostScripts()->refresh($apiScripts, $agent->getVolumes());
        $this->agentService->save($agent);

        $volumes = $agent->getPrePostScripts()->getVolumes();
        $volumesParameters = array();
        foreach ($volumes as $mountPoint => $volume) {
            if ($mountPoint === ConfigureController::SWAP_MOUNTPOINT) {
                // swap volume is hidden
                continue;
            }
            $scripts = $volume->getScripts();
            $scriptsParameters = array();
            foreach ($scripts as $script) {
                $scriptsParameters[] = array(
                    'scriptName' => $script->getName(),
                    'displayName' => $script->getDisplayName(),
                    'enabled' => $script->isEnabled(),
                );
            }
            $volumesParameters[] = array(
                'volumeName' => $volume->getVolumeName(),
                'blockDevice' => $volume->getBlockDevice(),
                'scripts' => $scriptsParameters
            );
        }

        return $volumesParameters;
    }
}
