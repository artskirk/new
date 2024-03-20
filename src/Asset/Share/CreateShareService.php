<?php

namespace Datto\Asset\Share;

use Datto\Asset\AssetInfoSyncService;
use Datto\Asset\OffsiteSettings;
use Datto\Asset\OriginDevice;
use Datto\Asset\Retention;
use Datto\Billing\Service as BillingService;
use Datto\Cloud\SpeedSync;
use Datto\Config\DeviceConfig;
use Datto\Feature\FeatureService;
use Datto\Log\LoggerAwareTrait;
use Datto\Replication\ReplicationService;
use Datto\Common\Utility\Filesystem;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Responsible for creating shares
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class CreateShareService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const CREATE_IN_PROGRESS = 'creating';
    const CREATE_NOT_IN_PROGRESS = '';
    const CREATE_IN_PROGRESS_FORMAT = '/tmp/share-create-%s.tmp';

    private ShareRepository $repository;
    private ShareService $shareService;
    private Filesystem $filesystem;
    private SpeedSync $speedSync;
    private BillingService $billingService;
    private DeviceConfig $deviceConfig;
    private FeatureService $featureService;
    private ReplicationService $replicationService;
    private AssetInfoSyncService $assetInfoSyncService;

    public function __construct(
        ShareRepository $repository,
        ShareService $shareService,
        Filesystem $filesystem,
        SpeedSync $speedSync,
        BillingService $billingService,
        DeviceConfig $deviceConfig,
        FeatureService $featureService,
        ReplicationService $replicationService,
        AssetInfoSyncService $assetInfoSyncService
    ) {
        $this->repository = $repository;
        $this->shareService = $shareService;
        $this->filesystem = $filesystem;
        $this->speedSync = $speedSync;
        $this->billingService = $billingService;
        $this->deviceConfig = $deviceConfig;
        $this->featureService = $featureService;
        $this->replicationService = $replicationService;
        $this->assetInfoSyncService = $assetInfoSyncService;
    }

    /**
     * Creates the given share based on the given model.
     *
     * The method writes a temporary file to indicate a share that is currently in creation.
     *
     * @param Share $share Share to be created
     * @param string $size Size of the zvol to create, ex '16T'
     * @param Share|null $template A share from which settings are to be copied
     * @return Share Created shares
     */
    public function create(Share $share, string $size, Share $template = null): Share
    {
        $this->logger->setAssetContext($share->getKeyName());
        $inProgressFile = $this->getCreateInProgressFile($share->getKeyName()); // todo leverage addProgress file?
        try {
            $this->preflightChecks($share, $template);
            $this->filesystem->touch($inProgressFile);

            $isInfiniteRetention = $this->billingService->isInfiniteRetention();
            $timeBasedRetentionYears = $this->billingService->getTimeBasedRetentionYears();

            $share->create($size);

            if ($template) {
                $this->shareService->save($share);
                // reload to get updated SambaShare
                $share = $this->shareService->get($share->getKeyName());
                $share->copyFrom($template);
            } elseif ($isInfiniteRetention) {
                $defaults = Retention::createDefaultInfinite($this->billingService);
                $current = $share->getOffsite()->getRetention();

                $share->getOffsite()->setRetention(new Retention(
                    $defaults->getDaily(),
                    $defaults->getWeekly(),
                    $defaults->getMonthly(),
                    $current->getMaximum() // Preserve current value
                ));
            } elseif ($timeBasedRetentionYears != 0) {
                $retention = Retention::createTimeBased($timeBasedRetentionYears);
                $share->getOffsite()->setRetention($retention);
            }

            $this->shareService->save($share);

            try {
                // This needs to run before 'speedsync add' for peer to peer
                $this->assetInfoSyncService->sync($share->getKeyName());
            } catch (Throwable $e) {
                // Don't allow this to fail the creation, speedsync add may still succeed
                $this->logger->error('CSS0004 Asset info failed to sync', ['exception' => $e]);
            }

            $zfsPath = $share->getDataset()->getZfsPath();
            $this->speedSync->add($zfsPath, $share->getOffsiteTarget());
            $this->logger->info('CSS0005 Share successfully created', ['share' => $share->getName()]); // log code is used by device-web see DWI-2252
            return $share;
        } finally {
            $this->filesystem->unlinkIfExists($inProgressFile);
        }
    }

    /**
     * @param string $shareName
     * @return bool
     */
    public function existsByName(string $shareName): bool
    {
        $shares = $this->shareService->getAllLocal();
        foreach ($shares as $share) {
            if (strtolower($share->getName()) === strtolower($shareName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Construct a default instance of offsite settings.
     *
     * @return OffsiteSettings
     */
    public function createDefaultOffsiteSettings(): OffsiteSettings
    {
        $offsite = new OffsiteSettings();
        $offsite->setRetention(Retention::createApplicableDefault($this->billingService));

        return $offsite;
    }

    /**
     * Create a new OriginDevice object containing
     * this device's deviceID and resellerID
     *
     * @return OriginDevice
     */
    public function createOriginDevice(): OriginDevice
    {
        $deviceId = $this->deviceConfig->getDeviceId();
        $resellerId = $this->deviceConfig->getResellerId();
        return new OriginDevice($deviceId, $resellerId);
    }

    /**
     * Get the create status of a given share.
     *
     * @param string $shareName Share name
     * @return string Status code
     */
    public function getCreateStatus(string $shareName): string
    {
        $inProgressFile = $this->getCreateInProgressFile($shareName);

        return $this->filesystem->exists($inProgressFile)
            ? self::CREATE_IN_PROGRESS
            : self::CREATE_NOT_IN_PROGRESS;
    }

    /**
     * @param Share $share
     * @param Share|null $template
     */
    private function preflightChecks(Share $share, Share $template = null): void
    {
        $assetKeys = $this->repository->getAllNames(true, true);
        $assetExists = in_array($share->getKeyName(), $assetKeys, true);
        if ($assetExists || $this->existsByName($share->getName())) {
            throw new ShareException('A share or system with that name already exists', ShareException::CODE_ALREADY_EXISTS);
        }

        if ($template) {
            $offsiteTargetIsBlankOrDefault = $share->getOffsiteTarget() === null || $share->getOffsiteTarget() === SpeedSync::TARGET_CLOUD;
            if (!$offsiteTargetIsBlankOrDefault) {
                throw new ShareException('Cannot set an offset target when creating from a template');
            }
        } else {
            $this->checkOffsiteTarget($share);
        }
    }

    private function checkOffsiteTarget(Share $share): void
    {
        $peerReplicationSupported = $this->featureService->isSupported(FeatureService::FEATURE_PEER_REPLICATION);

        if ($share->getOffsiteTarget() === SpeedSync::TARGET_CLOUD) {
            $this->logger->info('CSS0001 Using datto cloud as replication target for share', ['shareName' => $share->getName()]);
        } elseif ($share->getOffsiteTarget() === SpeedSync::TARGET_NO_OFFSITE) {
            $this->logger->info('CSS0003 No replication target set for share', ['shareName' => $share->getName()]);
        } elseif ($peerReplicationSupported && SpeedSync::isPeerReplicationTarget($share->getOffsiteTarget())) {
            $this->logger->info('CSS0002 Using device as replication target for share', ['replicationTarget' => $share->getOffsiteTarget(), 'shareName' => $share->getName()]);
            $this->replicationService->assertDeviceReachable($share->getOffsiteTarget());
        } else {
            $p2pMessage = $peerReplicationSupported ? 'a device ID, ' : '';
            throw new ShareException('Offsite Target must be either ' . $p2pMessage . SpeedSync::TARGET_CLOUD . ' or ' . SpeedSync::TARGET_NO_OFFSITE);
        }
    }

    /**
     * Return the temporary share creation file (indicates if a share
     * is being created).
     *
     * @param string $shareName Share name
     * @return string File name
     */
    private function getCreateInProgressFile(string $shareName): string
    {
        return sprintf(self::CREATE_IN_PROGRESS_FORMAT, $shareName);
    }
}
