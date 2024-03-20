<?php

namespace Datto\Restore\Differential\Rollback;

use Datto\Asset\Agent\Encryption\TempAccessService;
use Datto\Asset\Agent\EncryptionService;
use Datto\Asset\Agent\MountLoopHelper;
use Datto\Asset\Asset;
use Datto\Asset\AssetService;
use Datto\Block\DeviceMapperManager;
use Datto\Block\LoopManager;
use Datto\Filesystem\PartitionService;
use Datto\Log\LoggerAwareTrait;
use Datto\Mercury\MercuryFtpService;
use Datto\Mercury\MercuryFtpTarget;
use Datto\Mercury\MercuryTargetDoesNotExistException;
use Datto\Mercury\TargetInfo;
use Datto\Resource\DateTimeService;
use Datto\Restore\AssetCloneManager;
use Datto\Restore\CloneSpec;
use Datto\Restore\Differential\Rollback\Stages\AttachLoopsStage;
use Datto\Restore\Differential\Rollback\Stages\CreateCloneStage;
use Datto\Restore\Differential\Rollback\Stages\CreateMercuryTargetStage;
use Datto\Restore\Differential\Rollback\Stages\HideFilesStage;
use Datto\Restore\Differential\Rollback\Stages\SaveRestoreStage;
use Datto\Restore\Differential\Rollback\Stages\UnsealAssetStage;
use Datto\Restore\FileExclusionService;
use Datto\Restore\Restore;
use Datto\Restore\RestoreService;
use Datto\Restore\RestoreType;
use Datto\Security\PasswordGenerator;
use Datto\System\Transaction\Transaction;
use Datto\System\Transaction\TransactionFailureType;
use Datto\Utility\Security\SecretString;
use Exception;
use Psr\Log\LoggerAwareInterface;

/**
 * Creates MercuryFTP differential rollback targets for datto-stick.
 *
 * Giovanni Carvelli <gcarvelli@datto.com>
 */
