<?php

namespace Datto\Backup;

use Datto\Config\AgentConfig;

/**
 * Represents the current backup job ID.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class CurrentJob
{
    const UUID_KEY_NAME = 'currentJob';

    /** @var AgentConfig */
    private $agentConfig;

    /**
     * @param AgentConfig $agentConfig
     */
    public function __construct(AgentConfig $agentConfig)
    {
        $this->agentConfig = $agentConfig;
    }

    /**
     * Retrieve the UUID for the current job or empty if not set.
     *
     * @return string
     */
    public function getUuid(): string
    {
        return (string)$this->agentConfig->get(static::UUID_KEY_NAME);
    }

    /**
     * Save the UUID for the current job.
     *
     * @param string $value
     */
    public function saveUuid(string $value)
    {
        $this->agentConfig->set(static::UUID_KEY_NAME, $value);
    }

    /**
     * Clean up the UUID for the current job.
     */
    public function cleanup()
    {
        $this->agentConfig->clear(static::UUID_KEY_NAME);
    }
}
