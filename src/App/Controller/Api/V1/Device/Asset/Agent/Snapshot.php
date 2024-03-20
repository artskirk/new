<?php

namespace Datto\App\Controller\Api\V1\Device\Asset\Agent;

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
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class Snapshot extends AbstractAgentEndpoint
{
    /**
     * Get the snapshot timeout
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $agentName Name of the agent
     * @return int Timeout value in seconds
     */
    public function getTimeout($agentName)
    {
        $agent = $this->agentService->get($agentName);
        return $agent->getLocal()->getTimeout();
    }

    /**
     * Set the snapshot timeout
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $agentName Name of the agent
     * @param int $timeout Timeout value in seconds
     */
    public function setTimeout($agentName, $timeout): void
    {
        if ($timeout < 30) {
            throw new \Exception('Timeout must be at least 30 seconds');
        }

        $agent = $this->agentService->get($agentName);
        $agent->getLocal()->setTimeout($timeout);
        $this->agentService->save($agent);
    }
}
