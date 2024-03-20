<?php

namespace Datto\App\Controller\Api\V1\Device\Restore\Esx;

use Datto\Asset\Agent\Encryption\TempAccessService;
use Datto\Asset\Agent\EncryptionService;
use Datto\Common\Resource\ProcessFactory;
use Datto\Restore\EsxUpload\EsxUploadManager;
use Datto\Utility\Security\SecretString;

/**
 * API endpoint to initiate and monitor ESX uploads.
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
 * @author Christopher R. Wicks <cwicks@datto.com>
 */
class Upload
{
    /** @var ProcessFactory */
    private $processFactory;

    /** @var EsxUploadManager */
    private $esxUploadManager;

    /** @var EncryptionService */
    private $encryptionService;

    /** @var TempAccessService */
    private $tempAccessService;

    public function __construct(
        ProcessFactory $processFactory,
        EsxUploadManager $esxUploadManager,
        EncryptionService $encryptionService,
        TempAccessService $tempAccessService
    ) {
        $this->processFactory = $processFactory;
        $this->esxUploadManager = $esxUploadManager;
        $this->encryptionService = $encryptionService;
        $this->tempAccessService = $tempAccessService;
    }

    /**
     * Initiate an ESX upload with the given parameters.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_HYPERVISOR_UPLOAD")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_HYPERVISOR_UPLOAD_WRITE")
     *
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "assetKey" = @Datto\App\Security\Constraints\AssetExists(type="agent"),
     *   "connectionName" = @Datto\App\Security\Constraints\ConnectionExists()
     * })
     *
     * @param string $assetKey The key for the asset whose snapshot will be uploaded
     * @param int $snapshot The snapshot to upload
     * @param string $connectionName The name of the ESX connection to use
     * @param string $datastore The datastore to upload the snapshot to
     * @param string $directory The directory on the datastore to upload the snapshot to
     * @param string $passphrase The encryption passphrase
     * @return bool
     */
    public function start(string $assetKey, int $snapshot, string $connectionName, string $datastore, string $directory, string $passphrase): bool
    {
        $passphrase = new SecretString($passphrase);
        if ($this->encryptionService->isEncrypted($assetKey) && !$this->tempAccessService->isCryptTempAccessEnabled($assetKey)) {
            $this->encryptionService->decryptAgentKey($assetKey, $passphrase);
        }

        $this->processFactory->get(
            [
                'snapctl',
                'restore:esxupload:run',
                '--background',
                '--no-interaction', // This is required to prevent prompting for the encryption passphrase
                $assetKey,
                $snapshot,
                $connectionName,
                $datastore,
                $directory
            ]
        )->mustRun();

        return true;
    }

    /**
     * Get the progress of the ESX upload corresponding to the given asset key and process ID.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_HYPERVISOR_UPLOAD")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_HYPERVISOR_UPLOAD_WRITE")
     *
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentKey" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     *
     * @param string $agentKey
     * @param int $snapshot
     * @return array
     */
    public function getProgress(string $agentKey, int $snapshot): array
    {
        return $this->esxUploadManager->getProgress($agentKey, $snapshot);
    }

    /**
     * Cancel the ESX upload corresponding to the given process ID.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_HYPERVISOR_UPLOAD")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_HYPERVISOR_UPLOAD_WRITE")
     *
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentKey" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     *
     * @param string $agentKey
     * @param int $snapshot
     */
    public function cancel(string $agentKey, int $snapshot): void
    {
        $this->esxUploadManager->cancel($agentKey, $snapshot);
    }
}
