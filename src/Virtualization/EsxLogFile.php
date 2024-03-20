<?php

namespace Datto\Virtualization;

/**
 * Provides information needed to reference ESX log files.
 *
 * @author John Fury Christ <jchrist@datto.com>
 */
class EsxLogFile
{
    /** @var string The path to the log name on the ESX host */
    private $path;

    /** @var string The key for the logfile specified by the path */
    private $key;

    /**
     * @param string $path The path to the log name on the ESX host
     * @param string $key The key for the logfile specified by the path
     */
    public function __construct(
        $path,
        $key
    ) {
        $this->path = $path;
        $this->key = $key;
    }

    /**
     * @return string The path to the log name on the ESX host
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * @return string The key for the logfile specified by the path
     */
    public function getKey()
    {
        return $this->key;
    }
}
