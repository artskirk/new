<?php

namespace Datto\Util;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;
use RuntimeException;

/**
 * Helper class to manage and execute functions in a  container.
 *
 * Note that since this container is running a very old version of systemd, it does not support many interactive
 * features and flags to systemd-run (e.g. --wait, --pid, --pipe).
 *
 * Any generic container management framework should take this into account, and not implement the methods
 * in the same manner as implemented inside this class
 */
class ContainerManager implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var string The system path where machines and systemd-nspawn files are kept */
    public const MACHINE_FILES_PATH = '/var/lib/machines/';
    public const NSPAWN_FILES_PATH = '/etc/systemd/nspawn/';

    private string $container;
    private Filesystem $filesystem;
    private ProcessFactory $processFactory;

    public function __construct(
        Filesystem $filesystem,
        ProcessFactory $processFactory
    ) {
        $this->filesystem = $filesystem;
        $this->processFactory = $processFactory;
        $this->container = '';
    }

    public function setContainer(string $container): void
    {
        $this->container = $container;
    }

    /**
     * Import a tar file to the machine
     *
     * @param string $tarFile The full path to the tar file to import
     * @param string $nspawnTemplate An optional path to a file to use as a systemd.nspawn file
     */
    public function importTar(string $tarFile, string $nspawnTemplate = ''): void
    {
        // If the container already exists, do nothing
        if ($this->exists()) {
            $this->logger->debug('CMG0009 Skipping import of existing container image', [
                'container' => $this->container
            ]);
            return;
        }

        $this->logger->info('CMG0001 Importing container from tar file', [
            'container' => $this->container,
            'tarFile' => $tarFile
        ]);

        // If we were given a valid nspawn template, copy it
        if ($nspawnTemplate && $this->filesystem->exists($nspawnTemplate)) {
            $this->filesystem->copy($nspawnTemplate, self::NSPAWN_FILES_PATH . $this->container . '.nspawn');
        }

        $this->processFactory->get(['machinectl', 'import-tar', $tarFile, $this->container])
            ->mustRun();
    }

    /**
     * Start the container and wait for it to reach a steady state
     */
    public function start(): bool
    {
        $this->logger->info('CMG0002 Starting container', [
            'container' => $this->container,
        ]);

        $this->processFactory->get(['machinectl', 'start', $this->container])
            ->setTimeout(null)
            ->mustRun();

        return $this->isStarted() && $this->isReady();
    }

    public function enable(): void
    {
        $this->logger->info('CMG0003 Enabling container to start at boot', [
            'container' => $this->container
        ]);

        $this->processFactory->get(['machinectl', 'enable', $this->container])
            ->mustRun();
    }

    /**
     * Stop the container and wait for it to fully terminate
     *
     * @param bool $force Force a termination, rather than a clean shutdown
     */
    public function stop(bool $force = false): void
    {
        $this->logger->info('CMG0004 Stopping container', [
            'container' => $this->container,
        ]);

        if (!$this->isStarted()) {
            return;
        }

        $this->processFactory->get(['machinectl', $force ? 'terminate' : 'poweroff', $this->container])
            ->mustRun();

        $stopped = $this->waitFor(function () {
            return !$this->isStarted();
        }, 10);

        if (!$stopped) {
            throw new RuntimeException('Timed out waiting for the container to stop');
        }
    }

    public function disable(): void
    {
        $this->logger->info('CMG0005 Disabling container from starting at boot', [
            'container' => $this->container
        ]);

        if ($this->isEnabled()) {
            $this->processFactory->get(['machinectl', 'disable', $this->container])
                ->mustRun();
        }
    }

    public function remove(): void
    {
        $this->logger->info('CMG0006 Removing container', [
            'container' => $this->container,
        ]);

        if ($this->exists()) {
            $this->processFactory->get(['machinectl', 'remove', $this->container])
                ->mustRun();
        }
    }

    /**
     * Determine if the container image exists and has been imported
     * @return bool true if the container image exists
     */
    public function exists(): bool
    {
        $exitCode = $this->processFactory->get(['machinectl', 'image-status', $this->container])
            ->run();
        return $exitCode === 0;
    }

    /**
     * Determine if the container is started
     */
    public function isStarted(): bool
    {
        $exitCode = $this->processFactory->get(['machinectl', 'status', $this->container])
            ->run();
        return $exitCode === 0;
    }

    /**
     * Determine if the container is ready and has finished booting
     */
    public function isReady(): bool
    {
        $exitCode = $this->processFactory->get(['systemctl', '-M', $this->container, 'is-system-running'])
            ->run();
        return $exitCode === 0;
    }

    /**
     * Determine if the container is currently enabled as a systemd service to launch at startup
     */
    public function isEnabled(): bool
    {
        $exitCode = $this->processFactory->get(['systemctl', 'is-enabled', 'systemd-nspawn@' . $this->container . '.service'])
            ->run();
        return $exitCode === 0;
    }

    /**
     * Run a command in the container's filesystem and context.
     *
     * @param array $command The command to run in the container
     * @param array $env Additional environment variables passed to the command
     * @param float $timeout The time in seconds to wait for the command to complete
     *
     * @return string The command output
     */
    public function runCommand(array $command, array $env = [], float $timeout = 60.0): string
    {
        // If the container is ready, we can use a different mechanism which actually
        // executes commands on the systemd init process running inside
        if ($this->isStarted()) {
            return $this->executeInRunningContainer($command, $env, $timeout);
        } else {
            return $this->executeInStoppedContainer($command, $env, $timeout);
        }
    }

    /**
     * Get the journalctl log for the given unit in the container's context
     *
     * @param string $unit
     * @return string The contents of the unit's journald output
     */
    public function getUnitLog(string $unit): string
    {
        return $this->processFactory->get(['journalctl', '-M', $this->container, '-u', $unit, '-o', 'cat'])
            ->mustRun()
            ->getOutput();
    }

    /**
     * Execute a command in a stopped container. This is used primarily for operations which
     * interact with the filesystem. The init process and any daemon services will not be
     * running at this time.
     *
     * @param array $command The command to run in the container's context
     * @param float $timeout The time in seconds to wait for the command to complete
     *
     * @return string The command output
     */
    private function executeInStoppedContainer(array $command, array $env, float $timeout): string
    {
        $cmdBase = ['systemd-nspawn', '-q', '-D', self::MACHINE_FILES_PATH . $this->container];

        // Append the environment variables to the command
        foreach ($env as $key => $value) {
            $cmdBase[] = '--setenv=' . $key . '=' . $value;
        }

        return $this->processFactory->get(array_merge($cmdBase, $command))
            ->setTimeout($timeout)
            ->mustRun()
            ->getOutput();
    }

    /**
     * Runs the given command in the container's context.
     *
     * Note that since we are running a very old systemd version inside the container, we can't actually
     * use the `--pipe` and `--pid` flags to the systemd-run command, which would give us the error code,
     * so instead we have to periodically poll systemd for the status of a running unit.
     *
     * @param array $command The command to run inside of the container. Binary paths must be full paths
     *                       and relative to the container.
     * @param float $timeout The time in seconds to wait for the command to complete
     *
     * @return string The command output
     */
    private function executeInRunningContainer(array $command, array $env, float $timeout): string
    {
        $cmdBase = ['systemd-run', '-M', $this->container];

        // Append the environment variables to the command
        foreach ($env as $key => $value) {
            $cmdBase[] = '--setenv=' . $key . '=' . $value;
        }

        $process = $this->processFactory->get(array_merge($cmdBase, $command))
            ->mustRun();

        // Extract the name of the systemd-created unit file from the stderr output
        if (preg_match("/Running as unit: (\S*)/", $process->getErrorOutput(), $matches)) {
            $unit = $matches[1];
        } else {
            throw new RuntimeException('Could not determine unit for service in running container');
        }

        // Once we launch the unit file, it will stick around until it completes successfully
        // (error code of 0) at which point it be removed from the list of systemd services running
        // in the container's context. If it fails, then we can throw an exception with the logs

        // Wait for the service to go inactive
        $started = $this->waitFor(function () use ($unit) {
            return !$this->isUnitActive($unit);
        }, $timeout);

        // If the service did not successfully go active before the timeout, throw an exception
        if (!$started) {
            throw new RuntimeException('The command did not finish in the given time');
        };

        // If the service failed, throw a new exception
        if ($this->isUnitFailed($unit)) {
            throw new RuntimeException('Command Failed:' . PHP_EOL . $this->getUnitLog($unit));
        }

        // Return the unit log. It may have some extra systemd stuff so it's not perfect, but
        // it's about as good as we can get without supporting pipes/ptys inside the container
        return $this->getUnitLog($unit);
    }

    /**
     * Return true if the given unit is reporting as active in the container's context
     *
     * @param string $unit
     * @return bool
     */
    private function isUnitActive(string $unit): bool
    {
        $exitCode = $this->processFactory->get(['systemctl', '-M', $this->container, 'is-active', $unit])
            ->run();

        return $exitCode === 0;
    }

    /**
     * Return whether the given unit is reporting as failed in the container's context
     *
     * @param string $unit
     * @return bool
     */
    private function isUnitFailed(string $unit): bool
    {
        $exitCode = $this->processFactory->get(['systemctl', '-M', $this->container, 'is-failed', $unit])
            ->run();
        return $exitCode === 0;
    }


    /**
     * Waits for a function to return true
     *
     * @param callable $predFn The function to execute and wait for it to return true
     * @param float $timeout The time, in decimal seconds, to wait for the function
     * @param int $interval The interval between tests
     *
     * @return bool True if the predicate evaluated to true before the retries were exhausted
     */
    private function waitFor(callable $predFn, float $timeout, int $interval = 1): bool
    {
        $startTime = microtime(true);
        do {
            if ($predFn()) {
                return true;
            }
            /**
             * @psalm-suppress ArgumentTypeCoercion
             */
            sleep($interval);
        } while (microtime(true) < $startTime + $timeout);

        return false;
    }
}
