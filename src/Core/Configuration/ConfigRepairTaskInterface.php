<?php

namespace Datto\Core\Configuration;

/**
 * This interface represents a single config repair task used by ConfigurationRepairService.
 * Task implementations should be idempotent
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
interface ConfigRepairTaskInterface
{
    /**
     * Execute the task
     * @return bool true if the task modified config, else false
     */
    public function run(): bool;
}
