<?php

namespace Datto\Config;

use Datto\Common\Utility\Filesystem;

/**
* Access configuration settings of a specific agent, stored in shm
*/
class AgentShmConfig extends FileConfig
{
    /** @var string */
    private $agentKey;

    /**
     * @param string $agentKey
     * @param Filesystem $filesystem
     */
    public function __construct(
        string $agentKey,
        Filesystem $filesystem
    ) {
        $this->agentKey = $agentKey;
        $baseConfigPath = ShmConfig::BASE_SHM_PATH . '/' . $this->agentKey;
        parent::__construct($baseConfigPath, $filesystem);
    }

    /**
     * @param string $key
     * @return string
     */
    public function getKeyFilePath($key): string
    {
        return $this->baseConfigPath . '.' . $key;
    }

    /**
     * @return string
     */
    public function getAgentKey(): string
    {
        return $this->agentKey;
    }
}
