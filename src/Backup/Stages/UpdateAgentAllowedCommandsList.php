<?php

namespace Datto\Backup\Stages;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Windows\Api\ShadowSnapAgentApi;
use Throwable;

/**
 * This backup stage sends the list of allowed command to the agent.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 * @author Mark Blakley <mblakley@datto.com>
 */
class UpdateAgentAllowedCommandsList extends BackupStage
{
    /**
     * @inheritdoc
     */
    public function commit()
    {
        $asset = $this->context->getAsset();
        /** @var Agent $agent */
        $agent = $asset;
        $agentApiVersion = $agent->getDriver()->getApiVersion() ?? '';
        // Older ShadowSnap versions don't have an allowed commands list.
        $hasNewApi = version_compare($agentApiVersion, ShadowSnapAgentApi::NEW_API_VERSION) >= 0;
        if ($hasNewApi) {
            $this->sendAllowedCommandsList();
        }
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
    }

    /**
     * Update agent's list of allowed commands
     */
    private function sendAllowedCommandsList()
    {
        $agentApi = $this->context->getAgentApi();
        if (!($agentApi instanceof ShadowSnapAgentApi)) {
            return;
        }

        $this->context->getLogger()->debug("BAK2300 Updating list of allowed commands on agent");
        try {
            $result = $agentApi->updateAllowedCommandsList();
            if (!$result) {
                $this->context->getLogger()->warning("BAK2301 Push of allowed commands list to agent failed");
            }
        } catch (Throwable $e) {
            $this->context->getLogger()->error("BAK2302 Error updating list of allowed commands - " . $e->getMessage());
        }
    }
}
