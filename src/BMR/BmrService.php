<?php

namespace Datto\BMR;

use Datto\Asset\Asset;
use Datto\Restore\AssetCloneManager;
use Datto\Restore\CloneSpec;
use Datto\Restore\RestoreService;
use Datto\Resource\DateTimeService;

/**
 * Handles creating BMRs
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 * @deprecated No longer used with the new "pull" cloning on the stick.
 */
class BmrService
{
    const BMR_RESTORE_EXTENSION = 'bmr';

    /** @var AssetCloneManager */
    private $assetCloneManager;

    /** @var RestoreService */
    private $restoreService;

    /** @var DateTimeService */
    private $dateTimeService;

    /**
     * @param AssetCloneManager $assetCloneManager
     * @param RestoreService $restoreService
     * @param DateTimeService $dateTimeService
     */
    public function __construct(
        AssetCloneManager $assetCloneManager,
        RestoreService $restoreService,
        DateTimeService $dateTimeService
    ) {
        $this->assetCloneManager = $assetCloneManager;
        $this->restoreService = $restoreService;
        $this->dateTimeService = $dateTimeService;
    }

    /**
     * Creates a zfs clone and a UI restores entry for BMR
     *
     * @param Asset $asset
     * @param int $snapshotEpoch
     * @param string $extension
     */
    public function create(Asset $asset, int $snapshotEpoch, string $extension)
    {
        $cloneSpec = CloneSpec::fromAsset($asset, $snapshotEpoch, $extension);
        $this->assetCloneManager->createClone($cloneSpec);
        $restore = $this->restoreService->create(
            $asset->getKeyName(),
            $snapshotEpoch,
            static::BMR_RESTORE_EXTENSION,
            $this->dateTimeService->getTime()
        );
        
        $this->restoreService->add($restore);
        $this->restoreService->save();
    }

    /**
     * Destroys ZFS clone and UI restores entry
     *
     * @param Asset $asset
     * @param int $snapshotEpoch
     * @param string $extension
     */
    public function destroy(Asset $asset, int $snapshotEpoch, string $extension)
    {
        $cloneSpec = CloneSpec::fromAsset($asset, $snapshotEpoch, $extension);
        $this->assetCloneManager->destroyClone($cloneSpec);
        $restore = $this->restoreService->get(
            $asset->getKeyName(),
            $snapshotEpoch,
            static::BMR_RESTORE_EXTENSION
        );

        $this->restoreService->remove($restore);
        $this->restoreService->save();
    }
}
