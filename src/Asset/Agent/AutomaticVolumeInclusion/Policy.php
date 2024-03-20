<?php

namespace Datto\Asset\Agent\AutomaticVolumeInclusion;

use Datto\Asset\Agent\Agent;

interface Policy
{
    /**
     * @param Agent $agent
     * @return InclusionResult
     */
    public function apply(Agent $agent): InclusionResult;
}
