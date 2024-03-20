<?php

namespace Datto\DirectToCloud\Creation\Stages;

use Datto\Asset\Agent\Encryption\CloudEncryptionService;
use Datto\Asset\AssetInfoSyncService;
use Datto\Cloud\SpeedSync;
use Datto\Common\Resource\Sleep;
use Datto\Config\DeviceConfig;
use Datto\Log\DeviceLoggerInterface;
use Datto\Utility\Cloud\SpeedSync as SpeedSyncUtility;
use Datto\Utility\Screen;
use Throwable;

/**
 * Notify the cloud of the new asset.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class UpdateCloud extends AbstractStage
{
    const MAX_WAIT = 60;

    private AssetInfoSyncService $assetInfoSyncService;
    private DeviceConfig $deviceConfig;
    private SpeedSync $speedSync;
    private Screen $screen;
    private Sleep $sleep;
    private CloudEncryptionService $cloudEncryptionService;

    public function __construct(
        DeviceLoggerInterface $logger,
        AssetInfoSyncService $assetInfoSyncService,
        DeviceConfig $deviceConfig,
        SpeedSync $speedSync,
        Screen $screen,
        Sleep $sleep,
        CloudEncryptionService $cloudEncryptionService
    ) {
        parent::__construct($logger);
        $this->assetInfoSyncService = $assetInfoSyncService;
        $this->deviceConfig = $deviceConfig;
        $this->speedSync = $speedSync;
        $this->screen = $screen;
        $this->sleep = $sleep;
        $this->cloudEncryptionService = $cloudEncryptionService;
    }

    /**
     * {@inheritdoc}
     */
    public function commit()
    {
        $this->logger->info("DCS0007 Updating information in the cloud ...");

        try {
            $this->logger->debug('DCS0012 Syncing asset to cloud');
            $this->assetInfoSyncService->sync($this->context->getAssetKey());

            if ($this->context->getAssetMetadata()->getEncryptionKeyStashRecord() !== null) {
                $this->logger->debug('DCS0013 Syncing encryption keys to cloud');
                $this->cloudEncryptionService->uploadEncryptionKeys($this->context->getAssetKey(), false);
            }
        } catch (Throwable $e) {
            $this->logger->warning("DCS0008 Could not update cloud", [
                'exception' => $e->getMessage()
            ]);
        }

        if ($this->deviceConfig->isAzureDevice()) {
            $zfsPath = $this->context->getDataset()->getName();

            $this->logger->debug("DCS0009 Adding dataset to speedsync");

            $this->speedSync->add($zfsPath, $this->context->getOffsiteTarget());

            if ($this->context->isArchived()) {
                $this->logger->debug('DCS0010 Refreshing speedsync in background');

                $this->speedSync->refreshBackground($zfsPath);

                $this->logger->debug('DCS0015 Waiting for background refresh to complete');
                for ($i = 0; $i < self::MAX_WAIT && $this->isScreenRunning(); $i++) {
                    $this->sleep->sleep(1);
                }
                $this->logger->debug('DCS0016 Background refresh completed');
            }
        }
    }

    private function isScreenRunning(): bool
    {
        return $this->screen->isScreenRunning(SpeedSyncUtility::REFRESH_SCREEN);
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup()
    {
        // Nothing.
    }

    /**
     * {@inheritdoc}
     */
    public function rollback()
    {
        // Nothing.
    }
}
