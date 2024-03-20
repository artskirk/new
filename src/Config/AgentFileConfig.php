<?php

namespace Datto\Config;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;

/**
 * Base class for file config that operates on agents
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
abstract class AgentFileConfig extends FileConfig
{
    public const BASE_KEY_CONFIG_PATH = '/tmp'; // This directory should be overriden by the derived classes

    /** @var string */
    protected $agentKey;

    public function __construct(string $agentKey, Filesystem $filesystem = null)
    {
        $this->agentKey = $agentKey;
        $baseConfigPath = static::BASE_KEY_CONFIG_PATH . '/' . $this->agentKey;

        parent::__construct($baseConfigPath, $filesystem ?: new Filesystem(new ProcessFactory()));
    }

    /**
     * Get the agent key name for this config.
     *
     * @return string
     */
    public function getKeyName(): string
    {
        return $this->agentKey;
    }

    /**
     * Gets the full path and name of the key file given its name.
     *
     * @param string $key Key name.
     * @return string Filesystem path to key file.
     */
    public function getKeyFilePath($key): string
    {
        return $this->baseConfigPath . '.' . $key;
    }

    /**
     * @return string[] All the keys that exist in agent state for this agent
     */
    public function getAllKeys(): array
    {
        $files = $this->filesystem->glob($this->getKeyFilePath('*')) ?: [];

        $keys = array_map(function (string $file) {
            return str_replace($this->baseConfigPath . '.', '', $file);
        }, $files);

        return $keys;
    }

    /**
     * Deletes all the agent state keys for this agent
     *
     * @param string[] $keysToKeep Array of keys to avoid deleting
     */
    public function deleteAllKeys(array $keysToKeep)
    {
        foreach ($this->getAllKeys() as $key) {
            if (!in_array($key, $keysToKeep)) {
                $this->clear($key);
            }
        }
    }
}
