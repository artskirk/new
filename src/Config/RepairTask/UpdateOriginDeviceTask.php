<?php

namespace Datto\Config\RepairTask;

use Datto\Asset\AssetService;
use Datto\Asset\Serializer\OriginDeviceSerializer;
use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\Config\DeviceConfig;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * Ensure the originDevice key exists for all assets.
 * The originDevice key deals with whether the asset was replicated from another device.
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class UpdateOriginDeviceTask implements ConfigRepairTaskInterface
{
    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var AssetService */
    private $assetService;

    /** @var OriginDeviceSerializer */
    private $originDeviceSerializer;

    /** @var DeviceConfig */
    private $deviceConfig;

    /**
     * @param DeviceLoggerInterface $logger
     * @param AssetService $assetService
     * @param OriginDeviceSerializer $originDeviceSerializer
     * @param DeviceConfig $deviceConfig
     */
    public function __construct(
        DeviceLoggerInterface $logger,
        AssetService $assetService,
        OriginDeviceSerializer $originDeviceSerializer,
        DeviceConfig $deviceConfig
    ) {
        $this->logger = $logger;
        $this->assetService = $assetService;
        $this->originDeviceSerializer = $originDeviceSerializer;
        $this->deviceConfig = $deviceConfig;
    }

    /**
     * @inheritdoc
     */
    public function run(): bool
    {
        $hasUpdatedAny = false;
        $assets = $this->assetService->getAll();

        foreach ($assets as $asset) {
            $update = false;

            try {
                // if the device id is null, this asset was created before replication existed.
                // Therefore, we can assume that the asset originated on this device.
                if ($asset->getOriginDevice()->getDeviceId() === null) {
                    $asset->getOriginDevice()->setDeviceId($this->deviceConfig->getDeviceId());
                    $asset->getOriginDevice()->setResellerId($this->deviceConfig->getResellerId());
                    $update = true;
                }

                // If this asset is from another device, that means it's replicated
                if ($asset->getOriginDevice()->getDeviceId() !== (int) $this->deviceConfig->getDeviceId() && !$asset->getOriginDevice()->isReplicated()) {
                    $asset->getOriginDevice()->setReplicated(true);
                    $update = true;
                }

                if ($update) {
                    $this->assetService->save($asset);

                    $serializedOriginDevice = json_encode($this->originDeviceSerializer->serialize($asset->getOriginDevice()));
                    $this->logger->info('CFG0100 Updated originDevice key file contents', ['assetKeyName' => $asset->getKeyName(), 'originDeviceContents' => $serializedOriginDevice]);
                    $hasUpdatedAny = true;
                }
            } catch (Throwable $e) {
                $this->logger->error('CFG0130 Exception encountered during update origin device task', ['exception' => $e]);
            }
        }

        return $hasUpdatedAny;
    }
}
