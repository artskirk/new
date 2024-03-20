<?php

namespace Datto\Asset\Agent\Job;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentPlatform;
use Exception;

/**
 * Service class used to retrieve job information from an agent.
 * Currently only supports Shadow Snap agents.
 *
 * @author John Roland <jroland@datto.com>
 */
class JobService
{
    /** @var AgentJobStatusRetriever */
    private $jobRetriever;

    /**
     * @param AgentJobStatusRetriever|null $jobRetriever
     */
    public function __construct(
        AgentJobStatusRetriever $jobRetriever = null
    ) {
        $this->jobRetriever = $jobRetriever ?: new AgentJobStatusRetriever();
    }

    /**
     * @param Agent $agent
     * @return JobList[]
     */
    public function get(Agent $agent)
    {
        $retriever = null;
        if (!$agent->getPlatform()->isAgentless()) {
            $retriever = $this->jobRetriever->get($agent);
        } else {
            throw new Exception('Cannot retrieve jobs for agent ' . $agent->getKeyName());
        }
        return $retriever;
    }
}
