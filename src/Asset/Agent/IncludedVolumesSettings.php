<?php

namespace Datto\Asset\Agent;

/**
 * Manages settings for volumes that are to be included in backups for an agent.
 */
class IncludedVolumesSettings
{
    /** @var string[] */
    private array $volumes;

    /**
     * @param string[] $volumes An array of guids for the included volumes
     */
    public function __construct(array $volumes)
    {
        $this->volumes = $volumes;
    }

    public function getIncludedList(): array
    {
        return $this->volumes;
    }

    public function isIncluded(string $guid): bool
    {
        return in_array($guid, $this->volumes);
    }

    public function add(string $guid): void
    {
        if (!$this->isIncluded($guid)) {
            $this->volumes[] = $guid;
        }
    }

    public function remove(string $guid): void
    {
        if ($this->isIncluded($guid)) {
            $key = array_search($guid, $this->volumes);
            unset($this->volumes[$key]);
        }

        // Fix array to key at zero so json saves as an array not an object
        $this->volumes = array_values($this->volumes);
    }
}
