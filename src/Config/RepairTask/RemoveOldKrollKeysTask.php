<?php
namespace Datto\Config\RepairTask;

use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\Config\DeviceConfig;
use Datto\Common\Utility\Filesystem;
use Datto\Log\DeviceLoggerInterface;

/**
 * Remove old license files (from before we moved them to /datto/config/krollLicenses)
 *
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class RemoveOldKrollKeysTask implements ConfigRepairTaskInterface
{
    const OLD_LICENSE_LIST = 'krollLicenses';

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var Filesystem */
    private $filesystem;

    /** @var DeviceConfig */
    private $deviceConfig;

    /**
     * @param DeviceLoggerInterface $logger
     * @param Filesystem $filesystem
     * @param DeviceConfig $deviceConfig
     */
    public function __construct(DeviceLoggerInterface $logger, Filesystem $filesystem, DeviceConfig $deviceConfig)
    {
        $this->logger = $logger;
        $this->filesystem = $filesystem;
        $this->deviceConfig = $deviceConfig;
    }

    /**
     * @inheritdoc
     */
    public function run(): bool
    {
        $changesMade = false;
        $basePath = $this->deviceConfig->getBasePath();

        $exchangeKeys = $this->filesystem->glob($basePath.'Datto-500 EX-.5* TB-7-3*.ini');
        foreach ($exchangeKeys as $key) {
            $this->logger->warning(
                'CFG0003 Outdated license for Kroll OnTrack for Microsoft Exchange and Share Point deleted',
                ['key' => $key]
            );
            $this->filesystem->unlink($key);
            $changesMade = true;
        }

        $sqlKeys = array_merge(
            $this->filesystem->glob($basePath.'Datto-SQL-10* Instances-8-1_*.ini'),
            $this->filesystem->glob($basePath.'Datto-250 Instances-8-1_*.ini'),
            $this->filesystem->glob($basePath.'license_*.ini')
        );
        foreach ($sqlKeys as $key) {
            $this->logger->warning(
                'CFG0004 Outdated license for Kroll OnTrack for Microsoft SQL Server deleted',
                ['key' => $key]
            );
            $this->filesystem->unlink($key);
            $changesMade = true;
        }

        $licenseList = $basePath . self::OLD_LICENSE_LIST;
        if ($this->filesystem->isFile($licenseList)) {
            $this->logger->warning(
                'CFG0005 Outdated kroll license file list deleted',
                ['licenseList' => $licenseList]
            );
            $this->filesystem->unlink($licenseList);
            $changesMade = true;
        }

        return $changesMade;
    }
}
