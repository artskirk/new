<?php

namespace Datto\Restore;

use Datto\Asset\AssetService;
use Datto\Common\Resource\ProcessFactory;
use Datto\Core\Storage\StorageInfo;
use Datto\Core\Storage\StorageType;
use Datto\Util\DateTimeZoneService;

/**
 * Factory to create new Restore objects
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class RestoreFactory
{
    private AssetService $assetService;
    private ProcessFactory $processFactory;
    private DateTimeZoneService $dateTimeZoneService;

    public function __construct(
        AssetService $assetService,
        ProcessFactory $processFactory,
        DateTimeZoneService $dateTimeZoneService
    ) {
        $this->assetService = $assetService;
        $this->processFactory = $processFactory;
        $this->dateTimeZoneService = $dateTimeZoneService;
    }

    public function create(
        string $asset,
        string $point,
        string $suffix,
        string $activationTime = null,
        array $options = [],
        $html = null
    ): Restore {
        return new Restore(
            $asset,
            $point,
            $suffix,
            $activationTime,
            $options,
            $html,
            $this->assetService,
            $this->processFactory,
            $this->dateTimeZoneService
        );
    }

    public function createFromStorageInfo(StorageInfo $storageInfo): ?Restore
    {
        if ($storageInfo->getType() === StorageType::STORAGE_TYPE_FILE) {
            $mountpoint = $storageInfo->getFilePath();
        } else {
            $mountpoint = StorageInfo::STORAGE_PROPERTY_NOT_APPLICABLE;
        }

        $cloneSpec = CloneSpec::fromZfsDatasetAttributes(
            $storageInfo->getId(),
            $storageInfo->getParent(),
            $mountpoint
        );

        if (!isset($cloneSpec)) {
            return null;
        }

        return new Restore(
            $cloneSpec->getAssetKey(),
            $cloneSpec->getSnapshotName(),
            $cloneSpec->getSuffix(),
            $storageInfo->getCreationTime(),
            [],
            null,
            $this->assetService,
            $this->processFactory,
            $this->dateTimeZoneService
        );
    }
}
