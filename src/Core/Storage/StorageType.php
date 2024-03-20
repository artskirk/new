<?php

namespace Datto\Core\Storage;

/**
 * Represents the different types of storage.
 * Not all backends will support all storage types.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class StorageType
{
    const STORAGE_TYPE_UNKNOWN = 'unknown';
    const STORAGE_TYPE_OBJECT = 'object';
    const STORAGE_TYPE_FILE = 'file';
    const STORAGE_TYPE_BLOCK = 'block';
}
