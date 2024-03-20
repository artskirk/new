<?php

namespace Datto\Utility\Storage;

use Datto\Common\Resource\ProcessFactory;
use Exception;
use Throwable;

/**
 * Utility to interact with zpool.
 * In the OS2 repo, clients should interact with StorageInterface instead of calling this class directly.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class Zpool
{
    // Pool features must be kept in sync with the code in datto-stick's InitializeStorageStage class.
    const POOL_FEATURES = [
        'async_destroy',
        'empty_bpobj',
        'lz4_compress',
        'multi_vdev_crash_dump',
        'spacemap_histogram',
        'enabled_txg',
        'hole_birth',
        'extensible_dataset',
        'embedded_data',
        'bookmarks',
        'filesystem_limits',
        'large_blocks',
        'large_dnode',
        'sha512',
        'skein',
        'edonr',
        'userobj_accounting',
        'allocation_classes'
    ];

    const SUDO = 'sudo';
    const ZPOOL_COMMAND = 'zpool';
    const SCRUB = 'scrub';
    const STOP_FLAG = '-s';
    const DIRECTORY_FLAG = '-d';
    const DISK_BY_ID_PATH = '/dev/disk/by-id/';
    const ZPOOL_STATUS_ALL = '';

    const USE_FULL_PATH = true;
    const USE_DEFAULT_PATH = false;

    public const SCAN_STATUS_SCRUBBING = 'scrub in progress';

    public const DEFAULT_FILESYSTEM_PROPERTIES = ['overlay' => 'off'];

    private ProcessFactory $processFactory;
    private ZpoolStatusParser $zpoolStatusParser;

    public function __construct(
        ProcessFactory $processFactory,
        ZpoolStatusParser $zpoolStatusParser
    ) {
        $this->processFactory = $processFactory;
        $this->zpoolStatusParser = $zpoolStatusParser;
    }

    /**
     * Determine if zfs is enabled
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        try {
            $this->getStatus(self::ZPOOL_STATUS_ALL, Zpool::USE_DEFAULT_PATH);
        } catch (Throwable $throwable) {
            // get status failed, so zfs is not enabled
            return false;
        }

        return true;
    }

    /**
     * Determine if the pool with the given name exists
     *
     * @param string $pool
     * @return bool
     */
    public function exists(string $pool): bool
    {
        return $this->isImported($pool);
    }

    /**
     * Determine if the given storage pool is imported
     *
     * @param string $pool
     * @return bool
     */
    public function isImported(string $pool): bool
    {
        $this->validatePoolName($pool);

        $command = [self::SUDO, self::ZPOOL_COMMAND, 'get', 'health', '-H', $pool];
        $process = $this->processFactory->get($command);

        try {
            $process->run();
            return $process->isSuccessful();
        } catch (Throwable $throwable) {
            return false;
        }
    }

    /**
     * Import the storage pool
     *
     * @param string $pool
     * @param bool $force
     * @param string[] List of paths to search for devices. Empty array will search /dev by default
     */
    public function import(string $pool, bool $force, bool $byId, array $devicePaths = [])
    {
        $this->validatePoolName($pool);

        if ($this->isImported($pool)) {
            return;
        }

        $command = [
            self::SUDO,
            self::ZPOOL_COMMAND,
            'import'
        ];

        if ($byId) {
            $command[] = self::DIRECTORY_FLAG;
            $command[] = self::DISK_BY_ID_PATH;
        }

        if ($force) {
            $command[] = '-f';
        }

        foreach ($devicePaths as $devicePath) {
            $command[] = '-d';
            $command[] = $devicePath;
        }

        $command[] = $pool;

        $this->processFactory->get($command)->mustRun();
    }

    /**
     * Export the storage pool
     *
     * @param string $pool
     * @param bool $force
     */
    public function export(string $pool)
    {
        $this->validatePoolName($pool);

        if (!$this->isImported($pool)) {
            return;
        }

        $command = [
            self::SUDO,
            self::ZPOOL_COMMAND,
            'export',
            $pool
        ];
        $this->processFactory->get($command)->mustRun();
    }

    /**
     * Begin a scrub of the specified pool
     *
     * @param string $pool
     */
    public function scrub(string $pool)
    {
        $this->scrubInternal($pool);
    }

    /**
     * Cancel a scrub of the specified pool
     *
     * @param string $pool
     */
    public function cancelScrub(string $pool)
    {
        $this->scrubInternal($pool, [self::STOP_FLAG]);
    }

    /**
     * Create a storage pool
     *
     * @param string $pool
     * @param array $drives
     */
    public function create(string $pool, array $drives, string $mountpoint = '', array $fileSystemProperties = [])
    {
        $this->validatePoolName($pool);

        if ($this->exists($pool)) {
            throw new Exception('Pool with name ' . $pool . ' already exists');
        }

        $command = [self::SUDO, self::ZPOOL_COMMAND, 'create', '-o', 'ashift=12', '-f'];

        if (!empty($mountpoint)) {
            $command[] = '-m';
            $command[] = $mountpoint;
        }

        $command[] = '-d'; // do not automatically enable any features on the pool: we will specify which ones to enable
        foreach (self::POOL_FEATURES as $feature) {
            $command[] = '-o';
            $command[] = 'feature@' . $feature . '=enabled';
        }
        $command[] = $pool;
        foreach ($drives as $drive) {
            $command[] = $drive;
        }

        if (!empty($fileSystemProperties)) {
            foreach ($fileSystemProperties as $propertyName => $propertyValue) {
                $command[] = '-O';
                $command[] = $propertyName . '=' . $propertyValue;
            }
        }

        $process = $this->processFactory->get($command);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new ZpoolCreateException($process->getOutput() . PHP_EOL . $process->getErrorOutput(), $process->getCommandLine());
        }
    }

    /**
     * Destroy a storage pool
     *
     * @param string $pool Name of the pool to destroy
     */
    public function destroy(string $pool)
    {
        $command = [self::SUDO, self::ZPOOL_COMMAND, 'destroy', $pool];
        $this->processFactory->get($command)->mustRun();
    }

    /** Get a list of the features that are enabled on a pool */
    public function getFeatures(string $pool): array
    {
        $features = [];
        foreach ($this->getProperties($pool, ['all']) as $property => $value) {
            if (strpos($property, 'feature@') !== false && in_array($value, ['active', 'enabled'])) {
                $features[] = str_replace('feature@', '', $property);
            }
        }

        return $features;
    }

    /**
     * Enable all of the features from this class's POOL_FEATURES constant
     *
     * @codeCoverageIgnore This is effectively just a pass-through to `setProperty()`.
     */
    public function upgradeFeatures(string $pool)
    {
        foreach (self::POOL_FEATURES as $feature) {
            $this->setProperty($pool, 'feature@' . $feature, 'enabled');
        }
    }

    /**
     * Get the pool status
     *
     * @deprecated Use getParsedStatus instead
     * @param string $pool
     * @param bool $fullPath
     * @return string
     */
    public function getStatus(string $pool, bool $fullPath): string
    {
        $command = [self::SUDO, self::ZPOOL_COMMAND, 'status'];
        if ($fullPath) {
            $command[] = '-P';
        }
        if ($pool !== self::ZPOOL_STATUS_ALL) {
            $this->validatePoolName($pool);
            $command[] = $pool;
        }
        $process = $this->processFactory->get($command)->mustRun();
        return $process->getOutput();
    }

    /**
     * Retrieves the zpool status and returns the parsed output for the given pool name
     *
     * @param string $poolName Name of the pool to return the status for
     * @param bool $fullDevicePath If true, return the full path of the zpool devices. If false, return only the device name
     * @param bool $verbose If true, return verbose output of the pool status command. If false, return just the default output.
     * @return array
     */
    public function getParsedStatus(string $pool, bool $fullPath, bool $verbose = false): array
    {
        return $this->zpoolStatusParser->getParsedZpoolStatus($pool, $fullPath, $verbose);
    }

    /**
     * Get the number of errors associated with a pool
     *
     * @param string $pool
     * @return int
     */
    public function getErrorCount(string $pool): int
    {
        $this->validatePoolName($pool);

        $command = [self::SUDO, self::ZPOOL_COMMAND, 'status', '-x', $pool];
        $process = $this->processFactory->get($command)->mustRun();

        $output = $process->getOutput();
        if (preg_match("/errors: (\d+) data errors/", $output, $matches)) {
            return (int)$matches[1];
        }

        return 0;
    }

    /**
     * Expand a device in a pool to grow the device to utilize all space available.
     *
     * @param string $pool
     * @param string $poolDevice
     * @return void
     */
    public function expandPoolDevice(string $pool, string $poolDevice): void
    {
        $this->validatePoolName($pool);

        $command = [self::SUDO, self::ZPOOL_COMMAND, 'online', '-e', $pool, $poolDevice];
        $this->processFactory->get($command)->mustRun();
    }

    /**
     * Get a set of properties on a pool
     *
     * @param string $pool
     * @param string[] $propertyNames
     * @return array keys are property names; values are those properties' values
     *               If the `zpool get` command fails, an empty array will be returned.
     */
    public function getProperties(string $pool, array $propertyNames): array
    {
        $this->validatePoolName($pool);
        if (empty($propertyNames)) {
            throw new Exception('At least one property is required.');
        }

        $command = [
            self::SUDO,
            self::ZPOOL_COMMAND,
            'get',
            implode(',', $propertyNames),
            '-H',
            '-p',
            $pool,
            '-o',
            'property,value'
        ];
        $process = $this->processFactory->get($command);
        $process->run();

        if (!$process->isSuccessful()) {
            return [];
        }

        $properties = [];
        foreach (explode(PHP_EOL, trim($process->getOutput())) as $rawPropertyOutput) {
            list($name, $value) = explode("\t", $rawPropertyOutput);
            if ($name !== 'guid' && is_numeric($value)) {
                $properties[$name] = (float) $value;
            } else {
                $properties[$name] = $value;
            }
        }

        return $properties;
    }

    /**
     * Set a property on a pool
     *
     * @param string $pool
     * @param string $key
     * @param string $value
     */
    public function setProperty(string $pool, string $key, string $value)
    {
        $this->validatePoolName($pool);
        if ($key === "" || $value === "") {
            throw new Exception('Property key and value are required.');
        }

        $property = $key . '=' . $value;
        $command = [self::SUDO, self::ZPOOL_COMMAND, 'set', $property, $pool];
        $this->processFactory->get($command)->mustRun();
    }

    /**
     * Add a drive group to the pool
     *
     * @param string $pool
     * @param string[] $newDriveIds
     * @param string $raidLevel
     * @param bool $requireRaid
     */
    public function addDriveGroup(
        string $pool,
        array $newDriveIds,
        string $raidLevel,
        bool $requireRaid = true
    ) {
        $this->validatePoolName($pool);
        if (empty($newDriveIds)) {
            throw new Exception('At least one drive id is required.');
        }
        if ($requireRaid && $raidLevel === '') {
            throw new Exception('A raid level is required.');
        }

        $command = [self::SUDO, self::ZPOOL_COMMAND, 'add', '-f', $pool];
        if ($raidLevel !== '') {
            $command[] = $raidLevel;
        }
        foreach ($newDriveIds as $newDriveId) {
            $command[] = $this->prependDiskByIdIfNotFullPath($newDriveId);
        }
        $this->processFactory->get(array_filter($command))->setTimeout(900)->mustRun();
    }

    /**
     * Detach a drive from a pool
     *
     * @param string $pool
     * @param string $driveId
     */
    public function detachDrive(string $pool, string $driveId)
    {
        $this->validatePoolName($pool);
        if ($driveId === "") {
            throw new Exception('A drive id is required.');
        }

        $command = [self::SUDO, self::ZPOOL_COMMAND, 'detach', $pool, $driveId];
        $this->processFactory->get($command)->mustRun();
    }

    /**
     * Replace on a drive on the pool
     *
     * @param string $pool Name of the pool
     * @param string $sourceDriveId
     * @param string $targetDriveId
     */
    public function replaceDrive(string $pool, string $sourceDriveId, string $targetDriveId)
    {
        $this->validatePoolName($pool);
        if ($sourceDriveId === "" || $targetDriveId === "") {
            throw new Exception('Source and target drive ids are required.');
        }

        $sourceDrivePath = $this->prependDiskByIdIfNotFullPath($sourceDriveId);
        $targetDrivePath = $this->prependDiskByIdIfNotFullPath($targetDriveId);
        $command = [self::SUDO, self::ZPOOL_COMMAND, 'replace', '-f', $pool, $sourceDrivePath, $targetDrivePath];
        $this->processFactory->get($command)->mustRun();
    }

    /**
     * Clears all ZFS metadata on the drive specified by its ID
     *
     * @param string $driveId
     */
    public function clearZfsLabelsOnDrive(string $driveId)
    {
        if ($driveId === "") {
            throw new Exception('A drive id is required.');
        }

        $drivePath = $this->prependDiskByIdIfNotFullPath($driveId);
        $command = [self::SUDO, self::ZPOOL_COMMAND, 'labelclear', '-f', $drivePath];

        // zpool labelclear now fails when there is no zfs metadata (empty drive) so we just ignore the errors.
        // https://github.com/zfsonlinux/zfs/commit/dbb38f660509073f43284c6c745a4449ffd46385
        $this->processFactory->get($command)->run();
    }

    /**
     * List all of the zpools
     *
     * @return string[] List of zpools
     */
    public function listPools(): array
    {
        $command = [
            self::SUDO,
            self::ZPOOL_COMMAND,
            'list',
            '-H',
            '-o',
            'name'
        ];

        $process = $this->processFactory->get($command);
        $process->run();

        if (!$process->isSuccessful()) {
            return [];
        }

        $pools = explode(PHP_EOL, trim($process->getOutput()));
        return $pools;
    }

    /**
     * Get the number of disks across vdevs in a pool
     *
     * @param string $pool Pool name
     * @return int Number of disks across all vdevs in a pool
     */
    public function getNumberOfDisks(string $pool): int
    {
        $command = [
            self::SUDO,
            self::ZPOOL_COMMAND,
            'list',
            '-v',
            '-H', // remove headers
            '-P', // full device paths (used for parsing)
            $pool
        ];

        $process = $this->processFactory->get($command);
        $process->run();

        if (!$process->isSuccessful()) {
            return 0;
        }

        $output = explode(PHP_EOL, trim($process->getOutput()));
        $devices = array_filter(
            $output,
            function (string $line) {
                return strpos($line, '/dev/') !== false;
            }
        );
        return count($devices);
    }

    /**
     * Run the zpool scrub command with the specified flags
     *
     * @param string $pool
     * @param array $flags
     */
    private function scrubInternal(string $pool, array $flags = [])
    {
        $this->validatePoolName($pool);
        $command = [
            self::SUDO,
            self::ZPOOL_COMMAND,
            self::SCRUB
        ];
        $command = array_merge($command, $flags);
        $command[] = $pool;
        $this->processFactory->get($command)->mustRun();
    }

    /**
     * Ensure the pool name is valid
     *
     * @param string $pool
     */
    private function validatePoolName(string $pool)
    {
        if ($pool === "") {
            throw new Exception('A storage pool name is required.');
        }
    }

    /**
     * Prepend the disk_by_id path if only a drive id was given.
     *
     * @param string $driveId
     * @return string
     */
    private function prependDiskByIdIfNotFullPath(string $driveId)
    {
        $fullPath = $driveId;
        if (basename($driveId) === $driveId) {
            $fullPath = static::DISK_BY_ID_PATH . $driveId;
        }
        return $fullPath;
    }
}
