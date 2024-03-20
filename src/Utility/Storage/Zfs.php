<?php

namespace Datto\Utility\Storage;

use Datto\Common\Utility\Mount\MountException;
use Datto\Common\Utility\Mount\MountUtility;
use Datto\Common\Resource\ProcessFactory;
use Datto\Resource\DateTimeService;
use Exception;

/**
 * Utility to interact with Zfs.
 * In the OS2 repo, clients should interact with StorageInterface instead of calling this class directly.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class Zfs
{
    private const SUDO = 'sudo';
    private const ZFS_COMMAND = 'zfs';

    private MountUtility $mountUtility;

    private ProcessFactory $processFactory;

    public function __construct(ProcessFactory $processFactory, MountUtility $mountUtility)
    {
        $this->processFactory = $processFactory;
        $this->mountUtility = $mountUtility;
    }

    /**
     * Create a zfs dataset
     *
     * @param string $name Name of the dataset
     * @param bool $createParents True if the parent datasets should be created as well
     * @param array $properties Optional properties to set on the dataset
     */
    public function createDataset(string $name, bool $createParents = false, array $properties = []): void
    {
        $commandLine = [
            self::SUDO,
            self::ZFS_COMMAND,
            'create'
        ];
        if ($createParents) {
            $commandLine[] = '-p';
        }

        if (!empty($properties)) {
            foreach ($properties as $propertyName => $propertyValue) {
                $commandLine[] = '-o';
                $commandLine[] = $propertyName . '=' . $propertyValue;
            }
        }

        $commandLine[] = $name;

        $this->processFactory->get($commandLine)
            ->setTimeout(DateTimeService::SECONDS_PER_HOUR)
            ->mustRun();
    }

    /**
     * Create a zvol
     *
     * @param string $name Name of the zvol
     * @param int $sizeInBytes Size of the zvol in bytes
     * @param bool $createParents True if the parent datasets should be created as well
     */
    public function createZvolDataset(string $name, int $sizeInBytes, bool $createParents = false): void
    {
        $commandLine = [
            self::SUDO,
            self::ZFS_COMMAND,
            'create'
        ];

        if ($createParents) {
            $commandLine[] = '-p';
        }

        $commandLine = array_merge($commandLine, [
            '-s',
            '-V',
            $sizeInBytes,
            '-b',
            '128k',
            $name
        ]);

        $this->processFactory->get($commandLine)
            ->setTimeout(DateTimeService::SECONDS_PER_HOUR)
            ->mustRun();
    }

    /**
     * Does a destroy dry run and returns success or failure
     *
     * @param string $name The ZFS path of the dataset to destroy
     * @param bool $recursive Do a recursive destroy (note that if false, this function will throw a
     * ProcessFailedException if there are any snapshots that belong to the clone)
     * @return bool True if the dry run was successful indicating that the dataset can successfully be destroyed, false otherwise
     */
    public function destroyDryRun(string $name, bool $recursive = false): bool
    {
        $commandline = [
            self::SUDO,
            self::ZFS_COMMAND,
            'destroy',
            '-n' // Do a dry-run ("No-op") deletion
        ];

        if ($recursive) {
            $commandline[] = '-r';
        }

        $commandline[] = $name;

        $process = $this->processFactory->get($commandline);
        $process->setTimeout(DateTimeService::SECONDS_PER_HOUR);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Destroys the dataset with the given ZFS name
     *
     * @param string $name ZFS path of the dataset to destroy
     * @param bool $recursive Do a recursive destroy (note that if false, this function will throw a
     *      ProcessFailedException if there are any snapshots that belong to the clone)
     */
    public function destroyDataset(string $name, bool $recursive = false): void
    {
        $commandline = [
            self::SUDO,
            self::ZFS_COMMAND,
            'destroy'
        ];

        if ($recursive) {
            $commandline[] = '-r';
        }

        $commandline[] = $name;

        $this->processFactory
            ->get($commandline)
            ->setTimeout(DateTimeService::SECONDS_PER_HOUR * 2)
            ->mustRun();
    }

    /**
     * Get a list of datasets with the given properties
     *
     * @param string $dataset Dataset to retrieve the child datasets for
     * @param string[] $properties List of properties to get the values for
     * @param bool $recursive Recursively retrieve datasets
     * @return array List of datasets with given properties
     */
    public function getDatasets(string $dataset, array $properties, bool $recursive): array
    {
        return $this->getStorageInfo($dataset, $properties, $recursive, false);
    }

    /**
     * Run zfs list on a given dataset.
     *
     * @param string $dataset
     * @return string
     */
    public function list(string $dataset): string
    {
        // TODO: add ability to pass in flags.
        // TODO: fix output. By passing the complete zfs list output to the client it couples the client to zfs's output format.

        $process = $this->processFactory->get([
                self::SUDO,
                self::ZFS_COMMAND,
                'list',
                '-H',
                '-o',
                'name',
                $dataset
            ]);

        $process->mustRun();

        return $process->getOutput();
    }

    /**
     * Determine if a dataset exists
     *
     * @return bool True if the dataset exists, false otherwise
     */
    public function exists(string $dataset): bool
    {
        $process = $this->processFactory->get([
                self::SUDO,
                self::ZFS_COMMAND,
                'list',
                '-H',
                '-o',
                'name',
                $dataset
            ]);
        $process->setTimeout(DateTimeService::SECONDS_PER_HOUR);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Get the list of key/value property pairs for the given set of properties
     *
     * @param string $dataset Dataset to retrieve the properties for
     * @param string[] $propertyKeys List of property keys to retrieve
     */
    public function getProperties(string $dataset, array $propertyKeys): array
    {
        if (empty($propertyKeys)) {
            return [];
        }

        $keys = implode(',', $propertyKeys);
        $process = $this->processFactory->get([
                self::SUDO,
                self::ZFS_COMMAND,
                'get',
                $keys,
                '-H',
                '-p',
                '-o',
                'property,value',
                $dataset
            ]);
        $process->mustRun();

        $properties = [];
        foreach (explode(PHP_EOL, trim($process->getOutput())) as $rawPropertyOutput) {
            if (strpos($rawPropertyOutput, "\t") !== false) {
                list($name, $value) = explode("\t", $rawPropertyOutput);
            } else {
                $name = $rawPropertyOutput;
                $value = '';
            }
            $properties[$name] = $value;
        }

        return $properties;
    }

    /**
     * Set a property on a dataset
     *
     * @param string $dataset Name of the dataset
     * @param string $key Property key
     * @param string $value Property value
     */
    public function setProperty(string $dataset, string $key, string $value): void
    {
        if (empty($key) || empty($value)) {
            throw new Exception('Property key and value are required.');
        }

        $property = $key . '=' . $value;
        $this->processFactory->get([
                self::SUDO,
                self::ZFS_COMMAND,
                'set',
                $property,
                $dataset
            ])
            ->setTimeout(DateTimeService::SECONDS_PER_HOUR)
            ->mustRun();
    }

    /**
     * Inherit a property's value from the parent dataset
     *
     * This applies the parent dataset's value for a property to the specified dataset.
     * If the top-level dataset of a pool is provided, the default value for the property will be inherited.
     *
     * This operation can optionally set the property's value recursively.
     */
    public function inheritProperty(string $dataset, string $property, bool $recursive = false): void
    {
        if (empty($property)) {
            throw new Exception('Property name is required.');
        }

        $command = [self::SUDO, self::ZFS_COMMAND, 'inherit'];
        if ($recursive) {
            $command[] = '-r';
        }
        $command[] = $property;
        $command[] = $dataset;

        $this->processFactory->get($command)->setTimeout(DateTimeService::SECONDS_PER_HOUR)->mustRun();
    }

    /**
     * Mount the zfs dataset
     *
     * @param string $dataset Name of the dataset
     */
    public function mount(string $dataset)
    {
        $this->processFactory->get([
                self::SUDO,
                self::ZFS_COMMAND,
                'mount',
                $dataset
            ])
            ->setTimeout(DateTimeService::SECONDS_PER_HOUR)
            ->mustRun();
    }

    /**
     * Unmount a zfs dataset
     *
     * @param string $dataset Name of the dataset
     */
    public function unmount(string $dataset)
    {
        $this->processFactory->get([
                self::SUDO,
                self::ZFS_COMMAND,
                'unmount',
                $dataset
            ])
            ->setTimeout(DateTimeService::SECONDS_PER_HOUR)
            ->mustRun();
    }

    /**
     * Get a list of datasets and zvols for the given zpool
     *
     * @param string $zpool Name of the zpool to retrieve the datasets and zvols for
     * @return string[] List of dataset and zvol names
     */
    public function listDatasetsAndZvolsInPool(string $zpool): array
    {
        $process = $this->processFactory->get([
                self::SUDO,
                self::ZFS_COMMAND,
                'list',
                '-H',
                '-o',
                'name',
                '-r',
                $zpool
            ]);
        $process->mustRun();

        return explode(PHP_EOL, trim($process->getOutput()));
    }

    /**
     * Get a list of snapshots with the given properties
     *
     * @param string $dataset Dataset to retrieve the snapshots for
     * @param string[] $properties List of properties to get the values for
     * @param bool $recursive Recursively retrieve datasets or snapshots
     * @return array List of snapshots with given properties
     */
    public function getSnapshots(string $dataset, array $properties, bool $recursive): array
    {
        return $this->getStorageInfo($dataset, $properties, $recursive, true);
    }

    /**
     * Take a snapshot of the given dataset
     *
     * @param string $dataset Dataset to take a snapshot of
     * @param string $snapshotTag Tag to use as the snapshot name
     * @param int $timeout Timeout for the zfs call, default 3600 seconds
     * @return string Full name of the snapshot
     */
    public function takeSnapshot(string $dataset, string $snapshotTag, int $timeout): string
    {
        $snapshotName = $dataset . '@' . $snapshotTag;

        $this->processFactory->get([
                self::SUDO,
                self::ZFS_COMMAND,
                'snapshot',
                $snapshotName
            ])
            ->setTimeout($timeout)
            ->mustRun();

        return $snapshotName;
    }

    /**
     * Rollback the dataset back to given snapshot
     * This method will destroy any snapshots and bookmarks more recent than the one specified.
     *
     * @param string $snapshot ZFS path to the snapshot
     * @param bool $destroyMoreRecentSnapshots If true, destroy any snapshots more recent than the one specified
     * @param bool $destroyMoreRecentSnapshotsAndClones If true, destroy any more recent snapshots and clones of those snapshots
     */
    public function rollbackToSnapshot(string $snapshot, bool $destroyMoreRecentSnapshots, bool $destroyMoreRecentSnapshotsAndClones): void
    {
        $command = [
            self::SUDO,
            self::ZFS_COMMAND,
            'rollback'
        ];

        if ($destroyMoreRecentSnapshots) {
            $command[] = '-r';
        }

        if ($destroyMoreRecentSnapshotsAndClones) {
            $command[] = '-R';
        }

        $command[] = $snapshot;

        $this->processFactory->get($command)
            ->setTimeout(DateTimeService::SECONDS_PER_HOUR)
            ->mustRun();
    }

    /**
     * Creates a ZFS clone of the source snapshot
     *
     * @param string $sourceSnapshot ZFS path of the dataset to clone
     * @param string $clonePath Path to clone to
     * @param string $mountPoint Expected mount point, or '' if none.
     * @param bool|null $sync Whether or not the clone should have synchronous writes enabled/disabled/inherited (default: inherited)
     * @throws MountException
     */
    public function cloneSnapshot(
        string $sourceSnapshot,
        string $clonePath,
        string $mountPoint,
        bool $sync = null
    ): void {
        $command = [
            self::SUDO,
            self::ZFS_COMMAND,
            'clone',
            $sourceSnapshot,
            $clonePath
        ];

        if (isset($sync)) {
            $syncValue = $sync ? 'standard' : 'disabled';
            $command[] = '-o';
            $command[] = 'sync=' . $syncValue;
        }

        $this->mountUtility->assertIfMountTargetInvalid($mountPoint);

        $process = $this->processFactory->get($command);
        $process->setTimeout(DateTimeService::SECONDS_PER_HOUR);
        $process->mustRun();

        $cloneDetails = $this->getProperties($clonePath, ['mountpoint', 'type']);

        if ($cloneDetails['type'] === 'filesystem') {
            $this->mountUtility->assertIfFailedMount($clonePath, $cloneDetails['mountpoint']);
        }
    }

    /**
     * Promote a zfs clone to its own first class dataset
     *
     * @param string $cloneDatasetName New dataset full name
     */
    public function promoteClone(string $cloneDatasetName)
    {
        $this->processFactory->get([
                self::SUDO,
                self::ZFS_COMMAND,
                'promote',
                $cloneDatasetName
            ])
            ->setTimeout(DateTimeService::SECONDS_PER_HOUR)
            ->mustRun();
    }

    /**
     * Get a list of datasets or snapshots with the given properties
     *
     * @param string $dataset Dataset to retrieve the datasets or snapshots for
     * @param string[] $properties List of properties to get the values for
     * @param bool $recursive Recursively retrieve datasets or snapshots
     * @param bool $snapshotType If true, set the type to snapshot, otherwise datasets are retrieved
     * @return array List of datasets or snapshots with given properties
     */
    private function getStorageInfo(string $dataset, array $properties, bool $recursive, bool $snapshotType): array
    {
        $commandLine = [
            self::SUDO,
            self::ZFS_COMMAND,
            'list',
            '-H', // no headers
            '-p' // parseable numbers
        ];

        if ($snapshotType) {
            $commandLine[] = '-t';
            $commandLine[] = 'snapshot';
        }

        $commandLine[] = '-o';
        $commandLine[] = implode(',', $properties);

        if ($recursive) {
            $commandLine[] = '-r';
        }

        if (!empty($dataset)) {
            $commandLine[] = $dataset;
        }

        $process = $this->processFactory->get($commandLine)
            ->setTimeout(120);
        $process->mustRun();

        $output = trim($process->getOutput());

        $storages = [];
        if (!empty($output)) {
            $lines = explode("\n", $output);
            foreach ($lines as $line) {
                /*
                * Example lines:
                * homePool/home/agents/ef972ee05eff4887bafcfbe37f0440fb@1516979130        37445632
                * homePool/home/agents/ef972ee05eff4887bafcfbe37f0440fb@1516979787        14426112
                */
                $returnedProperties = preg_split('/\s+/', $line);
                $storage = [];
                foreach ($properties as $property) {
                    $storage[$property] = array_shift($returnedProperties);
                }
                $storages[] = $storage;
            }
        }
        return $storages;
    }
}
