<?php
namespace Datto\Screenshot;

use Datto\Lakitu\Client\InspectionClient;
use Datto\Log\DeviceLoggerInterface;

/**
 * Use Lakitu to determine if a VM is ready to have a screenshot taken.
 */
class LakituStatus implements Status
{
    // If Lakitu is unable to run a script
    const EXIT_UNKNOWN_ERROR = -65535;
    // If Lakitu executed the script
    const EXIT_SCRIPT_SUCCESS = 0;
    const EXIT_SCRIPT_ERROR = 1;

    /**
     *   Set to true after Lakitu is ready.
     *   Used to avoid unnecessary Lakitu readiness checks.
     */
    private bool $lakituReady = false;

    /**
     *   The client object for communicating with Lakitu.
     */
    private InspectionClient $client;

    /**
     *   A PSR-3 compliant logger for status updates.
     */
    private DeviceLoggerInterface $log;

    /**
     * LakituStatus constructor.
     *
     * @param InspectionClient $client
     *   The client object for communicating with Lakitu.
     * @param DeviceLoggerInterface $log
     *   A PSR-3 compliant logger for status updates.
     */
    public function __construct(InspectionClient $client, DeviceLoggerInterface $log)
    {
        $this->client = $client;
        $this->log = $log;
    }

    /**
     * @inheritDoc
     */
    public function isAgentReady(): bool
    {
        if (!$this->lakituReady) {
            $this->lakituReady = $this->client->isLakituReady();
            if ($this->lakituReady) {
                $this->log->debug('Lakitu is ready');
            }
        }

        return $this->lakituReady;
    }

    /**
     * @inheritDoc
     */
    public function getVersion()
    {
        $version = $this->client->getVersion();
        $this->log->debug('Lakitu version: ' . var_export($version, true));
        return $version;
    }

    /**
     * @inheritDoc
     */
    public function isLoginManagerReady(): bool
    {
        $ready = $this->client->isLoginManagerReady();
        if ($ready) {
            $this->log->debug('Login manager is ready');
        }

        return $ready;
    }

    /**
     * @inheritdoc
     */
    public function startScripts(): bool
    {
        $this->log->debug('Starting scripts');
        return $this->client->runScripts();
    }

    /**
     * Check the status of running scripts.
     *
     * @return array
     *   Array of script values.
     */
    public function checkScriptStatus(): array
    {
        $this->log->debug('Checking scripts status');
        return $this->client->getScriptsStatus();
    }
}
