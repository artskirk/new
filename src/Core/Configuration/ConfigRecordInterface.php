<?php

namespace Datto\Core\Configuration;

/**
 * An configuration object that can be read and written by @see Config
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
interface ConfigRecordInterface
{
    /**
     * @return string name of key file that this config record will be stored to
     */
    public function getKeyName(): string;

    /**
     * Deserialize the raw file contents into this config record instance
     *
     * @param string $raw key file contents
     */
    public function unserialize(string $raw);

    /**
     * Serialize the config record for persistence to a key file
     *
     * @return string
     */
    public function serialize(): string;
}
