<?php

namespace Datto\Config;

use Datto\Core\Configuration\ConfigRecordInterface;
use JsonSerializable;

/**
 * Base class for Config Records which serialize their content as JSON
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
abstract class JsonConfigRecord implements ConfigRecordInterface, JsonSerializable
{
    /**
     * @inheritdoc
     */
    public function serialize(): string
    {
        return json_encode($this);
    }

    /**
     * @inheritdoc
     */
    public function unserialize(string $raw)
    {
        $vals = json_decode($raw, true);
        $this->load($vals ?? []);
    }

    /**
     * Load the config record instance using values from associative array.
     *
     * @param array $vals
     */
    abstract protected function load(array $vals);
}