class DifferentialRollbackService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const TARGET_NAME_RESTORE_OPTION_KEY = 'mercuryTarget';
    const FULL_SUFFIX_OPTION_KEY = 'fullSuffix';
    const PASSWORD_LENGTH = 32;

    /** @var AssetService */
    private $assetService;

    /** @var AssetCloneManager */
    private $assetCloneManager;

    /** @var EncryptionService */
    private $encryptionService;

    /** @var TempAccessService */
    private $tempAccessService;

    /** @var RestoreService */
    private $restoreService;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var MountLoopHelper */
    private $mountLoopHelper;

    /** @var MercuryFtpTarget */
    private $mercuryFtpTarget;

    /** @var LoopManager */
    private $loopManager;

    /** @var FileExclusionService */
    private $fileExclusionService;

    /** @var PartitionService */
    private $partitionService;

    /** @var DeviceMapperManager */
    private $deviceMapperManager;

    public function __construct(
        AssetService $assetService,
        AssetCloneManager $assetCloneManager,
        EncryptionService $encryptionService,
        TempAccessService $tempAccessService,
        RestoreService $restoreService,
        DateTimeService $dateTimeService,
        MountLoopHelper $mountLoopHelper,
        MercuryFtpTarget $mercuryFtpTarget,
        LoopManager $loopManager,
        FileExclusionService $fileExclusionService,
        PartitionService $partitionService,
        DeviceMapperManager $deviceMapperManager
    ) {
        $this->assetService = $assetService;
        $this->assetCloneManager = $assetCloneManager;
        $this->encryptionService = $encryptionService;
        $this->tempAccessService = $tempAccessService;
        $this->restoreService = $restoreService;
        $this->dateTimeService = $dateTimeService;
        $this->mountLoopHelper = $mountLoopHelper;
        $this->mercuryFtpTarget = $mercuryFtpTarget;
        $this->loopManager = $loopManager;
        $this->fileExclusionService = $fileExclusionService;
        $this->partitionService = $partitionService;
        $this->deviceMapperManager = $deviceMapperManager;
    }

    /**
     * Creates a differential rollback target for this asset and snapshot.
     *
     * @param string $assetKey
     * @param int $snapshot
     * @param string $suffix
     * @param SecretString|null $passphrase
     * @return Restore
     */
    public function create(
        string $assetKey,
        int $snapshot,
        string $suffix,
        SecretString $passphrase = null
    ): Restore {
        $this->logger->setAssetContext($assetKey);
        $this->logger->info('DFR0001 Creating differential rollback target', ['snapshot' => $snapshot, 'suffix' => $suffix]);

        if ($this->restoreExists($assetKey, $snapshot, $suffix)) {
            throw new Exception("Differential rollback for $assetKey@$snapshot-$suffix already exists!");
        }

        $asset = $this->assetService->get($assetKey);
        $cloneSpec = CloneSpec::fromAsset($asset, $snapshot, $suffix);

        if ($this->encryptionService->isEncrypted($assetKey) &&
            !$this->tempAccessService->isCryptTempAccessEnabled($assetKey) &&
            empty($passphrase)
        ) {
            throw new Exception("Passphrase not provided for asset.");
        }

        $context = new DifferentialRollbackContext(
            $asset,
            $snapshot,
            $cloneSpec,
            $passphrase
        );

        $transaction = $this->getTransaction($context);

        $transaction->commit();

        $this->logger->info('DFR0004 Differential rollback target created', ['snapshot' => $snapshot, 'suffix' => $suffix]);

        return $context->getRestore();
    }

    /**
     * Removes a differential rollback target for this asset and snapshot.
     *
     * @param string $assetKey
     * @param int $snapshot
     * @param string $suffix
     */
    public function remove(string $assetKey, int $snapshot, string $suffix)
    {
        $this->logger->setAssetContext($assetKey);
        $this->logger->info('DFR0005 Removing differential rollback target', ['snapshot' => $snapshot, 'suffix' => $suffix]);

        $restoreType = $this->getRestoreType($suffix);
        $restore = $this->restoreService->find($assetKey, $snapshot, $restoreType);

        if (!$restore) {
            throw new Exception('Restore not found!');
        }

        $targetName = $restore->getOptions()[self::TARGET_NAME_RESTORE_OPTION_KEY];
        $this->logger->debug("DFR0006 Deleting MercuryFTP target $targetName...");

        try {
            $this->mercuryFtpTarget->deleteTarget($targetName);
        } catch (MercuryTargetDoesNotExistException $e) {
            $this->logger->warning('DFR0009 Target does not exist, continuing with remove ...');
        }

        $cloneSpec = CloneSpec::fromAssetAttributes(
            false,
            $assetKey,
            $snapshot,
            $suffix
        );

        $this->mountLoopHelper->detachLoopDevices($cloneSpec->getTargetMountpoint());

        $this->logger->debug('DFR0007 Removing clone ...');
        $this->assetCloneManager->destroyClone($cloneSpec);

        $this->restoreService->delete($restore);
        $this->restoreService->save();

        $this->logger->info('DFR0008 Differential rollback target removed', ['snapshot' => $snapshot, 'suffix' => $suffix]);
    }

    /**
     * Destroys BMRs by recovery point and asset
     *
     * @param string $assetKey
     * @param int $snapshot
     * @param string $restoreType bmr or differential-rollback
     */
    public function removeAllForPoint(string $assetKey, int $snapshot, string $restoreType)
    {
        $asset = $this->assetService->get($assetKey);
        $clones = $this->getClonesByAgentAndPoint($asset, $snapshot, $restoreType);
        foreach ($clones as $clone) {
            $this->assetCloneManager->destroyClone($clone);
            $restore = $this->restoreService->get(
                $asset->getKeyName(),
                $snapshot,
                $restoreType
            );

            $this->restoreService->remove($restore);
            $this->restoreService->save();
        }
    }

    /**
     * Returns true if the differential rollback already exists and false otherwise.
     *
     * @param string $assetKey
     * @param int $snapshot
     * @param string $suffix
     * @return bool
     */
    public function restoreExists(string $assetKey, int $snapshot, string $suffix): bool
    {
        $restoreType = $this->getRestoreType($suffix);
        $restore = $this->restoreService->find($assetKey, $snapshot, $restoreType);

        return $restore !== null;
    }

    /**
     * Get some metadata for the restore; for use by the API.
     *
     * @param string $assetKey
     * @param int $snapshot
     * @param string $suffix
     * @return array
     */
    public function getRestoreData(string $assetKey, int $snapshot, string $suffix): array
    {
        $this->mercuryFtpTarget->startIfDead();
        $restoreType = $this->getRestoreType($suffix);
        $restore = $this->restoreService->get($assetKey, $snapshot, $restoreType);
        $targetName = $restore->getOptions()[self::TARGET_NAME_RESTORE_OPTION_KEY];
        try {
            $targetInfo = $this->mercuryFtpTarget->getTarget($targetName);
        } catch (MercuryTargetDoesNotExistException $exception) {
            // The mercury target gets wiped out if the service crashes.  If the clone is still
            // there, try to re-create the target so we can continue.
            if ($this->cloneExists($assetKey, $snapshot, $suffix)) {
                $targetInfo = $this->createTargetForClone($assetKey, $snapshot, $suffix);
            } else {
                throw $exception;
            }
        }

        $data = [
            'target' => $targetInfo->getName(),
            'password' => $targetInfo->getPassword(),
            'port' => MercuryFtpService::MERCURYFTP_TRANSFER_PORT
        ];

        foreach ($targetInfo->getLuns() as $i => $partitionDevice) {
            $blockDevice = substr($partitionDevice, 0, -2);
            $backingFilePath = $this->loopManager->getLoopInfo($blockDevice)->getBackingFilePath() ?? '';

            $uuid = $this->getUuidFromImagePath($backingFilePath);
            if (!$uuid) {
                $mapperBackingLoop = $this->deviceMapperManager->getBackingFile($backingFilePath);
                if ($mapperBackingLoop) {
                    $backingFilePath = $this->loopManager->getLoopInfo($mapperBackingLoop)->getBackingFilePath() ?? '';
                    $uuid = $this->getUuidFromImagePath($backingFilePath);
                }
            }

            $data['luns'][] = [
                'id' => $i,
                'uuid' => $uuid,
                'path' => $backingFilePath,
                'blkid_uuid' => $this->partitionService->getPartitionUuid($partitionDevice)
            ];
        }

        return $data;
    }

    private function getUuidFromImagePath(string $backingFilePath): string
    {
        if (preg_match('/^(?<uuid>[A-Za-z0-9\-]+)\.d[ae]tto$/', basename($backingFilePath), $matches)) {
            return $matches['uuid'];
        }
        return '';
    }

    private function getRestoreType(string $suffix): string
    {
        return $suffix === RestoreType::DIFFERENTIAL_ROLLBACK
            ? RestoreType::DIFFERENTIAL_ROLLBACK
            : RestoreType::BMR;
    }

    private function getTransaction(DifferentialRollbackContext $context)
    {
        $transaction = new Transaction(TransactionFailureType::STOP_ON_FAILURE(), $this->logger);

        $transaction->add(
            new UnsealAssetStage(
                $context,
                $this->logger,
                $this->encryptionService,
                $this->tempAccessService
            )
        );

        $transaction->add(
            new CreateCloneStage(
                $context,
                $this->logger,
                $this->assetCloneManager
            )
        );

        $transaction->add(
            new HideFilesStage(
                $context,
                $this->logger,
                $this->fileExclusionService
            )
        );

        $transaction->add(
            new AttachLoopsStage(
                $context,
                $this->logger,
                $this->mountLoopHelper
            )
        );

        $transaction->add(
            new CreateMercuryTargetStage(
                $context,
                $this->logger,
                $this->mercuryFtpTarget
            )
        );

        $transaction->add(
            new SaveRestoreStage(
                $context,
                $this->logger,
                $this->restoreService,
                $this->dateTimeService
            )
        );

        return $transaction;
    }

    /**
     * Check if a given clone exists
     *
     * @param string $assetKey
     * @param int $snapshot
     * @param string $suffix
     * @return bool
     */
    private function cloneExists(string $assetKey, int $snapshot, string $suffix): bool
    {
        $asset = $this->assetService->get($assetKey);
        $cloneSpec = CloneSpec::fromAsset($asset, $snapshot, $suffix);
        return $this->assetCloneManager->exists($cloneSpec);
    }

    /**
     * Create a MercuryFTP target for an already cloned restore point.
     *
     * @param string $assetKey
     * @param int $snapshot
     * @param string $suffix
     * @return TargetInfo
     */
    private function createTargetForClone(string $assetKey, int $snapshot, string $suffix): TargetInfo
    {
        $targetName = $this->mercuryFtpTarget->makeRestoreTargetName($assetKey, $snapshot, $suffix);
        $lunPaths = $this->getLunPaths($assetKey, $snapshot, $suffix);
        $password = PasswordGenerator::generate(DifferentialRollbackService::PASSWORD_LENGTH);
        $this->mercuryFtpTarget->createTarget($targetName, $lunPaths, $password);
        return $this->mercuryFtpTarget->getTarget($targetName);
    }

    /**
     * Scan loops for any that belong to the given restore point.
     *
     * @param string $assetKey
     * @param int $snapshot
     * @param string $suffix
     * @return array
     */
    private function getLunPaths(string $assetKey, int $snapshot, string $suffix): array
    {
        $asset = $this->assetService->get($assetKey);
        $cloneSpec = CloneSpec::fromAsset($asset, $snapshot, $suffix);
        $loops = $this->loopManager->getLoops();
        $loopPaths = [];
        foreach ($loops as $loopInfo) {
            if (strpos($loopInfo->getBackingFilePath(), $cloneSpec->getTargetMountpoint()) === 0) {
                $loopPaths[] = $loopInfo->getPathToPartition(1);
            }
        }

        return $loopPaths;
    }

    /**
     * @param Asset $asset
     * @param int $snapshot
     * @param string $restoreType
     * @return CloneSpec[]
     */
    private function getClonesByAgentAndPoint(Asset $asset, int $snapshot, string $restoreType): array
    {
        $zfsClones = $this->assetCloneManager->getAllClones();
        $activeBMRClones = [];
        foreach ($zfsClones as $clone) {
            $cloneAssetKey = $clone->getAssetKey();
            $suffix = $clone->getSuffix();
            $clonePoint = $clone->getSnapshotName();
            $correctAsset = $cloneAssetKey === $asset->getKeyName();
            $isCorrectRestore = strpos($suffix, $restoreType) !== false;
            $correctPoint = $clonePoint === (string)$snapshot;
            if ($correctAsset && $correctPoint && $isCorrectRestore) {
                $activeBMRClones[] = $clone;
            }
        }

        return $activeBMRClones;
    }
}
