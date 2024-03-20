<?php
namespace Datto\Screenshot;

use Datto\Log\DeviceLoggerInterface;

/**
 * Status object for unsupported agents.
 *
 * Always indicates that the machine is not ready.
 * This forces the timeout to fully expire before taking a screenshot.
 */
class UnknownStatus implements Status
{
    /**
     * @var DeviceLoggerInterface $log
     *   A PSR-3 compliant logger for status updates.
     */
    private $log;

    /**
     * UnknownStatus constructor.
     *
     * @param DeviceLoggerInterface $log
     *   A PSR-3 compliant logger for status updates.
     */
    public function __construct(DeviceLoggerInterface $log)
    {
        $this->log = $log;
    }

    /**
     * Always return false for unsupported operating systems.
     *
     * @inheritdoc
     */
    public function isAgentReady(): bool
    {
        $this->log->debug('Unable to check verification agent status for unsupported agents');
        return false;
    }

    /**
     * Always return null for unsupported operating systems.
     *
     * @inheritDoc
     */
    public function getVersion()
    {
        $this->log->debug('Unable to check verification agent version for unsupported agents');
        return null;
    }

    /**
     * Always return false for unsupported operating systems.
     *
     * @inheritDoc
     */
    public function isLoginManagerReady(): bool
    {
        $this->log->debug('Unable to check status for unsupported agents');
        return false;
    }

    /**
     * Always return false for unsupported operating system.
     *
     * @inheritdoc
     */
    public function startScripts($scriptsDir = null): bool
    {
        $this->log->debug('Unable to start scripts for unsupported agents');
        return false;
    }

    /**
     * Always return false for unsupported operating system
     *
     * @inheritdoc
     */
    public function checkScriptStatus(): array
    {
        $this->log->debug('Unable to start scripts for unsupported agent');
        return [];
    }
}
