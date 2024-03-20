<?php

namespace Datto\Config\RepairTask;

use Datto\Config\DeviceConfig;
use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\Log\DeviceLoggerInterface;
use Datto\System\Hardware;
use Datto\Utility\ByteUnit;

/**
 * Consolidate the various legacy Siris Lite key files into one.
 *
 * @author Stephen Allan <sallan@datto.com>
 */
class ConsolidateSirisLiteKeys implements ConfigRepairTaskInterface
{
    const SIRIS_MIN_RAM_GIB = 4;
    const LEGACY_SIRIS_LITE_KEYS = [
        DeviceConfig::KEY_IS_LIGHT,
        DeviceConfig::KEY_S_LIGHT,
        DeviceConfig::KEY_IS_S_LIGHT,
        DeviceConfig::KEY_IS_LITE,
        DeviceConfig::KEY_S_LITE
    ];

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var Hardware */
    private $hardware;

    public function __construct(DeviceLoggerInterface $logger, DeviceConfig $deviceConfig, Hardware $hardware)
    {
        $this->logger = $logger;
        $this->deviceConfig = $deviceConfig;
        $this->hardware = $hardware;
    }

    /**
     * Remove the legacy Siris Lite key files, and ensure the non-deprecated file remains.
     *
     * @return bool True if at least one legacy key file was removed, False otherwise
     */
    public function run(): bool
    {
        if ($this->deviceConfig->has(DeviceConfig::KEY_IS_AZURE_DEVICE)) {
            // Azure devices are not Siris Lites regardless of size
            return false;
        }

        $hasSirisLiteKeyFile = $this->deviceConfig->has(DeviceConfig::KEY_IS_S_LITE);
        $hasLowRam = $this->hardware->getPhysicalRamMiB() < ByteUnit::GIB()->toMiB(self::SIRIS_MIN_RAM_GIB);
        $isSirisLite = $hasSirisLiteKeyFile || $hasLowRam;

        $updatedConfig = false;
        foreach (self::LEGACY_SIRIS_LITE_KEYS as $key) {
            if ($this->deviceConfig->has($key)) {
                $isSirisLite = true;

                $this->logger->warning(
                    'CFG0015 Clearing device key',
                    ['keyPath' => $this->deviceConfig->getBasePath() . '/' . $key]
                );
                $updatedConfig |= $this->deviceConfig->clear($key);
            }
        }

        if ($isSirisLite && !$hasSirisLiteKeyFile) {
            $this->logger->info(
                'CFG0025 Setting device key',
                ['keyPath' => $this->deviceConfig->getBasePath() . '/' . DeviceConfig::KEY_IS_S_LITE]
            );
            $updatedConfig |= $this->deviceConfig->setRaw(DeviceConfig::KEY_IS_S_LITE, '');
        }

        return $updatedConfig;
    }
}
