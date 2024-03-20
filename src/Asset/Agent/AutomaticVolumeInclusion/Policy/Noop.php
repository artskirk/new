<?php

namespace Datto\Asset\Agent\AutomaticVolumeInclusion\Policy;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AutomaticVolumeInclusion\Policy;
use Datto\Asset\Agent\AutomaticVolumeInclusion\InclusionResult;

class Noop implements Policy
{
    /**
     * @param Agent $agent
     * @return InclusionResult
     */
    public function apply(Agent $agent): InclusionResult
    {
        return new InclusionResult(false);
    }
}
