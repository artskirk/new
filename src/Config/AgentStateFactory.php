<?php


namespace Datto\Config;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;

/**
 * A factory to build a means to access an agents state information
 *
 * @author Shawn Carpenter <scarpenter@datto.com>
 */
class AgentStateFactory
{
    /** @var Filesystem */
    private $filesystem;

    /**
     * @param Filesystem $filesystem
     */
    public function __construct(Filesystem $filesystem = null)
    {
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
    }

    /**
     * Create new instance of AgentState for the given key
     *
     * @param string $agentKey
     * @return AgentState
     */
    public function create(string $agentKey): AgentState
    {
        return new AgentState($agentKey, $this->filesystem);
    }
}
