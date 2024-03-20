<?php

namespace Datto\App\Controller\Api\V1\Device\Asset\Agent\DatasetClone\Volume;

use Datto\App\Controller\Api\V1\Device\Asset\Agent\AbstractAgentEndpoint;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\DatasetClone\MirrorService;

class Mirror extends AbstractAgentEndpoint
{
    /** @var MirrorService */
    private $mirrorService;

    public function __construct(AgentService $agentService, MirrorService $mirrorService)
    {
        parent::__construct($agentService);

        $this->mirrorService = $mirrorService;
    }

    /**
     * Mirror an image
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_BMR")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_BMR_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agent" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~"),
     *   "snapshot" = @Symfony\Component\Validator\Constraints\Type(type = "numeric")
     * })
     * @param string $bmrIP the IP of the BMR device
     * @param string $agent the agent to mirror
     * @param string $guid the guid of the volume to mirror
     * @param string $snapshot the latest recovery point mirrored to the BMR for this volume
     * @param int|null $agentSnapshot
     *      The agent's snapshot to mirror. If not set, the latest snapshot will
     *      be used.
     *
     * @return array
     */
    public function start($bmrIP, $agent, $guid, $snapshot, $agentSnapshot = null)
    {
        $started = $this->mirrorService->start($bmrIP, $agent, $guid, $snapshot, $agentSnapshot);

        return array("started" => $started);
    }

    /**
     * Returns status on a mirror in progress.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_BMR")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_BMR_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agent" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~"),
     * })
     * @param string $bmrIP the IP of the BMR device
     * @param string $agent the agent to mirror
     * @param string $guid the guid to mirror
     * @param string $point the point to mirror
     *
     * @return array
     */
    public function status($bmrIP, $agent, $guid, $point)
    {
        return $this->mirrorService->status($bmrIP, $agent, $guid, $point);
    }

    /**
     * Cleans up iscsi leftovers from mirror job
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_BMR")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_BMR_WRITE")
     * @param string $bmrIP the IP of the BMR device
     * @return array
     */
    public function cleanup($bmrIP)
    {
        $this->mirrorService->cleanup($bmrIP);

        return array("complete" => true);
    }
}
