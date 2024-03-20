<?php

namespace Datto\Backup\Stages;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Asset;
use Datto\Backup\Integrity\FilesystemIntegrity;
use Datto\Malware\RansomwareService;
use Datto\Restore\AssetCloneManager;
use Datto\Restore\CloneSpec;
use Exception;
use Throwable;

/**
 * This backup stage creates and destroys the clone and block devices used in
 * the RunFileIntegrityCheck and RansomwareService steps.
 *
 * @author Alex Mankowski <amankowski@datto.com>
 */
class PrepareLocalVerifications extends BackupStage
{
    const LOCAL_VERIFICATION_CLONE_SUFFIX = 'localverification';

    /** @var AssetCloneManager */
    private $assetCloneManager;
    private FilesystemIntegrity $filesystemIntegrity;
    private RansomwareService $ransomwareService;

    public function __construct(
        AssetCloneManager $assetCloneManager,
        FilesystemIntegrity $filesystemIntegrity,
        RansomwareService $ransomwareService
    ) {
        $this->assetCloneManager = $assetCloneManager;
        $this->filesystemIntegrity = $filesystemIntegrity;
        $this->ransomwareService = $ransomwareService;
    }

    /**
     * @inheritDoc
     */
    public function commit()
    {
        /** @var Agent $asset */
        $asset = $this->context->getAsset();

        // Setup Expectations
        $expectRansomwareChecks =
            $this->ransomwareService->isTestable($asset) &&
            $asset->getLocal()->isRansomwareCheckEnabled();
        $expectFilesystemChecks =
            $this->filesystemIntegrity->isSupported() &&
            $asset->getLocal()->isIntegrityCheckEnabled();
        $expectMissingVolumesCheck = $asset->getLocal()->isIntegrityCheckEnabled();

        $this->context->setExpectRansomwareChecks($expectRansomwareChecks);
        $this->context->setExpectFilesystemChecks($expectFilesystemChecks);
        $this->context->setExpectMissingVolumesChecks($expectMissingVolumesCheck);

        // Prepare Clone
        $this->cleanupClones($asset);

        $snapshotEpoch = $this->getLatestSnapshot($asset);

        // TODO: Replace this block with a call to DattoImageSnapshot->setupDattoImages
        $cloneSpec = CloneSpec::fromAsset($asset, $snapshotEpoch, self::LOCAL_VERIFICATION_CLONE_SUFFIX);
        if ($this->assetCloneManager->exists($cloneSpec)) {
            throw new Exception("A clone already exists for this {$asset->getKeyName()}@{$snapshotEpoch}");
        }

        $this->assetCloneManager->createClone($cloneSpec, $ensureDecrypted = false);

        $dattoImages = $this->context->getDattoImageFactory()->createImagesForSnapshot(
            $asset,
            $cloneSpec->getSnapshotName(),
            $cloneSpec->getTargetMountpoint()
        );

        foreach ($dattoImages as $dattoImage) {
            try {
                $dattoImage->acquire(true, true);
            } catch (Throwable $throwable) {
                $this->context->getLogger()->error(
                    "LDV0005 Error acquiring block devices for local data verification. " .
                    $throwable
                );
            }
        }

        $this->context->setLocalVerificationDattoImages($dattoImages);
    }

    /**
     * @inheritDoc
     */
    public function cleanup()
    {
        $dattoImages = $this->context->getLocalVerificationDattoImages();
        // TODO: Replace this block with a call to DattoImageSnapshot->cleanupDattoImages
        foreach ($dattoImages as $dattoImage) {
            try {
                $dattoImage->release();
            } catch (Throwable $throwable) {
                $this->context->getLogger()->error(
                    "LDV0004 Error releasing block devices for local data verification.",
                    ['message' => $throwable->getMessage()]
                );
            }
        }

        $asset = $this->context->getAsset();

        $this->cleanupClones($asset);
    }

    /**
     * Get the most recent snapshot for the current asset.
     *
     * @param Asset $asset
     * @return int Epoch time of the most recent snapshot
     */
    private function getLatestSnapshot(Asset $asset): int
    {
        $latestRecoveryPoint = $asset->getLocal()->getRecoveryPoints()->getLast();
        if (is_null($latestRecoveryPoint)) {
            throw new Exception("No local snapshots exist for asset {$asset->getKeyName()}");
        }
        return $latestRecoveryPoint->getEpoch();
    }

    /**
     * Destroy any existing snapshot clones used for local data verification for an asset.
     *
     * @param Asset $asset
     */
    private function cleanupClones(Asset $asset)
    {
        $this->context->getLogger()->debug('LDV0001 Cleaning up local data verification clones for ' . $asset->getKeyName());

        foreach ($this->assetCloneManager->getAllClones() as $cloneSpec) {
            if ($cloneSpec->getAssetKey() === $asset->getKeyName() &&
                $cloneSpec->getSuffix() === self::LOCAL_VERIFICATION_CLONE_SUFFIX
            ) {
                try {
                    $this->assetCloneManager->destroyClone($cloneSpec);
                } catch (Throwable $throwable) {
                    $this->context->getLogger()->error(
                        'LDV0003 Error destroying dataset for local data verification.',
                        ['clone' => $cloneSpec->getTargetDatasetName(), 'error' => $throwable->getMessage()]
                    );
                }
            }
        }
    }
}
