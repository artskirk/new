<?php

namespace Datto\Asset\Agent\Windows;

/**
 * Settings for VSS Writers
 *
 * @author Matt Cheman <mcheman@datto.com>
 */
class VssWriterSettings
{
    /**
     * @var array VSS writers as presented by the agent running on the protected system.
     */
    private array $writers;

    /**
     * @var string[] VSS writer IDs that have been explicitly excluded by the siris.
     */
    private array $excludedIds;

    /**
     * @param array $writers vss writers
     */
    public function __construct(
        array $writers = [],
        array $excludedIds = []
    ) {
        $this->writers = $writers;
        $this->excludedIds = $excludedIds;
    }

    /**
     * Exclude the DFS writer (because it is known to cause issues)
     */
    public function excludeDfsWriter(): void
    {
        $this->excludeWriter('2707761b-2324-473d-88eb-eb007a359533');
    }

    /**
     * Get the VSS writers that are available on the protected system.
     *
     * @return array vss writers
     */
    public function getWriters()
    {
        return $this->writers;
    }

    /**
     * New method for getting VSS writer settings as objects instead of associative arrays.
     *
     * @return VssWriterSetting[]
     */
    public function getAll(): array
    {
        $writers = [];

        foreach ($this->writers as $writer) {
            if (!isset($writer['id'])) {
                continue;
            }

            $id = $writer['id'];
            $name = $writer['name'] ?? null;

            $writers[] = new VssWriterSetting(
                $id,
                $this->isExcluded($id),
                $name
            );
        }

        return $writers;
    }

    /**
     * Get excluded VSS writers regardless of if the writer is present on the protected system.
     *
     * @return string[]
     */
    public function getExcludedIds(): array
    {
        return $this->excludedIds;
    }

    /**
     * Get excluded VSS writers but only ones that are currently available on the protected system.
     *
     * @return string[]
     */
    public function getAvailableExcludedIds(): array
    {
        $ids = [];

        foreach ($this->getAll() as $writer) {
            if ($writer->isExcluded()) {
                $ids[] = $writer->getId();
            }
        }

        return $ids;
    }

    public function isExcluded(string $id): bool
    {
        foreach ($this->excludedIds as $excludedId) {
            if (strtolower($id) === strtolower($excludedId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Excludes the specified writer
     *
     * @param string $id the id of the writer to exclude
     */
    public function excludeWriter($id)
    {
        foreach ($this->excludedIds as $excludedId) {
            if (strtolower($id) === strtolower($excludedId)) {
                return;
            }
        }

        $this->excludedIds[] = $id;
    }

    /**
     * Stops excluding the specified writer
     *
     * @param string $id the id of the writer to stop excluding
     */
    public function includeWriter($id)
    {
        foreach ($this->excludedIds as $key => $excludedId) {
            if (strtolower($id) === strtolower($excludedId)) {
                unset($this->excludedIds[$key]);
                $this->excludedIds = array_values($this->excludedIds);

                return;
            }
        }
    }
}
