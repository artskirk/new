<?php

namespace Datto\Utility\Cloud;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Utility\File\LockFactory;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Provides an interface to the speedsync command line utility.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class SpeedSync
{
    const SPEEDSYNC_COMMAND = 'speedsync';
    const TIMEOUT_ONE_DAY = 86400;
    const TIMEOUT_TEN_MINUTES = 600;
    const MIRROR_TIMEOUT_SECONDS = 300;

    const OPTION_COMPRESSION = 'compression';
    const OPTION_COMPRESSION_XZ = 'xz';
    const OPTION_COMPRESSION_GZIP = 'gz';
    const OPTION_COMPRESSION_ZFS = 'zfs';

    const TARGET_INFO_CACHE_FILE = '/var/cache/datto/device/speedsync/targetInfo';

    const REFRESH_SCREEN = 'speedsyncRefresh';

    private ProcessFactory $processFactory;
    private LockFactory $lockFactory;
    private Filesystem $filesystem;
    private LoggerInterface $logger;

    public function __construct(
        ProcessFactory $processFactory,
        LockFactory $lockFactory,
        Filesystem $filesystem,
        LoggerInterface $logger
    ) {
        $this->processFactory = $processFactory;
        $this->lockFactory = $lockFactory;
        $this->filesystem = $filesystem;
        $this->logger = $logger;
    }

    /**
     * Refresh of inbound and outbound dataset metadata based on vectors that exist in speedsync-master.
     *
     * @return int
     */
    public function refreshVectorsInBackground(): int
    {
        $commandLine = [self::SPEEDSYNC_COMMAND, 'refresh', 'vectors'];
        return $this->runCommandLine($commandLine)->getExitCode();
    }

    /**
     * Adds a ZFS dataset path to SpeedSync.
     *
     * @param string $zfsPath
     * @return int
     */
    public function addDataset(string $zfsPath): int
    {
        $commandLine = [static::SPEEDSYNC_COMMAND, 'add', $zfsPath];
        $process = $this->runCommandLine($commandLine);

        $result = $process->getExitCode();
        $this->logger->debug("SYN4530 ExitCode=$result for speedsync add: " . $process->getCommandLine());
        return $result;
    }

    /**
     * Adds a ZFS dataset path to SpeedSync with the given offsite target.
     *
     * @param string $zfsPath
     * @param string $offsiteTarget
     * @return int
     */
    public function addDatasetToTarget(string $zfsPath, string $offsiteTarget): int
    {
        $commandLine = [static::SPEEDSYNC_COMMAND, 'add', $zfsPath, '--targetDeviceId', $offsiteTarget];
        $process = $this->runCommandLine($commandLine);

        $result = $process->getExitCode();
        $this->logger->debug("SYN4531 ExitCode=$result for speedsync add: " . $process->getCommandLine());
        return $result;
    }

    /**
     * Remove a dataset path from SpeedSync.
     *
     * @param string $zfsPath
     * @return int
     */
    public function remove(string $zfsPath): int
    {
        $commandLine = [static::SPEEDSYNC_COMMAND, 'remove', $zfsPath];
        return $this->runCommandLine($commandLine)->getExitCode();
    }

    /**
     * Halt SpeedSync for a ZFS path.
     *
     * @param string $zfsPath
     */
    public function halt(string $zfsPath)
    {
        $commandLine = [static::SPEEDSYNC_COMMAND, 'halt', $zfsPath];
        $this->runCommandLine($commandLine, static::TIMEOUT_TEN_MINUTES);
    }

    /**
     * Pause for a dataset.
     * @param string $zfsPath
     * @return bool
     */
    public function pauseZfs(string $zfsPath): bool
    {
        $commandLine = [static::SPEEDSYNC_COMMAND, 'pause', 'zfs', $zfsPath];
        return $this->runCommandLine($commandLine)->isSuccessful();
    }

    /**
     * Resume for a dataset.
     *
     * @param string $zfsPath
     * @return bool
     */
    public function resumeZfs(string $zfsPath): bool
    {
        $commandLine = [static::SPEEDSYNC_COMMAND, 'resume', 'zfs', $zfsPath];
        return $this->runCommandLine($commandLine)->isSuccessful();
    }

