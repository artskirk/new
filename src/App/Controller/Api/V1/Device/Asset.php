<?php

namespace Datto\App\Controller\Api\V1\Device;

use Datto\Asset\Agent\EncryptionService;
use Datto\Asset\Agent\Log\LogService;
use Datto\Asset\AssetRemovalService;
use Datto\Asset\AssetService;
use Datto\Asset\AssetType;
use Exception;

/**
 * Endpoint for asset functionality.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class Asset
{
    const MAX_LOG_LINES = 1000;

    private AssetService $assetService;
    private LogService $logService;
    private AssetRemovalService $assetRemovalService;
    private EncryptionService $encryptionService;

    public function __construct(
        AssetService $assetService,
        LogService $logService,
        AssetRemovalService $assetRemovalService,
        EncryptionService $encryptionService
    ) {
        $this->assetService = $assetService;
        $this->logService = $logService;
        $this->assetRemovalService = $assetRemovalService;
        $this->encryptionService = $encryptionService;
    }

    /**
     * Return whether or not an asset exists
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *      "assetKey" = @Symfony\Component\Validator\Constraints\Type(type = "string")
     * })
     *
     * @param string $assetKey
     * @return bool
     */
    public function exists(string $assetKey): bool
    {
        return $this->assetService->exists($assetKey);
    }

    /**
     * Return type information for a specific asset.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *      "assetKey" = @Datto\App\Security\Constraints\AssetExists()
     * })
     * @param string $assetKey Key name of the asset
     * @return array Array of type information for a specific asset.
     *    The returned array is an associative array with the following keys:
     *      'type':     Agent or Share
     *      'subType':  AssetType of the given asset
     */
    public function getType(string $assetKey): array
    {
        $asset = $this->assetService->get($assetKey);

        return [
            'type' => $asset->isType(AssetType::AGENT) ? AssetType::AGENT : AssetType::SHARE,
            'subType' => $asset->getType()
        ];
    }

    /**
     * Remove any type of asset.
     *
     * FIXME Add granular permission/feature checking
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_DELETE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *      "assetKey" = @Datto\App\Security\Constraints\AssetExists(),
     *      "background" = @Symfony\Component\Validator\Constraints\Type(type = "bool")
     * })
     * @param string $assetKey
     * @param bool $background
     */
    public function remove(string $assetKey, $background = true): void
    {
        if ($background) {
            $this->assetRemovalService->enqueueAssetRemoval($assetKey);
        } else {
            $this->assetRemovalService->removeAsset($assetKey);
        }
    }

    /**
     * Get the status of a removal.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_DELETE")
     *
     * @param string $assetKey
     * @return array
     */
    public function getRemovalStatus(string $assetKey)
    {
        return $this->assetRemovalService->getAssetRemovalStatus($assetKey)->toArray();
    }

    /**
     * Get latest log entries from an asset's log file.
     *
     * FIXME Add granular permission/feature checking
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "assetKey" = @Datto\App\Security\Constraints\AssetExists()
     * })
     * @param string $assetKey The name of the asset
     * @param int $lines The number of log lines to return
     * @return array Array of arrays containing a timestamp and the associated log message
     */
    public function getLogs(string $assetKey, int $lines): array
    {
        $asset = $this->assetService->get($assetKey);

        $lines = min($lines, self::MAX_LOG_LINES);
        $logRecords = $this->logService->getLocalDescending($asset, $lines);
        $arrayRecords = [];

        foreach ($logRecords as $record) {
            $arrayRecords[] = [
                'timestamp' => $record->getTimestamp(),
                'message' => $record->getMessage()
            ];
        }

        return $arrayRecords;
    }
}
