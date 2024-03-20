<?php

namespace Datto\System\Migration;

/**
 * The context of a device migration
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class Context
{
    /** @var string[] */
    private $sources;

    /** @var string[] */
    private $targets;

    /** @var bool */
    private $enableMaintenanceMode;

    /**
     * Context constructor.
     *
     * @param string[] $targets
     * @param string[]|null $sources
     * @param bool $enableMaintenanceMode
     */
    public function __construct(
        array $targets,
        $sources = null,
        bool $enableMaintenanceMode = true
    ) {
        $this->sources = $sources;
        $this->targets = $targets;
        $this->enableMaintenanceMode = $enableMaintenanceMode;
    }

    /**
     * @return string[]
     */
    public function getTargets() : array
    {
        return $this->targets;
    }

    /**
     * @return string[]
     */
    public function getSources() : array
    {
        return $this->sources;
    }

    /**
     * @return bool
     */
    public function shouldEnableMaintenanceMode(): bool
    {
        return $this->enableMaintenanceMode;
    }
}
