<?php

namespace Datto\Core\Storage;

/**
 * Interface that all storage backends must implement
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
interface StorageInterface
{
    /**
     * STORAGE BACKEND METHODS
     */

    /**
     * Get a property value associated with the given property key for the storage backend
     *
     * @param string $property Storage backend property to retrieve the value for
     */
    public function getGlobalProperty(string $property): string;

    /**
     * STORAGE POOL METHODS
     */

    /**
     * Get the list of storage pools.
     * If the list of pools cannot be retrieved, an exception will be thrown.
     *
     * @return string[] List of pool ids
     */
    public function listPoolIds(): array;

    /**
     * Create a storage pool based on the given pool creation context.
     * If the pool name already exists or the pool cannot be created, an exception will be thrown.
     *
     * @param StoragePoolCreationContext $context Context that defines how the pool should be created
     * @return string Pool Id
     */
    public function createPool(StoragePoolCreationContext $context): string;

    /**
     * Destroy a storage pool with the given pool id.
     * If the pool id does not exist or pool cannot be destroyed, an exception will be thrown.
     *
     * @param string $poolId Id of the pool to be destroyed
     */
    public function destroyPool(string $poolId): void;

    /**
     * Determine if a storage pool with the given pool id has been imported.
     * If the pool id does not exist, an exception will be thrown.
     *
     * @return bool True if the pool has been imported, false otherwise
     */
    public function poolHasBeenImported(string $poolId): bool;

    /**
     * Import a storage pool.
     * If the pool name already exists or the pool cannot be imported, an exception will be thrown.
     *
     * @param StoragePoolImportContext $context Context that defines how the pool should be imported
     * @return string Pool Id
     */
    public function importPool(StoragePoolImportContext $context): string;

    /**
     * Export a storage pool.
     * If the pool id does not exist or the pool cannot be exported, an exception will be thrown.
     *
     * @param string $poolId Id of the pool to be exported
     */
    public function exportPool(string $poolId): void;

    /**
     * Get the pool status for the given pool id.
     * If the pool id does not exist or the pool status cannot be retrieved, an exception will be thrown.
     * This was decoupled from getPoolInfo as the info may not be accessible depending on the pool status.
     *
     * @param string $poolId Id of the pool to retrieve the pool status for
     * @param bool $fullDevicePath If true, return the full path of the pool devices. If false, return only the device name
     * @param bool $verbose If true, return verbose output of the pool status command. If false, return just the default output.
     * @return StoragePoolStatus Pool status
     */
    public function getPoolStatus(string $poolId, bool $fullDevicePath = false, bool $verbose = false): StoragePoolStatus;

    /**
     * Get the pool info for the given pool id.
     * If the pool id does not exist or the pool info cannot be retrieved, an exception will be thrown.
     *
     * @param string $poolId Id of the pool to retrieve the pool info for
     * @return StoragePoolInfo Pool info
     */
    public function getPoolInfo(string $poolId): StoragePoolInfo;

    /**
     * Get a list of property values associated with the given pool and list of property keys.
     * If the pool id does not exist or the pool properties cannot be retrieved, an exception will be thrown.
     *
     * @param string $poolId Id of the pool to retrieve the property from
     * @param string[] $properties List of properties to get the values for
     * @return string[] List of key/value property pairs
     *      keys are property names; values are those properties' values
     */
    public function getPoolProperties(string $poolId, array $properties): array;

    /**
     * Set a list of properties on the given pool.
     * If the pool id does not exist or the pool properties cannot be set, an exception will be thrown.
     * The same properties can be set to the same values multiple times without causing an error.
     *
     * @param string $poolId Id of the pool to set the property on
     * @param string[] $properties List of key/value pairs to set as properties on the pool
     */
    public function setPoolProperties(string $poolId, array $properties): void;

    /**
     * Expand the size of a device in a given pool. This can be useful if the underlying size of the device has changed
     * and you want the pool to consume the entire device.
     *
     * @param StoragePoolExpandDeviceContext $context
     * @return void
     */
    public function expandPoolDeviceSpace(StoragePoolExpandDeviceContext $context): void;

    /**
     * Expand the amount of space in the storage pool.
     *
     * @param StoragePoolExpansionContext $context Context that defines how the pool should be expanded
     */
    public function expandPoolSpace(StoragePoolExpansionContext $context): void;

    /**
     * Reduce the amount of space in the storage pool.
     *
     * @param StoragePoolReductionContext $context Context that defines how the pool should be reduced
     */
    public function reducePoolSpace(StoragePoolReductionContext $context): void;

    /**
     * Replace parts of the storage pool.
     * For drive-based storage pools, this can be used to replace a failed drive.
     *
     * @param StoragePoolReplacementContext $context Context that defines how the pool should be replaced
     */
    public function replacePoolStorage(StoragePoolReplacementContext $context): void;

    /**
     * Start repairing the pool with the given pool id.
     * If the pool id does not exist or the repair cannot be started, an exception will be thrown.
     *
     * @param string $poolId Id of the pool to start the repair on
     */
    public function startPoolRepair(string $poolId): void;

    /**
     * Stop repairing the pool with the given pool id.
     * If the pool id does not exist or the repair cannot be stopped, an exception will be thrown.
     *
     * @param string $poolId Id of the pool to stop the repair on
     */
    public function stopPoolRepair(string $pooldId): void;

    /**
     * STORAGE METHODS
     */

    /**
     * Get the list of storages.
     * If the pool id does not exist or the list of pools cannot be retrieved, an exception will be thrown.
     *
     * @param string $poolId Id of the pool to retrieve the list of storages
     * @return string[] List of storage ids
     */
    public function listStorageIds(string $poolId): array;

    /**
     * Get the list of cloned storages. These are storages that are a child of another storage.
     * If the pool id does not exist or the list of pools cannot be retrieved, an exception will be thrown.
     *
     * @param string $poolId Id of the pool to retrieve the list of cloned storages
     * @return string[] List of cloned storage ids
     */
    public function listClonedStorageIds(string $poolId): array;

    /**
     * Determine if a storage with the given storage id exists
     *
     * @return bool True if the storage exists, false otherwise
     */
    public function storageExists(string $storageId): bool;

    /**
     * Create storage based in the given pool based on the creation context.
     * If the storage name and type already exists or the storage cannot be created, an exception will be thrown.
     *
     * @param StorageCreationContext $context Context that defines how the storage should be created
     * @return string Storage Id
     */
    public function createStorage(StorageCreationContext $context): string;

    /**
     * Determine if a storage can be destroyed
     *
     * @param string $storageId Id of the storage to be destroyed
     * @param bool $recursive If true, recursively destroy all storage children
     * @return bool True if the storage can be destroyed, false otherwise
     */
    public function canDestroyStorage(string $storageId, bool $recursive = false) : bool;

    /**
     * Destroy storage based on the given storage id.
     * If the storage id does not exist or the storage cannot be destroyed, an exception will be thrown.
     *
     * @param string $storageId Id of the storage to be destroyed
     * @param bool $recursive If true, recursively destroy all storage children
     */
    public function destroyStorage(string $storageId, bool $recursive = false): void;

    /**
     * Get the storage info for the given storage id.
     * If the storage id does not exist or the storage info cannot be retrieved, an exception will be thrown.
     *
     * @param string $storageId Id of the storage to retrieve the storage info for
     * @return StorageInfo Storage info
     */
    public function getStorageInfo(string $storageId): StorageInfo;

    /**
     * Get all the storage infos for the given storage id.
     * If the storage id does not exist or the storage infos cannot be retrieved, an exception will be thrown.
     *
     * @param string $storageId Id of the storage to retrieve the storage infos for.
     *  If this is an empty string, the main pool will be used.
     * @param bool $recursive If true, recursively retrieve all storage children
     * @return StorageInfo[] List of storage infos
     */
    public function getStorageInfos(string $storageId, bool $recursive = false): array;

    /**
     * Get a list of property values associated with the given storage and list of property keys.
     * If the storage id does not exist or the storage properties cannot be retrieved, an exception will be thrown.
     *
     * @param string $storageId Id of the storage to retrieve the property from
     * @param string[] $properties List of properties to get the values for
     * @return string[] List of key/value property pairs
     *      keys are property names; values are those properties' values
     */
    public function getStorageProperties(string $storageId, array $properties): array;

    /**
     * Set a list of properties on the given storage.
     * If the storage id does not exist or the storage properties cannot be set, an exception will be thrown.
     * The same properties can be set to the same values multiple times without causing an error.
     *
     * @param string $storageId Id of the storage to set the property on
     * @param string[] $properties List of key/value pairs to set as properties on the storage
     */
    public function setStorageProperties(string $storageId, array $properties): void;

    /**
     * Mount the storage with the given storage id.
     * If the storage id does not exist or the storage cannot be mounted, an exception will be thrown.
     *
     * @param string $storageId Id of the storage to mount
     */
    public function mountStorage(string $storageId): void;

    /**
     * Unmount the storage with the given storage id.
     * If the storage id does not exist or the storage cannot be unmounted, an exception will be thrown.
     *
     * @param string $storageId Id of the storage to unmount
     */
    public function unmountStorage(string $storageId): void;

    /**
     * STORAGE SNAPSHOT METHODS
     */

    /**
     * Get the list of snapshots.
     * If the storage id does not exist or the list of snapshots cannot be retrieved, an exception will be thrown.
     *
     * @param string $storageId Id of the storage to retrieve the list of snapshots
     * @param bool $recursive Recursively retrieve snapshot ids
     * @return string[] List of snapshot ids
     */
    public function listSnapshotIds(string $storageId, bool $recursive): array;

    /**
     * Get the list of snapshot names.
     * If the storage id does not exist or the list of snapshots cannot be retrieved, an exception will be thrown.
     *
     * @param string $storageId Id of the storage to retrieve the list of snapshot names
     * @param bool $recursive Recursively retrieve snapshot names
     * @return string[] List of snapshot names
     */
    public function listSnapshotNames(string $storageId, bool $recursive): array;

    /**
     * Take snapshot of the given storage.
     * If the storage id does not exist or the snapshot cannot be taken, an exception will be thrown.
     *
     * @param string $storageId Id of the storage to take the snapshot of
     * @return string Snapshot Id
     */
    public function takeSnapshot(string $storageId, SnapshotCreationContext $context): string;

    /**
     * Destroy snapshot based on the given snapshot id.
     * If the snapshot id does not exist or the snapshot cannot be destroyed, an exception will be thrown.
     *
     * @param string $snapshotId Id of the snapshot to be destroyed
     */
    public function destroySnapshot(string $snapshotId): void;

    /**
     * Get the snapshot info for the given snapshot id.
     * If the snapshot id does not exist or the snapshot info cannot be retrieved, an exception will be thrown.
     *
     * @param string $snapshotId Id of the snapshot to retrieve the snapshot info for
     * @return SnapshotInfo Snapshot info
     */
    public function getSnapshotInfo(string $snapshotId): SnapshotInfo;

    /**
     * Get all the snapshot infos for the given storage id.
     * If the storage id does not exist or the snapshot infos cannot be retrieved, an exception will be thrown.
     *
     * @param string $storageId Id of the storage to retrieve the snapshot infos for
     * @param bool $recursive Recursively retrieve snapshots
     * @return SnapshotInfo[] List of snapshot infos
     */
    public function getSnapshotInfosFromStorage(string $storageId, bool $recursive): array;

    /**
     * Rollback storage to the most recent snapshot for the given storage id. This rollback will not destroy existing snapshots.
     * Contents of the storage will be replaced with the contents of the snapshot.
     * If the storage id does not exist or the storage cannot be rolled back, an exception will be thrown.
     *
     * @param string $storageId Id of the storage to rollback to the most recent snapshot of
     */
    public function rollbackToLatestSnapshot(string $storageId): void;

    /**
     * Rollback storage to an existing snapshot. This rollback will destroy any
     * snapshots more recently created than the snapshot being rolled back to.
     * Contents of the storage will be replaced with the contents of the snapshot.
     * If the snapshot id does not exist or the storage cannot be rolled back, an exception will be thrown.
     *
     * @param string $snapshotId Id of the snapshot to rollback to
     * @param bool $destroyClones If true, also destroy any clones of more recent snapshots
     */
    public function rollbackToSnapshotDestructive(string $snapshotId, bool $destroyClones): void;

    /**
     * Clone a snapshot.
     * If the snapshot id does not exist, the clone name and parent already exist,
     *  or the clone cannot be created, an exception will be thrown.
     *
     * @param string $snapshotId Id of the snapshot to clone
     * @param CloneCreationContext $context Context that defines how the clone should be created
     * @return string Storage Id of the clone
     */
    public function cloneSnapshot(string $snapshotId, CloneCreationContext $context): string;

    /**
     * Promote a clone to a first-class storage.
     * If the clone id does not exist or the clone cannot be promoted, an exception will be thrown.
     *
     * @param string $cloneId Id of the clone
     */
    public function promoteClone(string $cloneId): void;
}
