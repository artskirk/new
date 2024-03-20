<?php

namespace Datto\Restore\File\Stages;

use Datto\Asset\Agent\Encryption\TempAccessService;
use Datto\Asset\Agent\EncryptionService;
use Datto\Asset\Agent\MountHelper;
use Datto\Asset\AssetType;
use Datto\Restore\CloneSpec;
use Datto\Restore\File\AbstractFileRestoreStage;
use Datto\Restore\File\FileRestoreContext;
use Datto\Restore\RestoreType;
use Datto\System\MountManager;
use Datto\Resource\DateTimeService;
use Datto\Util\DateTimeZoneService;
use Datto\Common\Utility\Filesystem;
use Datto\Util\RetryHandler;
use Datto\Log\DeviceLoggerInterface;

/**
 * Mount volumes for file restore.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class MountFileRestoreStage extends AbstractFileRestoreStage
{
    const AGENT_MOUNT_FORMAT = '/datto/mounts/%s/%s';
    const SHARE_MOUNT_FORMAT = '/datto/mounts/%s-%d-%s';
    const ZVOL_DEVICE_BASE = '/dev/zvol';
    const MKDIR_MODE = 0777;

    /** @var MountHelper */
    private $mountHelper;

    /** @var EncryptionService */
    private $encryptionService;

    /** @var TempAccessService */
    private $tempAccessService;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var DateTimeZoneService */
    private $dateTimeZoneService;

    /** @var Filesystem */
    private $filesystem;

    /** @var MountManager */
    private $mountManager;

    /** @var RetryHandler */
    private $retryHandler;

    public function __construct(
        FileRestoreContext $context,
        DeviceLoggerInterface $logger,
        MountHelper $mountHelper,
        EncryptionService $encryptionService,
        TempAccessService $tempAccessService,
        DateTimeService $dateTimeService,
        DateTimeZoneService $dateTimeZoneService,
        Filesystem $filesystem,
        MountManager $mountManager,
        RetryHandler $retryHandler
    ) {
        parent::__construct($context, $logger);
        $this->mountHelper = $mountHelper;
        $this->encryptionService = $encryptionService;
        $this->tempAccessService = $tempAccessService;
        $this->dateTimeService = $dateTimeService;
        $this->dateTimeZoneService = $dateTimeZoneService;
        $this->filesystem = $filesystem;
        $this->mountManager = $mountManager;
        $this->retryHandler = $retryHandler;
    }

    /**
     * Attempts to execute this stage
     */
    public function commit()
    {
        $asset = $this->context->getAsset();
        $assetKey = $asset->getKeyName();

        $isMountableAgent = $asset->isType(AssetType::AGENT);
        $isMountableShare = $asset->isType(AssetType::NAS_SHARE)
            || $asset->isType(AssetType::EXTERNAL_NAS_SHARE);

        if ($isMountableAgent) {
            $restoreMount = $this->mountAgent();
        } elseif ($isMountableShare) {
            $restoreMount = $this->mountShare();
        } else {
            throw new \Exception("Cannot mount $assetKey: type is not supported");
        }

        // Need folder to be world readable/executable so sftp user can list its contents
        $this->filesystem->chmod(dirname($restoreMount), 0775);

        $this->context->setRestoreMount($restoreMount);
    }

    /**
     * Clean up artifacts left behind in the commit stage
     */
    public function cleanup()
    {
        // nothing
    }

    /**
     * Rolls back any committed changes
     */
    public function rollback()
    {
        $this->mountHelper->unmount(
            $this->context->getAsset()->getKeyName(),
            $this->context->getSnapshot(),
            RestoreType::FILE
        );
    }

    /**
     * @return string
     */
    private function mountAgent(): string
    {
        $assetKey = $this->context->getAsset()->getKeyName();
        $cloneSpec = $this->context->getCloneSpec();
        $restoreMount = sprintf(self::AGENT_MOUNT_FORMAT, $assetKey, $this->getFormattedDate());
        $requiresDecryption = $this->encryptionService->isEncrypted($assetKey)
            && !$this->tempAccessService->isCryptTempAccessEnabled($assetKey);

        if ($requiresDecryption && !$this->context->getRepairMode()) {
            $this->logger->info("FIR0008 Decrypting agent ...");
            $this->encryptionService->decryptAgentKey($assetKey, $this->context->getPassphrase());
        }

        $this->logger->debug("FIR0009 Mounting directory tree ...");
        $this->mountHelper->mountTree($assetKey, $cloneSpec->getTargetMountpoint(), $restoreMount);

        return $restoreMount;
    }

    /**
     * @return string
     */
    private function mountShare(): string
    {
        $assetKey = $this->context->getAsset()->getKeyName();
        $cloneSpec = $this->context->getCloneSpec();
        $partitionPath = $this->getPartitionPath($cloneSpec);
        $restoreMount = sprintf(
            self::SHARE_MOUNT_FORMAT,
            $assetKey,
            $this->context->getSnapshot(),
            RestoreType::FILE
        );

        $this->filesystem->mkdirIfNotExists($restoreMount, false, self::MKDIR_MODE);
        $result = $this->mountManager->mountDevice($partitionPath, $restoreMount);
        if ($result->mountFailed()) {
            throw new \Exception($result->getMountOutput());
        }

        return $restoreMount;
    }

    /**
     * @param CloneSpec $cloneSpec
     * @return string
     */
    private function getPartitionPath(CloneSpec $cloneSpec): string
    {
        $partitionPathGlob = self::ZVOL_DEVICE_BASE . '/' . $cloneSpec->getTargetDatasetName() . '-*';

        // under very heavy disk load zvols will take some time to appear
        $partitionPath = $this->retryHandler->executeAllowRetry(
            function () use ($partitionPathGlob) {
                $partitionPaths = $this->filesystem->glob($partitionPathGlob);
                $partitionPath = reset($partitionPaths);
                if (!$partitionPath) {
                    throw new \Exception("Unable to determine path to partition using glob: " . $partitionPathGlob);
                }

                return $partitionPath;
            }
        );

        return $partitionPath;
    }

    /**
     * @return string
     */
    private function getFormattedDate(): string
    {
        return $this->dateTimeService->format(
            $this->dateTimeZoneService->localizedDateFormat('time-date-hyphenated'),
            $this->context->getSnapshot()
        );
    }
}
