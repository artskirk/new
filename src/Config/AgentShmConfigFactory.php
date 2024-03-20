<?php

namespace Datto\Config;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;

/**
 * Creates agent shm config objects
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class AgentShmConfigFactory
{
    /** @var Filesystem */
    private $filesystem;

    /**
     * @param Filesystem|null $filesystem
     */
    public function __construct(Filesystem $filesystem = null)
    {
        $this->filesystem = $filesystem ?? new Filesystem(new ProcessFactory());
    }

    /**
     * @param string $agentKey
     * @return AgentShmConfig
     */
    public function create(string $agentKey)
    {
        return new AgentShmConfig($agentKey, $this->filesystem);
    }
}
