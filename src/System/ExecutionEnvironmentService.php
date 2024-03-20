<?php

namespace Datto\System;

/**
 * Wrapper around PHP standard environment functions
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class ExecutionEnvironmentService
{
    const SAPI_CLI = 'cli';

    /**
     * Returns the type of interface between web server and PHP
     *
     * @return string
     */
    public function getSapiName(): string
    {
        return php_sapi_name();
    }

    /**
     * Returns true if PHP is running in the CLI environment
     *
     * @return bool
     */
    public function isCli(): bool
    {
        return $this->getSapiName() === static::SAPI_CLI;
    }
}
