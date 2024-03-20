<?php

namespace Datto\Backup\File;

use Datto\Filesystem\PartitionService;
use Datto\Filesystem\SparseFileService;
use Datto\Log\DeviceLoggerInterface;
use Datto\System\HealthService;

/**
 * Create instances of BackupImageFile
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class BackupImageFileFactory
{
    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var SparseFileService */
    private $sparseFileService;

    /** @var PartitionService */
    private $partitionService;

    /** @var HealthService */
    private $healthService;

    /**
     * @param DeviceLoggerInterface $logger
     * @param SparseFileService $sparseFileService
     * @param PartitionService $partitionService
     */
    public function __construct(
        DeviceLoggerInterface $logger,
        SparseFileService $sparseFileService,
        PartitionService $partitionService,
        HealthService $healthService
    ) {
        $this->logger = $logger;
        $this->sparseFileService = $sparseFileService;
        $this->partitionService = $partitionService;
        $this->healthService = $healthService;
    }

    /**
     * Create instance of BackupImageFile for Linux
     *
     * @return BackupImageFile
     */
    public function createLinux(): BackupImageFile
    {
        return new LinuxBackupImageFile(
            $this->logger,
            $this->sparseFileService,
            $this->partitionService,
            $this->healthService
        );
    }

    /**
     * Create instance of BackupImageFile for Agentless Linux
     *
     * @return BackupImageFile
     */
    public function createAgentlessLinux(): BackupImageFile
    {
        return new AgentlessLinuxBackupImageFile(
            $this->logger,
            $this->sparseFileService,
            $this->partitionService,
            $this->healthService
        );
    }

    /**
     * Create instance of BackupImageFile for Mac
     *
     * @return BackupImageFile
     */
    public function createMac(): BackupImageFile
    {
        return new MacBackupImageFile(
            $this->logger,
            $this->sparseFileService,
            $this->partitionService,
            $this->healthService
        );
    }

    /**
     * Create instance of BackupImageFile for Windows
     *
     * @return BackupImageFile
     */
    public function createWindows(): BackupImageFile
    {
        return new WindowsBackupImageFile(
            $this->logger,
            $this->sparseFileService,
            $this->partitionService,
            $this->healthService
        );
    }

    /**
     * Create instance of FullDiskBackupImageFile for generic backups
     *
     * @return BackupImageFile
     */
    public function createFullDisk(): BackupImageFile
    {
        return new FullDiskBackupImageFile($this->logger, $this->sparseFileService);
    }
}
