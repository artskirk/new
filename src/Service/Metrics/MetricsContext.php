<?php

namespace Datto\Service\Metrics;

use Datto\Restore\Restore;
use Datto\Asset\Agent\Agent;

/**
 * Context object for metrics collection.
 *
 * @author Sri Ramanujam <sramanujam@datto.com>
 */
class MetricsContext
{
    /** @var Restore[] */
    private $activeRestores;

    /** @var Restore[] */
    private $orphanedRestores;

    /** @var Agent[] */
    private $agents;

    /** @var Agent[] */
    private $directToCloudAgents;

    /** @var Agent[] */
    private $activeDirectToCloudAgents;

    /**
     * @param Restore[] $restores
     */
    public function setActiveRestores(array $restores)
    {
        $this->activeRestores = $restores;
    }

    /**
     * Set orphaned restores array
     *
     * @param Restore[] $orphans
     * @return void
     */
    public function setOrphanedRestores(array $orphans)
    {
        $this->orphanedRestores = $orphans;
    }

    /**
     * Set agents array
     *
     * @param Agent[] $agents
     * @return void
     */
    public function setAgents(array $agents)
    {
        $this->agents = $agents;
    }

    /**
     * Set Direct to Cloud agents array.
     *
     * @param Agent[] $dtcAgents
     * @return void
     */
    public function setDirectToCloudAgents(array $dtcAgents)
    {
        $this->directToCloudAgents = $dtcAgents;
    }

    /**
     * Set active Direct to Cloud agents array.
     *
     * @param array $activeDtcAgents
     * @return void
     */
    public function setActiveDirectToCloudAgents(array $activeDtcAgents)
    {
        $this->activeDirectToCloudAgents = $activeDtcAgents;
    }

    /**
     * @return Restore[]
     */
    public function getActiveRestores(): array
    {
        return $this->activeRestores;
    }

    /**
     * @return Restore[]
     */
    public function getOrphanedRestores(): array
    {
        return $this->orphanedRestores;
    }

    /**
     * @return Agent[]
     */
    public function getDirectToCloudAgents(): array
    {
        return $this->directToCloudAgents;
    }

    /**
     * @return Agent[]
     */
    public function getActiveDirectToCloudAgents(): array
    {
        return $this->activeDirectToCloudAgents;
    }

    /**
     * @return Agent[]
     */
    public function getAgents(): array
    {
        return $this->agents;
    }
}
