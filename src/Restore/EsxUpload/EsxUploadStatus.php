<?php

namespace Datto\Restore\EsxUpload;

use Datto\Config\JsonConfigRecord;

/**
 * Represents the progress/status of an esx upload restore
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class EsxUploadStatus extends JsonConfigRecord
{
    // these correspond to translations (restore.esx.upload.status.<const>)
    const STATUS_STARTING = 'starting';
    const STATUS_CLONE = 'clone';
    const STATUS_DATASTORE = 'datastore';
    const STATUS_UPLOADING = 'uploading';

    /** @var int */
    private $snapshot;

    /** @var string */
    private $status = '';
    
    /** @var string */
    private $error = '';

    /** @var string[] */
    private $vmdks = [];

    /** @var string */
    private $datastore = '';

    /** @var string */
    private $directory = '';

    /** @var bool */
    private $createdDirectory = false;
    
    /** @var int */
    private $uploadedSize = 0;

    /** @var int */
    private $totalSize = 0;

    /** @var bool */
    private $isFinished = false;

    /** @var int */
    private $pid = 0;

    /** @var string */
    private $connectionName = '';

    /** @var string */
    private $currentVmdk = '';

    /**
     * @param int $snapshot
     */
    public function __construct(int $snapshot)
    {
        $this->snapshot = $snapshot;
    }

    /**
     * @return int
     */
    public function getSnapshot(): int
    {
        return $this->snapshot;
    }

    /**
     * @param string $status
     */
    public function setStatus(string $status)
    {
        $this->status = $status;
    }

    /**
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @param string $error
     */
    public function setError(string $error)
    {
        $this->error = $error;
    }

    /**
     * @return string
     */
    public function getError(): string
    {
        return $this->error;
    }

    /**
     * @return int
     */
    public function getUploadedSize(): int
    {
        return $this->uploadedSize;
    }

    /**
     * @param int $uploadedSize
     */
    public function setUploadedSize(int $uploadedSize)
    {
        $this->uploadedSize = $uploadedSize;
    }

    /**
     * @return int
     */
    public function getTotalSize(): int
    {
        return $this->totalSize;
    }

    /**
     * @param int $totalSize
     */
    public function setTotalSize(int $totalSize)
    {
        $this->totalSize = $totalSize;
    }

    /**
     * @return bool
     */
    public function isFinished(): bool
    {
        return $this->isFinished;
    }

    /**
     * @param bool $isFinished
     */
    public function setFinished(bool $isFinished)
    {
        $this->isFinished = $isFinished;
    }

    /**
     * @return string name of key file that this config record will be stored to
     */
    public function getKeyName(): string
    {
        return $this->snapshot . '.esxUploadStatus';
    }

    /**
     * @return string[]
     */
    public function getVmdks(): array
    {
        return $this->vmdks;
    }

    /**
     * @param string[] $vmdks
     */
    public function setVmdks(array $vmdks)
    {
        $this->vmdks = $vmdks;
    }

    /**
     * @return string
     */
    public function getDatastore(): string
    {
        return $this->datastore;
    }

    /**
     * @param string $datastore
     */
    public function setDatastore(string $datastore)
    {
        $this->datastore = $datastore;
    }

    /**
     * @return string
     */
    public function getDirectory(): string
    {
        return $this->directory;
    }

    /**
     * @param string $directory
     */
    public function setDirectory(string $directory)
    {
        $this->directory = $directory;
    }

    /**
     * @return bool
     */
    public function createdDirectory(): bool
    {
        return $this->createdDirectory;
    }

    /**
     * @param bool $createdDirectory
     */
    public function setCreatedDirectory(bool $createdDirectory)
    {
        $this->createdDirectory = $createdDirectory;
    }

    /**
     * @return int
     */
    public function getPid(): int
    {
        return $this->pid;
    }

    /**
     * @param int $pid
     */
    public function setPid(int $pid)
    {
        $this->pid = $pid;
    }

    /**
     * @return string
     */
    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    /**
     * @param string $connectionName
     */
    public function setConnectionName(string $connectionName)
    {
        $this->connectionName = $connectionName;
    }

    /**
     * @return string
     */
    public function getCurrentVmdk(): string
    {
        return $this->currentVmdk;
    }

    /**
     * @param string $currentVmdk
     */
    public function setCurrentVmdk(string $currentVmdk)
    {
        $this->currentVmdk = $currentVmdk;
    }

    /**
     * Load the config record instance using values from associative array.
     *
     * @param array $vals
     */
    protected function load(array $vals)
    {
        $this->status = $vals['status'] ?? '';
        $this->error = $vals['error'] ?? '';
        $this->uploadedSize = $vals['uploadedSize'] ?? 0;
        $this->totalSize = $vals['totalSize'] ?? 0;
        $this->isFinished = $vals['isFinished'] ?? false;
        $this->datastore = $vals['datastore'] ?? '';
        $this->directory = $vals['directory'] ?? '';
        $this->createdDirectory = $vals['createdDirectory'] ?? false;
        $this->vmdks = $vals['vmdks'] ?? [];
        $this->pid = $vals['pid'] ?? 0;
        $this->connectionName = $vals['connectionName'] ?? '';
        $this->currentVmdk = $vals['currentVmdk'] ?? '';
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            'status' => $this->status,
            'error' => $this->error,
            'uploadedSize' => $this->uploadedSize,
            'totalSize' => $this->totalSize,
            'isFinished' => $this->isFinished,
            'datastore' => $this->datastore,
            'directory' => $this->directory,
            'createdDirectory' => $this->createdDirectory,
            'vmdks' => $this->vmdks,
            'pid' => $this->pid,
            'connectionName' => $this->connectionName,
            'currentVmdk' => $this->currentVmdk
        ];
    }
}
