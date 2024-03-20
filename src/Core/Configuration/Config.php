<?php

namespace Datto\Core\Configuration;

/**
 * Interface Config
 *
 * An interface for Config classes
 */
interface Config
{
    /**
     * Get config value
     *
     * @param string|null $key
     * @param bool $default
     * @return mixed
     */
    public function get(string $key = null, $default = false);

    /**
     * Set config value, preserves quotes
     *
     * @param string|null $key
     * @param $value
     * @return bool
     */
    public function set(string $key = null, $value): bool;

    /**
     * Determine if key has a value
     *
     * @param string|null $key
     * @return bool
     */
    public function has(string $key = null): bool;

    /**
     * Clear the key value if it exists
     *
     * @param string|null $key
     * @return bool
     */
    public function clear(string $key = null): bool;

    /**
     * Get the raw value, or return default
     *
     * @param string|null $key
     * @param bool $default
     * @return mixed
     */
    public function getRaw(string $key = null, $default = false);

    /**
     * Set the raw value
     *
     * @param string|null $key
     * @param $value
     * @return bool
     */
    public function setRaw(string $key = null, $value): bool;

    /**
     * Load a config record
     *
     * @param ConfigRecordInterface $record instance to be loaded from key file
     * @return bool true if the key file exists, and the record was loaded, otherwise false
     */
    public function loadRecord(ConfigRecordInterface $record): bool;

    /**
     * Save a config record to a key file
     *
     * @param ConfigRecordInterface $record
     */
    public function saveRecord(ConfigRecordInterface $record);

    /**
     * Delete existing key file associated with the config record
     *
     * @param ConfigRecordInterface $record
     */
    public function clearRecord(ConfigRecordInterface $record);
}
