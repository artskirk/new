<?php

namespace Datto\Asset\Agent\AutomaticVolumeInclusion;

class InclusionResult
{
    private bool $inclusionsChanged;

    public function __construct(bool $inclusionsChanged)
    {
        $this->inclusionsChanged = $inclusionsChanged;
    }

    /**
     * @return bool
     */
    public function hasChanges(): bool
    {
        return $this->inclusionsChanged;
    }
}