    /**
     * Pause transfers for a dataset.
     *
     * @param string $zfsPath
     * @return bool
     */
    public function pauseTransfer(string $zfsPath): bool
    {
        $commandLine = [static::SPEEDSYNC_COMMAND, 'pause', 'transfer', $zfsPath];
        return $this->runCommandLine($commandLine)->isSuccessful();
    }

    /**
     * Resume transfers for a dataset.
     *
     * @param string $zfsPath
     * @return bool
     */
    public function resumeTransfer(string $zfsPath): bool
    {
        $commandLine = [static::SPEEDSYNC_COMMAND, 'resume', 'transfer', $zfsPath];
        return $this->runCommandLine($commandLine)->isSuccessful();
    }

    /**
     * Pause ZFS device-wide
     *
     * @return bool
     */
    public function pauseDeviceZfs(): bool
    {
        $commandLine = [static::SPEEDSYNC_COMMAND, 'pause', 'zfs'];
        return $this->runCommandLine($commandLine)->isSuccessful();
    }

    /**
     * Resume device-wide ZFS pause
     *
     * @return bool
     */
    public function resumeDeviceZfs(): bool
    {
        $commandLine = [static::SPEEDSYNC_COMMAND, 'resume', 'zfs'];
        return $this->runCommandLine($commandLine)->isSuccessful();
    }

    /**
     * Pause transfers device-wide
     *
     * @return bool
     */
    public function pauseDeviceTransfer(): bool
    {
        $commandLine = [static::SPEEDSYNC_COMMAND, 'pause', 'transfer'];
        return $this->runCommandLine($commandLine)->isSuccessful();
    }

    /**
     * Resume device-wide transfer pause
     *
     * @return bool
     */
    public function resumeDeviceTransfer(): bool
    {
        $commandLine = [static::SPEEDSYNC_COMMAND, 'resume', 'transfer'];
        return $this->runCommandLine($commandLine)->isSuccessful();
    }

    /**
     * Get the currently running SpeedsSync jobs.
     *
     * @return array
     */
    public function getJobs(): array
    {
        $commandLine = [static::SPEEDSYNC_COMMAND, 'jobs'];
        $process = $this->runCommandLine($commandLine, static::TIMEOUT_ONE_DAY);

        if (!$process->isSuccessful()) {
            throw new Exception('Cannot query speedsync for list of jobs.');
        }

        $jobs = @json_decode($process->getOutput(), true);

        if ($jobs === null) {
            throw new Exception(sprintf(
                'Cannot parse output of speedsync query: "%s" command returned "%s"',
                $process->getCommandLine(),
                $process->getOutput()
            ));
        }

        return $jobs;
    }

    /**
     * Get the global speedsync options.
     *
     * @return SpeedSyncGlobalOptions
     */
    public function getGlobalOptions(): SpeedSyncGlobalOptions
    {
        $commandLine = [static::SPEEDSYNC_COMMAND, 'options'];
        $process = $this->runCommandLine($commandLine);
        $options = $this->parseOptionsResult($process);
        $this->validateGlobalOptions($options);
        return new SpeedSyncGlobalOptions(
            $options['maxZfs'],
            $options['maxTransfer'],
            $options['pauseZfs'],
            $options['pauseTransfer'],
            $options['compression']
        );
    }

    /**
     * Get the options for a dataset.
     *
     * @param string $zfsPath
     * @return SpeedSyncDatasetOptions
     */
    public function getDatasetOptions(string $zfsPath): SpeedSyncDatasetOptions
    {
        $commandLine = [static::SPEEDSYNC_COMMAND, 'options', $zfsPath];
        $process = $this->runCommandLine($commandLine);
        $options = $this->parseOptionsResult($process);
        $this->validateDatasetOptions($options);
        return new SpeedSyncDatasetOptions(
            (int)$options['priority'],
            $options['target'],
            (int)$options['pauseZfs'],
            (int)$options['pauseTransfer']
        );
    }

