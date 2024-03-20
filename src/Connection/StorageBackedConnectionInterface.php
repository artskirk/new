<?php
namespace Datto\Connection;

use Datto\Metadata\FileAccess\FileAccess;

/**
 * Class: StorageBackedConnectionInterface
 *
 * Allows to implement hypervisor connections that store their config data using
 * an arbitrary storage backend.
 *
 */
interface StorageBackedConnectionInterface
{
    /**
     * Sets the class dependency for handling saving/loading the connection data.
     *
     * @todo This is currently limited to FileAccess backend as we lack
     *       StorageBackendInterface or such that would allow us to plugin
     *       any arbitrary storage backend for persisting data.
     *
     * @param FileAccess $storageBackend
     */
    public function setStorageBackend(FileAccess $storageBackend);

    /**
     * Gets the storage backend used to persist the connection data.
     *
     * @return FileAccess
     *  Currently only FileAccess backend is available.
     */
    public function getStorageBackend();
    
    /**
     * Loads the object data from storage backned.
     */
    public function loadData();

    /**
     * Saves the object data to storage backend.
     *
     * @return bool
     */
    public function saveData();

    /**
     * Deletes object data from storage backend (and object itself).
     */
    public function deleteData();
}
