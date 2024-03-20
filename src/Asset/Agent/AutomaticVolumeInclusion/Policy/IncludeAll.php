<?php

namespace Datto\Asset\Agent\AutomaticVolumeInclusion\Policy;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AutomaticVolumeInclusion\Policy;
use Datto\Asset\Agent\AutomaticVolumeInclusion\InclusionResult;
use Datto\Asset\Agent\VolumesService;

class IncludeAll implements Policy
{
    private VolumesService $volumesService;

    public function __construct(VolumesService $volumesService)
    {
        $this->volumesService = $volumesService;
    }

    /**
     * @param Agent $agent
     * @return InclusionResult
     */
    public function apply(Agent $agent): InclusionResult
    {
        $updateCloud = false;

        foreach ($agent->getVolumes()->getArrayCopy() as $volume) {
            if (!$volume->isIncluded()) {
                $this->volumesService->includeByGuid($agent->getKeyName(), $volume->getGuid());
                $updateCloud = true;
            }
        }

        return new InclusionResult($updateCloud);
    }
}
