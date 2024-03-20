<?php

namespace Datto\Asset;

use Datto\Cloud\JsonRpcClient;
use Datto\Common\Utility\Filesystem;
use Datto\Log\LoggerAwareTrait;
use Datto\System\Storage\StorageService;
use Exception;
use Psr\Log\LoggerAwareInterface;

/**
 * Responsible for uploading asset information to device web.
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class AssetInfoSyncService implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    
    const LAST_SENT_KEY = 'lastSentAssetInfo';
    const LAST_SENT_KEY_PATH_FORMAT = '/datto/config/keys/%s.' . self::LAST_SENT_KEY;

    private Filesystem $filesystem;
    private AssetInfoService $assetInfoService;
    private JsonRpcClient $client;
    private StorageService $storageService;

    public function __construct(
        Filesystem $filesystem,
        AssetInfoService $assetInfoService,
        JsonRpcClient $client,
        StorageService $storageService
    ) {
        $this->filesystem = $filesystem;
        $this->assetInfoService = $assetInfoService;
        $this->client = $client;
        $this->storageService = $storageService;
    }

    /**
     * Upload asset information changes to device-web.
     * This only calls device-web endpoints when an actual change was detected.
     *
     * @param string|null $assetKey If supplied, only upload asset information for this particular asset if changed
     * @return bool True if any changes were sent, false if there was nothing to do
     */
    public function sync(string $assetKey = null): bool
    {
        if (!$this->storageService->poolExists()) {
            $this->logger->error('AIS0011 homePool not imported, refusing to sync asset data.');
            return false;
        }

        $current = $this->getCurrent($assetKey);
        $lastSent = $this->getLastSent($assetKey);
        $changed = $this->getChanged($lastSent, $current);

        $totalDeleted = max(count($lastSent) - count($current), 0);
        $totalChanged = count($changed) + $totalDeleted;

        if ($totalChanged > 0) {
            $this->logger->debug("AIS0008 $totalChanged assets have changed. Syncing.");

            // delete data for assets that are not present on the device
            if ($assetKey === null) {
                $this->deleteUnknown($current, $lastSent);
            }

            // update data for assets that have changed on the device
            return $this->updateChanged($changed);
        }

        return false;
    }

    /**
     * Delete a single asset
     *
     * @param Asset $asset
     */
    public function syncDeletedAsset(Asset $asset): void
    {
        // delete cloud
        $params = ['assetToDelete' => $asset->getUuid()];
        $this->client->queryWithId('v1/device/asset/volumes/delete', $params);

        // delete local
        $this->filesystem->unlink(sprintf(self::LAST_SENT_KEY_PATH_FORMAT, $asset->getUuid()));
    }

    /**
     * Get the asset info from the current state of the device
     *
     * @param string|null $assetKey Limit the AssetInfo objects to one asset
     * @return AssetInfo[]
     */
    private function getCurrent(string $assetKey = null): array
    {
        // todo make getAssetInfoFromDevice return an AssetInfo object
        $assetInfoArray = $this->assetInfoService->getAssetInfoFromDevice($assetKey);

        foreach ($assetInfoArray as $assetInfo) {
            try {
                $current[$assetInfo['name']] = new AssetInfo($assetInfo);
            } catch (Exception $e) {
                $this->logger->error('AIS0006 Could not instantiate object for asset info', ['exception' => $e]);
            }
        }

        return $current ?? [];
    }

    /**
     * Get the asset info from the data that was previously sent to device-web
     *
     * @param string|null $assetKey
     * @return AssetInfo[] The last asset info we sent to device web
     */
    private function getLastSent(string $assetKey = null): array
    {
        $globKey = $assetKey === null ? '*' : $assetKey;
        $files = $this->filesystem->glob(sprintf(self::LAST_SENT_KEY_PATH_FORMAT, $globKey));

        foreach ($files as $file) {
            $contents = $this->filesystem->fileGetContents($file);
            $assetInfo = json_decode($contents, true) ?? [];
            try {
                $lastSent[basename($file, '.' . self::LAST_SENT_KEY)] = new AssetInfo($assetInfo);
            } catch (Exception $e) {
                // problem with values present. file is garbage. Delete it.
                $this->logger->error('AIS0007 Deleting garbage file', ['exception' => $e]);
                $this->filesystem->unlink($file);
            }
        }

        return $lastSent ?? [];
    }

    /**
     * Tell device-web to delete any asset info that does not match the current device state.
     * We upload a complete list of assets that are on the device and device-web purges any
     * others from the database.
     *
     * @param AssetInfo[] $known
     * @param AssetInfo[] $lastSent
     */
    private function deleteUnknown(array $known, array $lastSent): void
    {
        foreach ($known as $assetInfo) {
            $knownZfsDatasets[] = $assetInfo->getZfsPath();
        }

        // delete cloud
        $params = ['knownZfsDatasets' => $knownZfsDatasets ?? []];
        $this->client->queryWithId('v1/device/asset/volumes/deleteUnknown', $params);

        // delete local
        $unknown = $this->getUnknown($known, $lastSent);
        $this->deleteFromLastSent($unknown);
    }

    /**
     * Remove the last sent keyfiles from the passed assets
     *
     * @param AssetInfo[] $unknown the last sent asset info files to remove
     */
    private function deleteFromLastSent(array $unknown): void
    {
        foreach ($unknown as $name => $info) {
            $this->filesystem->unlink(sprintf(self::LAST_SENT_KEY_PATH_FORMAT, $name));
        }
    }

    /**
     * Tell device-web about changes to asset info on the device.
     * This also saves the last sent asset info once we're sure device-web successfully received it.
     *
     * @param AssetInfo[] $changed The asset info that device-web does not know about
     */
    private function updateChanged(array $changed): bool
    {
        if (empty($changed)) {
            return false;
        }

        foreach ($changed as $name => $assetObj) {
            $volumes[$name] = $assetObj->toArray();
        }

        // update cloud
        $result = $this->client->queryWithId('v1/device/asset/volumes/upload', ['volumes' => $volumes]) === true;

        // update local if query is successful
        if ($result) {
            $this->writeLastSent($changed);
        }
        return $result;
    }

    /**
     * @param AssetInfo[] $lastSent
     *
     * @return void
     */
    private function writeLastSent(array $lastSent): void
    {
        foreach ($lastSent as $name => $assetInfo) {
            $contents = json_encode($assetInfo->toArray());
            $this->filesystem->filePutContents(sprintf(self::LAST_SENT_KEY_PATH_FORMAT, $name), $contents);
        }
    }

    /**
     * Get the asset info objects that have changed between $old and $new
     *
     * @param AssetInfo[] $old The old state
     * @param AssetInfo[] $new The new state
     * @return AssetInfo[] Changed objects
     */
    private function getChanged(array $old, array $new): array
    {
        foreach ($new as $name => $newInfo) {
            // This requires a non-strict comparison since we compare data that has been json_encoded and saved to disk
            // with data that we calculate. For example, json_encode will save a float value like 58.0 as 58.
            // When we load that back in, php sees it as an int. That would make this code detect a change every time
            // when using strict checking since 58.0 === 58 is false while 58.0 == 58 is true.
            if (!isset($old[$name]) || $old[$name] != $newInfo) {
                $changed[$name] = $newInfo;
            }
        }

        return $changed ?? [];
    }

    /**
     * Get the asset info objects that we no longer exist
     *
     * @param AssetInfo[] $known The current state of the device, the asset info we know about
     * @param AssetInfo[] $lastSeen The previous state of the device
     * @return AssetInfo[] The objects that have been deleted that we no longer know about
     */
    private function getUnknown(array $known, array $lastSeen): array
    {
        return array_diff_key($lastSeen, $known);
    }
}