    /**
     * Set a value for the given speedsync option.
     * Note:
     *   Most speedsync options are controlled by the dataset tables syncSettingsDefinition and syncSettings.
     *   Option values configured in the DB will overwrite options set with this function on next device checkin.
     *
     * @param string $optionName
     * @param string $optionValue
     * @param string|null $zfsPath
     *
     * @return bool
     */
    public function setOption(string $optionName, string $optionValue, $zfsPath = null): bool
    {
        $commandLine = [static::SPEEDSYNC_COMMAND, 'set', $optionName, $optionValue];
        if ($zfsPath !== null) {
            $commandLine[] = $zfsPath;
        }

        $process = $this->runCommandLine($commandLine);

        return $process->isSuccessful();
    }

    /**
     * Destroy a remote dataset.
     *
     * @param string $zfsPath
     * @param string $reason
     * @return int
     */
    public function remoteDestroy(string $zfsPath, string $reason): int
    {
        $commandLine = [static::SPEEDSYNC_COMMAND, 'remote', 'destroy', $zfsPath, $reason];
        $process = $this->runCommandLine($commandLine, static::TIMEOUT_ONE_DAY);
        return $process->getExitCode();
    }

    /**
     * Get remote data usage for a dataset.
     *
     * @param string $zfsPath
     * @return int
     */
    public function getRemoteUsed(string $zfsPath): int
    {
        $commandLine = [static::SPEEDSYNC_COMMAND, 'remote', 'get', 'used', $zfsPath];
        $process = $this->runCommandLine($commandLine, static::TIMEOUT_ONE_DAY);
        return $process->isSuccessful() ? (int)$process->getOutput() : 0;
    }

    /**
     * Get the locally queued restore points.
     *
     * @param string $zfsPath
     * @return int[]
     */
    public function getLocalPoints(string $zfsPath): array
    {
        $commandLine = [static::SPEEDSYNC_COMMAND, 'list', 'snapshots', 'local', 'true', $zfsPath];
        $process = $this->runCommandLine($commandLine, static::TIMEOUT_ONE_DAY);

        $queuedPoints = @json_decode($process->getOutput(), true);

        if (!is_array($queuedPoints)) {
            throw new Exception(sprintf(
                'Cannot parse output of speedsync query: "%s" command returned "%s"',
                $process->getCommandLine(),
                $process->getOutput()
            ));
        }

        return $queuedPoints;
    }

    /**
     * Get the critical points for a dataset.
     *
     * @param string $zfsPath
     * @return int[]
     */
    public function getCriticalPoints(string $zfsPath): array
    {
        $commandLine = [static::SPEEDSYNC_COMMAND, 'list', 'snapshots', 'critical', $zfsPath];
        $process = $this->runCommandLine($commandLine, static::TIMEOUT_ONE_DAY);

        $points = @json_decode($process->getOutput(), true);
        if (!is_array($points)) {
            throw new Exception(sprintf(
                'Cannot parse output of speedsync query: "%s" command returned "%s"',
                $process->getCommandLine(),
                $process->getOutput()
            ));
        }

        return $points;
    }

    /**
     * Get offsite points for a dataset.
     *
     * @param string $zfsPath
     * @return array
     */
    public function getRemotePoints(string $zfsPath): array
    {
        $commandLine = [static::SPEEDSYNC_COMMAND, 'list', 'snapshots', 'remote', 'zfs', $zfsPath];
        $process = $this->runCommandLine($commandLine, static::TIMEOUT_ONE_DAY);

        $points = @json_decode($process->getOutput(), true);

        return is_array($points) ? $points : [];
    }

    /**
     * Get the currently running actions.
     *
     * @return array
     */
    public function getActions(): array
    {
        $process = $this->runCommandLine([static::SPEEDSYNC_COMMAND, 'actions'], static::TIMEOUT_ONE_DAY);

        $points = @json_decode($process->getOutput(), true);
        if (!is_array($points)) {
            throw new Exception(sprintf(
                'Cannot parse output of speedsync query: "%s" command returned "%s"',
                $process->getCommandLine(),
                $process->getOutput()
            ));
        }

        return $points;
    }

