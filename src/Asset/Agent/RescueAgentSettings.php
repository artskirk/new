<?php

namespace Datto\Asset\Agent;

/**
 * Settings for dealing with rescue agents.
 *
 * @author Christopher R. Wicks <cwicks@datto.com>
 */
class RescueAgentSettings
{
    /** @var string */
    private $sourceAgentKeyName;

    /** @var int */
    private $sourceAgentSnapshotEpoch;

    /**
     * @param string $sourceAgentKeyName
     * @param int $sourceAgentSnapshotEpoch
     */
    public function __construct(
        $sourceAgentKeyName,
        $sourceAgentSnapshotEpoch
    ) {
        $this->sourceAgentKeyName = $sourceAgentKeyName;
        $this->sourceAgentSnapshotEpoch = $sourceAgentSnapshotEpoch;
    }

    /**
     * Get the name of the source agent that this rescue was initialized from.
     *
     * @return string
     */
    public function getSourceAgentKeyName()
    {
        return $this->sourceAgentKeyName;
    }

    /**
     * Get the snapshot epoch of the snapshot (on the source agent) that this rescue was initialized from.
     *
     * @return int
     */
    public function getSourceAgentSnapshotEpoch()
    {
        return $this->sourceAgentSnapshotEpoch;
    }
}
