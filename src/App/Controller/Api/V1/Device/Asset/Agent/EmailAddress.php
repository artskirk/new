<?php

namespace Datto\App\Controller\Api\V1\Device\Asset\Agent;

use Datto\App\Controller\Api\V1\Device\Asset\EmailAddress as AssetEmailAddress;

/**
 * API endpoint query and change email alert settings for agents
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
class EmailAddress extends AssetEmailAddress
{
    /**
     * Get e-mail addresses for a certain agent
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $agentName Name of the agent
     * @return array Structures list of email lists
     */
    public function getAll($agentName)
    {
        $agent = $this->assetService->get($agentName);

        return array(
            'weekly' => $agent->getEmailAddresses()->getWeekly(),
            'critical' => $agent->getEmailAddresses()->getCritical(),
            'warning' => $agent->getEmailAddresses()->getWarning(),
            'getLog' => $agent->getEmailAddresses()->getLog()
        );
    }

    /**
     * Set weekly backup email report address list for an agent
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $agentName Name of the agent
     * @param string[] $emails email address list
     * @return bool true if successful
     */
    public function setWeekly($agentName, array $emails)
    {
        $agent = $this->assetService->get($agentName);

        $agent->getEmailAddresses()->setWeekly($emails);
        $this->assetService->save($agent);

        return true;
    }

    /**
     * Set weekly backup email report address list for all agents
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @param string[] $emails email address list
     * @param string|null $type asset type
     * @return array Structures list of asset name and emails
     */
    public function setWeeklyAll(array $emails, $type = null)
    {
        $agents = $this->assetService->getAllActiveLocal($type);

        $status = array();
        foreach ($agents as $agent) {
            $agent->getEmailAddresses()->setWeekly($emails);
            $this->assetService->save($agent);

            $status[] = array(
                'name' => $agent->getName(),
                'retention' => $emails
            );
        }

        return $status;
    }
}
