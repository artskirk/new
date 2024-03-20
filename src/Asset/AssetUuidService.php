<?php

namespace Datto\Asset;

use Datto\Asset\AssetService;
use Datto\Asset\UuidGenerator;
use Datto\Log\DeviceLoggerInterface;

/**
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class AssetUuidService
{
    const ZFS_DATTO_UUID_PROPERTY = 'datto:uuid';

    /** @var AssetService */
    protected $assetService;

    /** @var UuidGenerator */
    protected $uuidGenerator;

    /** @var DeviceLoggerInterface */
    protected $logger;

    /**
     * AssetUuidService constructor.
     * @param AssetService $assetService
     * @param UuidGenerator $uuidGenerator
     * @param DeviceLoggerInterface $logger
     */
    public function __construct(
        AssetService $assetService,
        UuidGenerator $uuidGenerator,
        DeviceLoggerInterface $logger
    ) {
        $this->assetService = $assetService;
        $this->uuidGenerator = $uuidGenerator;
        $this->logger = $logger;
    }

    /**
     * Generates a UUID for any existing assets that don't have a UUID.
     * Sets the ZFS "datto:uuid" property on any existing assets that don't
     * have that ZFS property set.
     */
    public function generateMissing(): void
    {
        $assetList = $this->assetService->getAll();

        foreach ($assetList as $asset) {
            $uuid = $asset->getUuid();
            $isValidUuid = UuidGenerator::isUuid($uuid);
            if (!$isValidUuid) {
                $uuid = $this->uuidGenerator->get();
                $asset->setUuid($uuid);
                try {
                    $this->assetService->save($asset);
                    $asset->getDataset()->setAttribute(self::ZFS_DATTO_UUID_PROPERTY, $uuid);
                    $this->logger->info('AID1002 Assigned UUID to existing asset', ['uuid' => $uuid, 'assetKey' => $asset->getKeyName()]);
                } catch (\Exception $e) {
                    $this->logger->error('AID1001 Error setting UUID for existing asset', ['assetKey' => $asset->getKeyName(), 'exception' => $e]);
                }
            } else {
                try {
                    $dataset = $asset->getDataset();
                    if ($dataset->getAttribute(self::ZFS_DATTO_UUID_PROPERTY) === false) {
                        $dataset->setAttribute(self::ZFS_DATTO_UUID_PROPERTY, $uuid);
                        $this->logger->info('AID1004 Assigned ZFS UUID property to existing asset', ['uuid' => $uuid, 'assetKey' => $asset->getKeyName()]);
                    }
                } catch (\Exception $e) {
                    $this->logger->error('AID1003 Error setting ZFS UUID property for existing asset', ['assetKey' => $asset->getKeyName(), 'exception' => $e]);
                }
            }
        }
    }
}
