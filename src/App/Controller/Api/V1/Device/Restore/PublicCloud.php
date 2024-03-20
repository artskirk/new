<?php

namespace Datto\App\Controller\Api\V1\Device\Restore;

use Datto\App\Console\Command\Restore\PublicCloud\PublicCloudUploadCommand;
use Datto\Log\LoggerAwareTrait;
use Datto\Security\SecretFile;
use Datto\Service\Restore\Export\PublicCloud\PublicCloudExporter;
use Datto\Service\Restore\Export\PublicCloud\PublicCloudManager;
use Datto\Utility\Screen;
use Psr\Log\LoggerAwareInterface;
use RuntimeException;

/**
 * API endpoint for managing public cloud virtualizations.
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
 * @author Chad Barbe <cbarbe@datto.com>
 */
class PublicCloud implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var PublicCloudManager */
    private $publicCloudManager;

    /** @var Screen */
    private $screen;

    public function __construct(Screen $screen, PublicCloudManager $publicCloudManager)
    {
        $this->screen = $screen;
        $this->publicCloudManager = $publicCloudManager;
    }

    /**
     * Kicks off the upload of the VHDs for each disk of a restore point for the given agent and snapshot.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_VIRTUALIZATION_PUBLIC_CLOUD")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_PUBLIC_CLOUD_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "assetKey" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     * @param string $assetKey
     * @param int $snapshot
     * @param array $sasURIMap Map from mountPoint to SASURI for each disk of the restore
     *                  e.g. [ "C"=> "https://md-impexp-qhqcv2012v3l.blob.core.windows.net/zd1b32xgmqmp/abcd?sv=2017-04-17&sr=b&si=a4951e57-d314-4146-988e-adfc497d20be&sig=Qepkpkl3iq8VkDBPib1TXWwZzZFuRMol5JxVeogJ%2Fc8%3D"]
     * @param string $vmGeneration HyperV VM generation
     * @param bool $enableAgent Whether or not the agent should be enabled in the restored VM
     * @return bool True if the restore has started or was already started, otherwise false
     * @noinspection AnnotationDocBlockTagClassNotFound
     */
    public function upload(
        string $assetKey,
        int $snapshot,
        array $sasURIMap,
        string $vmGeneration = PublicCloudExporter::VM_GENERATION_V2,
        bool $enableAgent = false
    ): bool {
        $screenName = "publicCloudUpload-$assetKey-$snapshot";
        if ($this->screen->isScreenRunning($screenName)) {
            return true;
        }

        $secretFile = new SecretFile();
        $secretFile->save(json_encode($sasURIMap));

        $snapctlArguments = [
            'snapctl',
            PublicCloudUploadCommand::getDefaultName(),
            $assetKey,
            $snapshot,
            $vmGeneration,
            $secretFile->getFilename()
        ];

        if ($enableAgent) {
            $snapctlArguments[] = '--enable-agent';
        }

        $returnValue = $this->screen->runInBackground(
            $snapctlArguments,
            $screenName
        );

        if ($returnValue) {
            try {
                $secretFile->waitUntilSecretFileRemoved();
            } catch (RuntimeException $r) {
                $this->logger->warning('PUB0002: Failed to wait for secret file removal when starting public cloud restore');
                $returnValue = false;
            }
        }
        return $returnValue;
    }

    /**
     * Cleans up the restore after a public cloud upload was performed for the given agent and point. Usually the
     *  restore is automatically cleaned up but this also cleans up the state of the upload which will be needed after
     *  cloudAPI finds out that the upload was completed.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_VIRTUALIZATION_PUBLIC_CLOUD")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_PUBLIC_CLOUD_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "assetKey" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     * @param string $assetKey
     * @param int $snapshot
     * @return bool Always returns true or an error
     * @noinspection AnnotationDocBlockTagClassNotFound
     */
    public function remove(string $assetKey, int $snapshot): bool
    {
        $this->publicCloudManager->remove($assetKey, $snapshot);
        return true;
    }

    /**
     * Get VHD sizes for public cloud VM.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_VIRTUALIZATION_PUBLIC_CLOUD")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_PUBLIC_CLOUD_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "assetKey" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     * @param string $assetKey
     * @param int $snapshot
     * @param string $vmGeneration HyperV VM generation
     * @return array<string, array> Associative array with VHD filenames as key and array with size and os as values
     * @noinspection AnnotationDocBlockTagClassNotFound
     */
    public function getInfo(
        string $assetKey,
        int $snapshot,
        string $vmGeneration = PublicCloudExporter::VM_GENERATION_V2
    ): array {
        return $this->publicCloudManager->getInfo($assetKey, $snapshot, $vmGeneration);
    }

    /**
     * Get get status of a running public cloud restore.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_VIRTUALIZATION_PUBLIC_CLOUD")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_PUBLIC_CLOUD_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "assetKey" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     */
    public function status(string $assetKey, int $snapshot): array
    {
        return $this->publicCloudManager->getStatus($assetKey, $snapshot)->jsonSerialize();
    }
}
