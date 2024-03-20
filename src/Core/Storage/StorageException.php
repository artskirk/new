<?php

namespace Datto\Core\Storage;

use Exception;
use Throwable;

/**
 * Exception that is thrown for all storage exceptions
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class StorageException extends Exception
{
    public const ID_UNKNOWN = 'unknown';
    public const OPERATION_UNKNOWN = 'unknown';
    public const STORAGE_TYPE_UNKNOWN = 'unknown';

    /** @var string Id of the storage element. This could be the id of a pool, storage, snapshot, etc. */
    private string $id;

    /** @var string Operation that was requested when excpetion was thrown */
    private string $operation;

    /** @var string Storage type. See StorageType enumeration for valid values */
    private string $storageType;

    public function __construct(
        string $message = '',
        string $id = self::ID_UNKNOWN,
        string $operation = self::OPERATION_UNKNOWN,
        string $storageType = self::STORAGE_TYPE_UNKNOWN,
        int $code = 0,
        Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);

        $this->id = $id;
        $this->operation = $operation;
        $this->storageType = $storageType;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function getStorageType(): string
    {
        return $this->storageType;
    }
}
