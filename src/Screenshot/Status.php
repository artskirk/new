<?php
namespace Datto\Screenshot;

/**
 * Determine if a VM is ready to have a screenshot taken.
 */
interface Status
{
    /**
     * Check if the verification agent is ready
     *
     * @return bool
     *   TRUE if the machine is ready to have a screenshot taken, otherwise FALSE.
     */
    public function isAgentReady(): bool;

    /**
     * Get the version number of the verification agent
     *
     * @return string|null
     *   Version number of the agent or NULL if no version is applicable.
     */
    public function getVersion();

    /**
     * Check if the machine is ready for a screenshot
     *
     * @return bool
     *   TRUE if the machine is ready to have a screenshot taken, otherwise FALSE.
     */
    public function isLoginManagerReady(): bool;

    /**
     * Start running diagnostic scripts
     *
     * @return bool
     *   TRUE if the scripts were started properly, otherwise FALSE.
     */
    public function startScripts(): bool;

    /**
     * Check the status of running scripts.
     *
     * @return array
     *   Array of script values.
     */
    public function checkScriptStatus(): array;
}
