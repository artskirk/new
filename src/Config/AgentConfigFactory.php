<?php

namespace Datto\Config;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;

/**
 * A factory to build a means to access an agents configuration information
 *
 * @author Andrew Mitchell <amitchell@datto.com>
 */

class AgentConfigFactory
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
     * Create new instance of AgentConfig for the given key
     *
     * @param string $agentKey
     * @return AgentConfig
     */
    public function create(string $agentKey): AgentConfig
    {
        return new AgentConfig($agentKey, $this->filesystem);
    }

    /**
     * Get all the keyNames of assets on this device.
     *
     * Note: This can return keyNames for assets that cannot be unserialized
     *
     * @return array
     */
    public function getAllKeyNames(): array
    {
        // all assets must have an agentInfo file to work
        return $this->getAllKeyNamesWithKey('agentInfo');
    }

    /**
     * Check whether the specified key exists for any asset
     * This can be used to quickly check whether specific asset types exist, such as archived or shadowsnap agents.
     */
    public function keyExistsForAnyAsset(string $key): bool
    {
        return count($this->getAllKeyNamesWithKey($key)) > 0;
    }

    /**
     * Gets the list of asset keyNames that have the specified key file.
     *
     * For example, when these files exist:
     *   /datto/config/keys/59e41a123c9946c3a5f3bc3528ed0259.agentInfo
     *   /datto/config/keys/myhostname.partnerdomain.com.agentInfo
     *   /datto/config/keys/10.40.70.80.agentInfo
     *   /datto/config/keys/04357392614c43f1bfb0f61e2934d586.removing
     *
     * then getAllKeyNamesWithKey('agentInfo') returns:
     *   ['59e41a123c9946c3a5f3bc3528ed0259', 'myhostname.partnerdomain.com', '10.40.70.80']
     */
    public function getAllKeyNamesWithKey(string $key): array
    {
        $files = $this->filesystem->glob(AgentConfig::BASE_KEY_CONFIG_PATH . "/*.$key");
        $keyNames = [];
        foreach ($files as $file) {
            $keyNames[] = basename($file, ".$key");
        }

        return $keyNames;
    }
}
