<?php

namespace Datto\Utility\Network;

/**
 * CachingNmcli is a wrapper for nmcli that caches connection and device information to improve speed.
 *
 * Calling a read method multiple times will result in only one expensive nmcli call.
 * The entire cache is cleared whenever a write operation occurs and will be regenerated on the next read call.
 * For this reason, it is important to avoid interleaving read and write operations as performance will degrade quickly.
 * Caching occurs within a process level and is not shared across processes.
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class CachingNmcli extends Nmcli
{
    private static array $cache = [];

    /**
     * Call this if something external to CachingNmcli has changed network state
     *
     * Note that cache will be cleared automatically whenever any write operations are called on CachingNmcli so it is
     * not normally necessary to call this method.
     */
    public function clearCache(): void
    {
        self::$cache = [];
    }

    //////////////////////////////////////////////////////////////////////////
    // Read operations - These cache their results so future calls are quick
    //////////////////////////////////////////////////////////////////////////

    public function connectionShowDetails(): array
    {
        $cacheKey = 'connectionShowDetails';

        if (!isset(self::$cache[$cacheKey])) {
            self::$cache[$cacheKey] = parent::connectionShowDetails();
        }

        return self::$cache[$cacheKey];
    }

    public function connectionShow(): array
    {
        $cacheKey = 'connectionShow';

        if (!isset(self::$cache[$cacheKey])) {
            self::$cache[$cacheKey] = parent::connectionShow();
        }

        return self::$cache[$cacheKey];
    }

    public function deviceShowDetails(): array
    {
        $cacheKey = 'deviceShowDetails';

        if (!isset(self::$cache[$cacheKey])) {
            self::$cache[$cacheKey] = parent::deviceShowDetails();
        }

        return self::$cache[$cacheKey];
    }

    /////////////////////////////////////////////////
    // Write operations - These all clear the cache
    /////////////////////////////////////////////////

    public function connectionAdd(string $type, string $iface, string $name, array $extra = []): array
    {
        $this->clearCache();
        return parent::connectionAdd($type, $iface, $name, $extra);
    }

    public function connectionDelete(string $identifier): void
    {
        $this->clearCache();
        parent::connectionDelete($identifier);
    }

    public function connectionModify(string $identifier, array $fields): void
    {
        $this->clearCache();
        parent::connectionModify($identifier, $fields);
    }

    public function connectionUp(string $identifier, int $wait): void
    {
        $this->clearCache();
        parent::connectionUp($identifier, $wait);
    }

    public function connectionDown(string $identifier): void
    {
        $this->clearCache();
        parent::connectionDown($identifier);
    }

    public function connectionReload(): void
    {
        $this->clearCache();
        parent::connectionReload();
    }

    public function networkingOn(): void
    {
        $this->clearCache();
        parent::networkingOn();
    }

    public function networkingOff(): void
    {
        $this->clearCache();
        parent::networkingOff();
    }
}
