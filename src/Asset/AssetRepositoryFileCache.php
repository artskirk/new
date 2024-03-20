<?php

namespace Datto\Asset;

/**
 * Per agent file contents for all loaded agents
 *
 * This class allows us to present a static file cache property from a non-static class
 * that can be injected or mocked out for testing.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class AssetRepositoryFileCache
{
    /** @var string[][] Per agent file contents for all loaded agents */
    protected static $fileCache;

    /**
     * Retrieve the file cache
     *
     * @return string[][] Per agent file contents for all loaded agents
     */
    public function get()
    {
        return self::$fileCache;
    }

    /**
     * Set the file cache
     *
     * @param string $fileKey Key file name
     * @param string[] $fileArray Key file values
     */
    public function set($fileKey, $fileArray): void
    {
        self::$fileCache[$fileKey] = $fileArray;
    }

    /**
     * Clear the cache
     *
     * Be cautious with this function.
     * It will clear the static cache for all AssetRepositoryFileCache objects.
     */
    public function clearCache(): void
    {
        self::$fileCache = array();
    }
}
