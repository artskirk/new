<?php

namespace Datto\App\Controller\Api\V1\Device\Asset;

use Datto\Asset\Share\ExternalNas\ExternalNasService;
use Datto\Asset\Share\ExternalNas\ExternalNasShare;
use Datto\Config\AgentConfigFactory;
use Datto\Asset\Agent\EncryptionService;
use Datto\Asset\AssetType;
use Datto\Asset\AssetService;
use Datto\Asset\OrphanDatasetService;
use Datto\Backup\BackupManagerFactory;
use Exception;

/**
 * This class contains the API endpoints for destroying the live dataset of
 * assets and entire orphaned datasets
 *
 * Important note:
 *   This is an API endpoint, and NOT the actual library class.
 *   Do NOT add actual functionality to this class. Use library
 *   classes (and functions, if you must) instead!
 *
 * How to call this API?
 *   Please refer to the /api/api.php file and/or the
 *   Datto\API\Server class for details on how to call this API.
 *
 * @author Brian Grogan <bgrogan@datto.com>
 */
class Dataset extends AbstractAssetEndpoint
{
    /**
     * The error code for when a dataset deletion fails during a backup
     */
    const DELETE_FAILURE = 78380;

    private EncryptionService $encryptionService;

    private AgentConfigFactory $agentConfigFactory;

    private OrphanDatasetService $orphanDatasetService;

    private BackupManagerFactory $backupManagerFactory;

    public function __construct(
        AssetService $assetService,
        EncryptionService $encryptionService,
        AgentConfigFactory $agentConfigFactory,
        OrphanDatasetService $orphanDatasetService,
        BackupManagerFactory $backupManagerFactory
    ) {
        parent::__construct($assetService);

        $this->encryptionService = $encryptionService;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->orphanDatasetService = $orphanDatasetService;
        $this->backupManagerFactory = $backupManagerFactory;
    }

    /**
     * Removes all files in the live dataset of assets.
     *
     * FIXME This permission is too broad.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_DELETE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "name" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~"),
     * })
     *
     * @param string $name Name of the asset from which to destroy the live dataset
     * @return bool
     */
    public function delete(string $name): bool
    {
        if ($this->encryptionService->isAgentSealed($name)) {
            throw new Exception("Data cannot be removed on a sealed asset");
        }

        $asset = $this->assetService->get($name);
        $dataset = $asset->getDataset();

        // If a backup is occurring the dataset should not be deleted
        $backupManager = $this->backupManagerFactory->create($asset);
        if ($backupManager->isRunning()) {
            throw new Exception("Cannot delete dataset while a backup is running.", self::DELETE_FAILURE);
        }

        $dataset->delete();
        // If the asset is an agent, trigger a full backup after deleting the dataset
        if ($asset->isType(AssetType::AGENT)) {
            $agentConfig = $this->agentConfigFactory->create($name);
            $agentConfig->set('forceFull', '1');
        }

        // If the asset is an external share and acl backups are enabled, trigger a full acl backup
        if ($asset->isType(AssetType::EXTERNAL_NAS_SHARE)) {
            /** @var ExternalNasShare $asset */
            if ($asset->isBackupAclsEnabled()) {
                $agentConfig = $this->agentConfigFactory->create($name);
                $agentConfig->set(ExternalNasService::FORCE_FULL_ACL_BACKUP_FLAG, '1');
            }
        }

        return true;
    }

    /**
     * Deletes the entire dataset, orphans only
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_DELETE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "dataset" = @Datto\App\Security\Constraints\DatasetExists()
     * })
     *
     * @param string $dataset
     * @return bool
     */
    public function destroy(string $dataset): bool
    {
        $this->orphanDatasetService->destroy($dataset);

        return true;
    }

    /**
     * Returns the ZFS dataset path of an asset
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_DEVICE_MIGRATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_READ")
     * @Datto\App\Security\RequiresPermission("PERMISSION_DEVICE_MIGRATION")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "name" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~"),
     * })
     *
     * @param string $name keyname of an asset
     * @return string ZFS dataset path
     */
    public function getPath(string $name): string
    {
        $asset = $this->assetService->get($name);
        return $asset->getDataset()->getZfsPath();
    }
}
