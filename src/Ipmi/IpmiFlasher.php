<?php

namespace Datto\Ipmi;

use Datto\Common\Resource\ProcessFactory;
use Datto\Config\DeviceConfig;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Datto\Log\DeviceLoggerInterface;

/**
 * Handles flashing the IPMI firmware on the motherboard.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class IpmiFlasher
{
    const FLASHING_BINARY = '/usr/lib/datto/device/app/Resources/Ipmi/Update/socflash_x64';
    // WARNING: This must NEVER timeout during the flashing process!!!
    // If it does, it will brick your system!  So this should be a BIG number.
    const FLASHING_TIMEOUT_SECONDS = null;
    const BACKUP_TIMEOUT_SECONDS = 900;
    const BACKUP_DIR = '/host/config';

    private ProcessFactory $processFactory;

    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var Filesystem */
    private $filesystem;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var DateTimeService */
    private $dateService;

    public function __construct(
        ProcessFactory $processFactory,
        DeviceConfig $deviceConfig,
        Filesystem $filesystem,
        DeviceLoggerInterface $logger,
        DateTimeService $dateService
    ) {
        $this->processFactory = $processFactory;
        $this->deviceConfig = $deviceConfig;
        $this->filesystem = $filesystem;
        $this->logger = $logger;
        $this->dateService = $dateService;
    }

    /**
     * Check if flashing is needed.
     *
     * @return bool
     */
    public function isFlashingNeeded(): bool
    {
        $ipmiMotherboard = FlashableIpmi::create();
        $record = $this->getIpmiVersion();

        return $this->filesystem->sha1($ipmiMotherboard->getBmcFirmwarePath()) !== $record->getBmcFirmwareSha1();
    }

    /**
     * Flash the IPMI firmware with a new version.
     *
     * @see docs/IpmiFirmware.md for documentation.
     */
    public function flash()
    {
        try {
            $ipmi = FlashableIpmi::create();

            $firmwarePath = $ipmi->getBmcFirmwarePath();
            $offsetString = $ipmi->getBmcOffsetString();

            $this->logger->info('IPF0000 Flashing IPMI firmware', ['firmwarePath' => $firmwarePath]);

            $process = $this->processFactory
                ->get([
                    self::FLASHING_BINARY,
                    "if=" . basename($firmwarePath),
                    "skip=$offsetString",
                    "offset=$offsetString"
                ])
                ->setWorkingDirectory(dirname($firmwarePath)) // socflash_x64 if paths must be <= 50 characters
                ->setTimeout(self::FLASHING_TIMEOUT_SECONDS);

            $process->mustRun();
            $this->setIpmiVersion($firmwarePath);

            $this->logger->info('IPF0001 IPMI firmware flashing complete');
        } catch (\Throwable $e) {
            throw new \Exception("Unable to flash IPMI firmware: " . $e->getMessage());
        }
    }

    /**
     * Take a backup of the IPMI firmware.
     *
     * @see docs/IpmiFirmware.md for documentation.
     *
     * @param string|null $backupPath Optional backup path.
     */
    public function backup(string $backupPath = null)
    {
        try {
            $backupPath = $backupPath ?? $this->getDefaultBackupPath();

            $this->logger->info('IPF0002 Backing up IPMI firmware ...', ['backupPath' => $backupPath]);

            $this->filesystem->mkdirIfNotExists(dirname($backupPath), true, 0777);
            $process = $this->processFactory
                ->get([self::FLASHING_BINARY, "of=" . basename($backupPath)])
                ->setTimeout(self::BACKUP_TIMEOUT_SECONDS)
                ->setWorkingDirectory(dirname($backupPath)); // socflash_x64 if paths must be <= 50 characters;

            $process->mustRun();

            $this->logger->info('IPF0003 IPMI firmware backup complete');
        } catch (\Throwable $e) {
            throw new \Exception("Unable to backup IPMI firmware: " . $e->getMessage());
        }
    }

    /**
     * Restore the IPMI firmware back to a backup.
     *
     * @see docs/IpmiFirmware.md for documentation.
     *
     * @param string|null $backupPath Optional backup path (defaults to path/to/firmware.bak)
     */
    public function restore(string $backupPath = null)
    {
        try {
            $backupPath = $backupPath ?? $this->getDefaultBackupPath();

            $this->logger->info('IPF0004 Restoring IPMI firmware', ['backupPath' => $backupPath]);

            if (!$this->filesystem->exists($backupPath)) {
                throw new \Exception("Backup does not exist: " . $backupPath);
            }

            $process = $this->processFactory
                ->get([self::FLASHING_BINARY, "if=" . basename($backupPath)])
                ->setTimeout(self::FLASHING_TIMEOUT_SECONDS)
                ->setWorkingDirectory(dirname($backupPath)); // socflash_x64 if paths must be <= 50 characters

            $process->mustRun();
            $this->setIpmiVersion($backupPath);

            $this->logger->info('IPF0005 IPMI firmware restore complete');
        } catch (\Throwable $e) {
            throw new \Exception("Unable to restore IPMI firmware from backup: " . $e->getMessage());
        }
    }

    /**
     * @param string $firmwarePath
     */
    private function setIpmiVersion(string $firmwarePath)
    {
        $this->deviceConfig->saveRecord(new IpmiVersionRecord(
            $this->filesystem->sha1($firmwarePath),
            $this->dateService->getTime()
        ));
    }

    /**
     * @return IpmiVersionRecord
     */
    private function getIpmiVersion(): IpmiVersionRecord
    {
        $record = new IpmiVersionRecord();
        $this->deviceConfig->loadRecord($record);
        return $record;
    }

    /**
     * @return string
     */
    private function getDefaultBackupPath(): string
    {
        $ipmi = FlashableIpmi::create();
        $firmwareName = basename($ipmi->getBmcFirmwarePath());

        return self::BACKUP_DIR . '/' . $firmwareName . '.bak';
    }
}
