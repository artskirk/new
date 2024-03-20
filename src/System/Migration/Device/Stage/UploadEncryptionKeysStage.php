<?php

namespace Datto\System\Migration\Device\Stage;

use Datto\Asset\Agent\Encryption\CloudEncryptionService;
use Datto\Asset\AssetInfoSyncService;
use Datto\System\Migration\Context;
use Datto\System\Migration\Stage\AbstractMigrationStage;

/**
 * Upload encrypted assets' keys to the portal
 *
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class UploadEncryptionKeysStage extends AbstractMigrationStage
{
    private CloudEncryptionService $cloudEncryptionService;

    /**
     * @param Context $context
     * @param CloudEncryptionService $cloudEncryptionService
     * @param AssetInfoSyncService $assetInfoSyncService
     */
    public function __construct(
        Context $context,
        CloudEncryptionService $cloudEncryptionService
    ) {
        parent::__construct($context);
        $this->cloudEncryptionService = $cloudEncryptionService;
    }

    /**
     * Upload encrypted assets' keys to the portal
     * @inheritdoc
     */
    public function commit()
    {
        $this->cloudEncryptionService->uploadEncryptionKeys();
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
    }

    /**
     * @inheritdoc
     */
    public function rollback()
    {
    }
}
