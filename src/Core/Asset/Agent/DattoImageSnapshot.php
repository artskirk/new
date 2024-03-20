<?php

namespace Datto\Core\Asset\Agent;

use Datto\Asset\Agent\DattoImage;
use Datto\Asset\Agent\DattoImageFactory;
use Datto\Asset\Asset;
use Datto\Restore\AssetCloneManager;
use Datto\Restore\CloneSpec;
use Exception;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * Class: DattoImageSnapshot handles setup and cleanup of DattoImages and clones associated with a snapshot
 *
 * @author Mark Blakey <mblakley@datto.com>
 */
class DattoImageSnapshot
{
    /**
     * @var AssetCloneManager
     */
    private $assetCloneManager;

    /**
     * @var DattoImageFactory
     */
    private $dattoImageFactory;

    /**
     * @var DeviceLoggerInterface
     */
    private $logger;

    /**
     * @param AssetCloneManager $assetCloneManager
     * @param DattoImageFactory $dattoImageFactory
     * @param DeviceLoggerInterface $logger
     */
    public function __construct(
        AssetCloneManager $assetCloneManager,
        DattoImageFactory $dattoImageFactory,
        DeviceLoggerInterface $logger
    ) {
        $this->assetCloneManager = $assetCloneManager;
        $this->dattoImageFactory = $dattoImageFactory;
        $this->logger = $logger;
    }

    /**
     * @param Asset $asset
     * @param int $snapshotEpoch
     * @param string $cloneSuffix
     * @return DattoImage[]
     */
    public function setup(Asset $asset, int $snapshotEpoch, string $cloneSuffix): array
    {
        $dattoImages = [];
        try {
            $cloneSpec = CloneSpec::fromAsset(
                $asset,
                $snapshotEpoch,
                $cloneSuffix
            );
            if ($this->assetCloneManager->exists($cloneSpec)) {
                throw new Exception("A clone already exists for this {$asset->getKeyName()}@{$snapshotEpoch}");
            }
            $this->assetCloneManager->createClone($cloneSpec, $ensureDecrypted = false);

            $dattoImages = $this->dattoImageFactory->createImagesForSnapshot(
                $asset,
                $cloneSpec->getSnapshotName(),
                $cloneSpec->getTargetMountpoint()
            );

            foreach ($dattoImages as $dattoImage) {
                $dattoImage->acquire(true, true);
            }
        } catch (Throwable $throwable) {
            $this->logger->error(
                'DIS0001 An error occurred while cloning snapshot and acquiring the datto images',
                ['snapshot' => $snapshotEpoch, 'assetKeyName' => $asset->getKeyName(), 'exception' => $throwable]
            );
            $this->cleanup($asset, $snapshotEpoch, $cloneSuffix, $dattoImages);
            throw new Exception("An error occurred while cloning snapshot $snapshotEpoch for asset " .
                "{$asset->getKeyName()} and acquiring the datto images: $throwable");
        }

        return $dattoImages;
    }

    /**
     * Release all DattoImages and cleanup any clones that were created
     *
     * @param Asset $asset
     * @param int $snapshotEpoch
     * @param string $cloneSuffix
     * @param DattoImage[] $dattoImages
     */
    public function cleanup(Asset $asset, int $snapshotEpoch, string $cloneSuffix, array $dattoImages)
    {
        foreach ($dattoImages as $dattoImage) {
            try {
                $dattoImage->release();
            } catch (Throwable $throwable) {
                $this->logger->error(
                    'DIS0002 Error releasing block devices for datto images',
                    ['exception' => $throwable]
                );
            }
        }

        $cloneSpec = CloneSpec::fromAsset(
            $asset,
            $snapshotEpoch,
            $cloneSuffix
        );
        if ($this->assetCloneManager->exists($cloneSpec)) {
            try {
                $this->assetCloneManager->destroyClone($cloneSpec);
            } catch (Throwable $throwable) {
                $this->logger->error(
                    'DIS0003 Error destroying clone dataset for datto image cleanup',
                    ['cloneDatasetName' => $cloneSpec->getTargetDatasetName(), 'exception' => $throwable]
                );
            }
        }
    }
}
