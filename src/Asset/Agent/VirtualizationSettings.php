<?php

namespace Datto\Asset\Agent;

use Datto\Config\AgentConfigFactory;
use Datto\Virtualization\Hypervisor\Config\EsxVmSettings;
use Datto\Virtualization\Hypervisor\Config\KvmVmSettings;

/**
 * Class for Virtualization Settings.
 *
 * Developer note:
 *   Be sure to make all properties injectable through the constructor, so that the
 *   state of the object can be recreated from a config file. Do NOT provide public
 *   setters for properties that could set the object into an inconsistent state,
 *   e.g. don't provide a setEnabled() method.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 * @author John Fury Christ <jchrist@datto.com>
 *
 */
class VirtualizationSettings
{
    const ENVIRONMENT_MODERN = 0;
    const ENVIRONMENT_LEGACY = 1;

    /** @var int ENVIRONMENT_MODERN or ENVIRONMENT_LEGACY */
    private $environment;

    /**
     * Construct a VirtualizationSettings object.
     *
     * @param int $environment Virtualization environment (ENVIRONMENT_MODERN or ENVIRONMENT_LEGACY)
     */
    public function __construct(
        $environment = null
    ) {
        $this->environment = $environment ?: self::ENVIRONMENT_MODERN;
    }

    /**
     * Retrieve the environment setting.
     *
     * @return int ENVIRONMENT_MODERN or ENVIRONMENT_LEGACY
     */
    public function getEnvironment()
    {
        return (int)$this->environment;
    }

    /**
     * Set the environment settings.
     * Valid values are ENVIRONMENT_MODERN and ENVIRONMENT_LEGACY.
     *
     * @param $environment int ENVIRONMENT_MODERN or ENVIRONMENT_LEGACY
     */
    public function setEnvironment($environment): void
    {
        $this->environment = (int)$environment;
    }
}
