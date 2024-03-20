<?php

namespace Datto\Utility\Systemd;

use Datto\Common\Resource\ProcessFactory;
use Exception;

/**
 * Basic wrapper around systemctl commands.
 *
 * @author Andrew Mitchell <amitchell@datto.com>
 */
class Systemctl
{
    const SYSTEMCTL_CMD = '/bin/systemctl';

    /** @var ProcessFactory */
    private $processFactory;

    public function __construct(ProcessFactory $processFactory = null)
    {
        $this->processFactory = $processFactory ?? new ProcessFactory();
    }

    /**
     * Reload the configuration of a running service
     *
     * @param string $service
     */
    public function reload(string $service)
    {
        $process = $this->processFactory->get([self::SYSTEMCTL_CMD, "reload", $service]);
        $process->mustRun();
    }

    /**
     * Restart a service
     *
     * @param string $service
     */
    public function restart(string $service)
    {
        $process = $this->processFactory->get([self::SYSTEMCTL_CMD, 'restart', $service]);
        $process->mustRun();
    }

    /**
     * Start a service
     *
     * @param string $service
     */
    public function start(string $service)
    {
        $process = $this->processFactory->get([self::SYSTEMCTL_CMD, "start", $service]);
        $process->mustRun();
    }

    /**
     * Stop a service
     *
     * @param string $service
     */
    public function stop(string $service)
    {
        $process = $this->processFactory->get([self::SYSTEMCTL_CMD, "stop", $service]);
        $process->mustRun();
    }

    /**
     * Enable a service to automatically start on its default run levels
     *
     * @param string $service
     */
    public function enable(string $service)
    {
        $process = $this->processFactory->get([self::SYSTEMCTL_CMD, "enable", $service]);
        $process->setTimeout(120);
        $process->mustRun();
    }

    /**
     * Disable a service so it does not automatically start
     *
     * @param string $service
     */
    public function disable(string $service)
    {
        $process = $this->processFactory->get([self::SYSTEMCTL_CMD, 'disable', $service]);
        $process->mustRun();
    }

    /**
     * Mask a service, preventing it from being enabled or started as a dependency of another service
     *
     * @param string $service
     */
    public function mask(string $service)
    {
        $process = $this->processFactory->get([self::SYSTEMCTL_CMD, 'mask', $service]);
        $process->mustRun();
    }

    /**
     * Unmask a service, allowing it to be enabled or started as a dependency of another service
     *
     * @param string $service
     */
    public function unmask(string $service)
    {
        $process = $this->processFactory->get([self::SYSTEMCTL_CMD, 'unmask', $service]);
        $process->mustRun();
    }

    /**
     * Check if the service is currently running.
     *
     * @param string $service
     * @return bool
     */
    public function isActive(string $service): bool
    {
        $process = $this->processFactory->get([self::SYSTEMCTL_CMD, 'is-active', $service]);
        $process->run();

        return $process->getExitCode() === 0;
    }

    /**
     * Check if the service is currently enabled.
     *
     * @param string $service
     * @return bool
     */
    public function isEnabled(string $service): bool
    {
        $process = $this->processFactory->get([self::SYSTEMCTL_CMD, 'is-enabled', $service]);
        $process->run();

        return $process->getExitCode() === 0;
    }

    /**
     * Check if the service is currently masked.
     *
     * @param string $service
     * @return bool
     */
    public function isMasked(string $service): bool
    {
        $process = $this->processFactory->get([self::SYSTEMCTL_CMD, 'is-enabled', $service]);
        $process->run();

        return trim($process->getOutput()) === 'masked';
    }

    /**
     * Check if the service's state is failed.
     *
     * @param string $service
     * @return bool
     */
    public function isFailed(string $service): bool
    {
        $process = $this->processFactory->get([self::SYSTEMCTL_CMD, 'is-failed', $service]);
        $process->run();

        return $process->getExitCode() === 0;
    }

    /**
     * Clears the 'failed' state of the given service (if applicable).
     * This method will throw an exception for non-failed services!
     *
     * @param string $service
     */
    public function resetFailed(string $service)
    {
        $process = $this->processFactory->get([self::SYSTEMCTL_CMD, 'reset-failed', $service]);
        $process->mustRun();
    }
    
    /**
     * Checks the system's current state according to systemctl, and returns it
     */
    public function isSystemRunning(): SystemdRunningStatus
    {
        $process = $this->processFactory->get([self::SYSTEMCTL_CMD, 'is-system-running']);
        $process->run();

        return SystemdRunningStatus::memberByValue(trim($process->getOutput()));
    }

    /**
     * Checks the system's running state, and errors if it is not running/degraded
     */
    public function assertSystemRunning()
    {
        $state = $this->isSystemRunning();
        if ($state !== SystemdRunningStatus::RUNNING() && $state !== SystemdRunningStatus::DEGRADED()) {
            throw new Exception(
                "System is not running, system is currently '$state'. The requested action cannot be performed."
            );
        }
    }
}