    /**
     * Get currently pending remote points.
     *
     * @param string $zfsPath
     * @return array
     */
    public function getRemotePending(string $zfsPath): array
    {
        $commandLine = [static::SPEEDSYNC_COMMAND, 'list', 'snapshots', 'remote', 'pending', $zfsPath];
        $process = $this->runCommandLine($commandLine, static::TIMEOUT_ONE_DAY);

        $points = @json_decode($process->getOutput(), true);

        return is_array($points) ? $points : [];
    }

    /**
     * Mirror a point offsite.
     *
     * @param string $zfsPath
     * @param int $snapshot
     * @return int
     */
    public function mirror(string $zfsPath, int $snapshot): int
    {
        $commandLine = [static::SPEEDSYNC_COMMAND, 'mirror', "$zfsPath@$snapshot"];
        return $this->runCommandLine($commandLine, self::MIRROR_TIMEOUT_SECONDS)->getExitCode();
    }

    /**
     * Refresh the caches for a dataset.
     *
     * @param string $zfsPath
     */
    public function refresh(string $zfsPath)
    {
        $commandLine = [static::SPEEDSYNC_COMMAND, 'refresh', $zfsPath];
        $this->mustRunCommandLine($commandLine);
    }

    public function refreshBackground(string $zfsPath): void
    {
        $commandLine = [static::SPEEDSYNC_COMMAND, 'refresh', 'background', $zfsPath];
        $this->mustRunCommandLine($commandLine);
    }

    /**
     * Refresh [most] speedsync data via 'task refresh'. This does not do a full refresh.
     */
    public function refreshAllTask(): void
    {
        $commandLine = [static::SPEEDSYNC_COMMAND, 'task', 'refresh'];
        $this->mustRunCommandLine($commandLine, static::TIMEOUT_TEN_MINUTES);
    }

    /**
     * Refresh all speedsync data.
     */
    public function refreshAll(): void
    {
        $commandLine = [static::SPEEDSYNC_COMMAND, 'refresh'];
        $this->mustRunCommandLine($commandLine, static::TIMEOUT_TEN_MINUTES);
    }

    /**
     * Get the snapshots for a dataset by type.
     *
     * @param string $path
     * @return array
     */
    public function listSnapshots(string $path): array
    {
        $commandLine = [static::SPEEDSYNC_COMMAND, 'list', 'snapshots', $path];
        $process = $this->runCommandLine($commandLine, static::TIMEOUT_ONE_DAY);

        $points = @json_decode($process->getOutput(), true);

        return is_array($points) ? $points : [];
    }

    /**
     * Returns the concurrent sync limit
     *
     * @return int Current sync limit
     */
    public function getMaxSyncs()
    {
        $commandLine = [static::SPEEDSYNC_COMMAND, 'get', 'maxSyncs'];
        $process = $this->mustRunCommandLine($commandLine);

        return (int)trim($process->getOutput());
    }

    /**
     * Sets the concurrent sync limit
     *
     * @param int $maxSyncs
     */
    public function setMaxSyncs(int $maxSyncs)
    {
        $commandLine = [static::SPEEDSYNC_COMMAND, 'set', 'maxSyncs', $maxSyncs];
        $this->mustRunCommandLine($commandLine);
    }

    /**
     * Invoke ad-hoc migration to move zfs datasets locally
     *
     * @param string $host
     * @param string $port
     * @param string $sshUser
     * @param string $sshKeyFile
     * @param int $sourceDeviceId
     * @param string $zfsPath
     */
    public function migrateDeviceDataset(
        string $host,
        string $port,
        string $sshUser,
        string $sshKeyFile,
        int $sourceDeviceId,
        string $zfsPath
    ) {
        $commandLine = [
            static::SPEEDSYNC_COMMAND,
            'migrate:device:dataset',
            "$host:$port",
            $sshUser,
            $sshKeyFile,
            $sourceDeviceId,
            $zfsPath
        ];
        $this->mustRunCommandLine($commandLine, static::TIMEOUT_TEN_MINUTES);
    }

