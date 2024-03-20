<?php

namespace Datto\Backup\Stages;

use Datto\Asset\AssetType;
use Datto\Asset\Share\Share;
use Datto\Asset\Share\ShareException;
use Datto\Backup\BackupException;
use Datto\Samba\SambaManager;
use Datto\ZFS\ZfsDatasetService;
use Exception;

/**
 * Run preflight checks for share backup.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class SharePreflightChecks extends BackupStage
{
    /** @var SambaManager */
    private $sambaManager;

    /** @var ZfsDatasetService */
    private $zfsDatasetService;

    public function __construct(
        SambaManager $sambaManager,
        ZfsDatasetService $zfsDatasetService
    ) {
        $this->sambaManager = $sambaManager;
        $this->zfsDatasetService = $zfsDatasetService;
    }

    /**
     * @inheritdoc
     */
    public function commit()
    {
        $this->verifyShareDataset();
        $this->verifyShareMounted();
        $this->verifySambaShare();
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
        // Nothing to do here
    }

    private function verifyShareMounted()
    {
        /** @var Share $share */
        $share = $this->context->getAsset();

        if ($share->isType(AssetType::ISCSI_SHARE)) {
            return; // iscsi shares aren't mounted
        }

        $share->mount(); // It should already be mounted, but lets double check

        $result = $this->zfsDatasetService->areBaseDatasetsMounted() && $share->getDataset()->isMounted();
        if (!$result) {
            $this->context->getLogger()->critical("ZFS3985 File System is not properly mounted. Please try rebooting your device to remount. If error persists, contact Support");
            throw new BackupException("Filesystem is not mounted");
        }
    }

    private function verifyShareDataset()
    {
        /** @var Share $share */
        $share = $this->context->getAsset();

        try {
            if (!$share->getDataset()->exists()) {
                throw new ShareException("unable to load, dataset ({$share->getDataset()->getZfsPath()}) does not exist");
            }

            if ($share->isType(AssetType::ZFS_SHARE)) {
                return; // zfs shares do not use zvols so the remaining checks do not apply
            }

            $share->getDataset()->assertZVolExists();

            if ($share->isType(AssetType::NAS_SHARE) || $share->isType(AssetType::EXTERNAL_NAS_SHARE)) {
                $share->getDataset()->assertZvolIsPartitioned();
            }
        } catch (Exception $e) {
            $this->context->getLogger()->critical('BAK0701 Dataset failed to verify dataset: ' . $e->getMessage());
            throw new BackupException('Share dataset verify failed');
        }
    }

    private function verifySambaShare()
    {
        /** @var Share $share */
        $share = $this->context->getAsset();

        if ($share->isType(AssetType::ZFS_SHARE) || $share->isType(AssetType::NAS_SHARE)) {
            $name = $share->getDisplayName();
            $share = $this->sambaManager->getShareByName($name);
            if (!$share) {
                throw new BackupException("Samba share $name does not exist");
            }
        }
    }
}
