<?php

namespace Datto\App\Controller\Api\V1\Device\Asset\Agent;

use Datto\Asset\Agent\Windows\WindowsAgent;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;

/**
 * This class contains the API endpoints for working with VSS writers.
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
 * @author Matt Cheman <mcheman@datto.com>
 */
class VssWriters extends AbstractAgentEndpoint implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * Exclude vss writer from backups
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "agentName" = @Datto\App\Security\Constraints\AssetExists(type="windows")
     * })
     * @param string $agentName name of agent
     * @param string $writerId id of the writer to exclude
     * @return bool
     */
    public function excludeWriter($agentName, $writerId)
    {
        $agent = $this->agentService->get($agentName);

        $this->logger->setAssetContext($agentName);
        $this->logger->info('VSS0002 Excluding vss writer', ['writer' => $writerId]); // log code is used by device-web see DWI-2252

        if ($agent instanceof WindowsAgent) {
            $agent->getVssWriterSettings()->excludeWriter($writerId);
        }
        $this->agentService->save($agent);
        return true;
    }

    /**
     * Include vss writer in backups
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "agentName" = @Datto\App\Security\Constraints\AssetExists(type="windows")
     * })
     * @param string $agentName name of agent
     * @param string $writerId id of the writer to include
     * @return bool
     */
    public function includeWriter($agentName, $writerId)
    {
        $agent = $this->agentService->get($agentName);

        $this->logger->setAssetContext($agentName);
        $this->logger->info('VSS0001 Including vss writer', ['writer' => $writerId]); // log code is used by device-web see DWI-2252

        if ($agent instanceof WindowsAgent) {
            $agent->getVssWriterSettings()->includeWriter($writerId);
        }
        $this->agentService->save($agent);
        return true;
    }
}