    /**
     * Manually run speedsync cron
     */
    public function cron()
    {
        $this->mustRunCommandLine([static::SPEEDSYNC_COMMAND, 'cron']);
    }

    public function getTargetInfo(bool $refreshCache): array
    {
        $refreshCache = $refreshCache || !$this->filesystem->exists(self::TARGET_INFO_CACHE_FILE);

        if ($refreshCache) {
            $commandLine = [static::SPEEDSYNC_COMMAND, 'debug:target', '--format', 'json'];
            $process = $this->runCommandLine($commandLine, self::TIMEOUT_TEN_MINUTES);

            $data = @json_decode($process->getOutput(), true);
        }

        $lock = $this->lockFactory->create(self::TARGET_INFO_CACHE_FILE);

        if ($refreshCache) {
            $lock->exclusive();
            $this->filesystem->filePutContents(self::TARGET_INFO_CACHE_FILE, json_encode($data));
        } else {
            $lock->shared();
            $data = @json_decode($this->filesystem->fileGetContents(self::TARGET_INFO_CACHE_FILE), true);
        }

        $lock->unlock();

        return is_array($data) ? $data : [];
    }

    /**
     * Invoke adhoc receive of a source dataset to the target
     *
     * @param string $sshKeyFile
     * @param string $targetDatasetPath
     * @param string $host
     * @param string $user
     * @param string $sourceDatasetPath
     */
    public function adhocReceiveDataset(
        string $sshKeyFile,
        string $targetDatasetPath,
        string $host,
        string $user,
        string $sourceDatasetPath
    ) {
        $commandLine = [
            static::SPEEDSYNC_COMMAND,
            "adhoc",
            "--key=$sshKeyFile",
            "--targetDataset=$targetDatasetPath",
            $host,
            $user,
            $sourceDatasetPath
        ];
        $this->mustRunCommandLine($commandLine, static::TIMEOUT_TEN_MINUTES);
    }

    /**
     * Runs a command with the given command line and returns the process.
     *
     * @param array $commandLine
     * @param int $timeout
     * @return Process
     */
    private function runCommandLine(array $commandLine, int $timeout = 0): Process
    {
        $process = $this->processFactory->get($commandLine);
        if ($timeout) {
            $process->setTimeout($timeout);
        }
        $process->run();
        return $process;
    }

    /**
     * Run a command using mustRun() and return the process.
     *
     * @param array $commandLine
     * @param int $timeout
     * @return Process
     */
    private function mustRunCommandLine(array $commandLine, int $timeout = 0): Process
    {
        $process = $this->processFactory->get($commandLine);
        if ($timeout) {
            $process->setTimeout($timeout);
        }
        $process->mustRun();
        return $process;
    }

    private function parseOptionsResult(Process $process): array
    {
        if (!$process->isSuccessful()) {
            throw new Exception('Unable to parse speedsync options: speedsync call failed');
        }

        $lines = explode(PHP_EOL, $process->getOutput());

        foreach ($lines as $line) {
            if (preg_match("/(\w+): (\d+|'.*')/", $line, $matches)) {
                $options[$matches[1]] = trim($matches[2], "'");
            }
        }

        return $options ?? [];
    }

    private function validateGlobalOptions(array $options)
    {
        $validOptions = [
            'maxZfs',
            'maxTransfer',
            'pauseZfs',
            'pauseTransfer',
            'compression'
        ];
        $this->validateOptions($options, $validOptions);
    }

    private function validateDatasetOptions(array $options)
    {
        $validOptions = [
            'priority',
            'target',
            'pauseZfs',
            'pauseTransfer'
        ];
        $this->validateOptions($options, $validOptions);
    }

    private function validateOptions(array $options, array $validOptions)
    {
        foreach ($validOptions as $key) {
            if (!isset($options[$key])) {
                throw new Exception("Unable to parse speedsync options: missing key $key");
            }
        }
    }
}
