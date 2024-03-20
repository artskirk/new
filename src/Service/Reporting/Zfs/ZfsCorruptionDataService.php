<?php

namespace Datto\Service\Reporting\Zfs;

use Datto\Common\Resource\Filesystem;
use Datto\Core\Storage\SirisStorage;
use Datto\Core\Storage\StorageInterface;
use Datto\Core\Storage\StoragePoolStatus;
use Datto\Core\Storage\Zfs\ZfsCorruptionInfo;
use Datto\Curl\CurlHelper;
use Datto\Log\LoggerAwareTrait;
use Datto\Utility\Package;
use Exception;
use Psr\Log\LoggerAwareInterface;

/**
 * Reports ZFS corruption data to device web
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class ZfsCorruptionDataService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const CACHED_LAST_VALUES_DIR = '/var/cache/datto/device/zfs';

    /** @var CurlHelper */
    private $curlHelper;

    /** @var Filesystem */
    private $filesystem;

    /** @var StorageInterface */
    private $storage;

    /** @var Package */
    private $package;

    public function __construct(
        CurlHelper $curlHelper,
        Filesystem $filesystem,
        StorageInterface $storage,
        Package $package
    ) {
        $this->curlHelper = $curlHelper;
        $this->filesystem = $filesystem;
        $this->storage = $storage;
        $this->package = $package;
    }

    /**
     * Check to see if there is any new zpool corruption, and if so, upload it to device web
     */
    public function updateZfsCorruptionData()
    {
        $zfsCorruptionCache = $this->getCachedData();
        $zfsCorruptionCurrent = $this->getCurrentData();

        if ($zfsCorruptionCurrent->isSame($zfsCorruptionCache)) {
            $this->logger->debug('ZPC0003 No new errors found in homePool');
            return;
        }

        $count = $zfsCorruptionCurrent->getZpoolErrorCount();
        if ($count > 0) {
            $this->logger->error('ZPC0002 Errors found in homePool', ['count' => $count]);
        } else {
            $this->logger->info('ZPC0005 No errors found in homePool. Updating cache.');
        }

        $this->sendUpdate($zfsCorruptionCurrent);
        $this->updateZfsCorruptionCache($zfsCorruptionCurrent);
    }

    /**
     * Get pool status and parse corruption info. Return any corruption data for the specified asset, if
     * no corruption return an empty array.
     */
    public function checkZfsDatasetCorruption(string $assetKey): array
    {
        $zpoolErrors = $this->storage->getPoolStatus(SirisStorage::PRIMARY_POOL_NAME, false, true)->getErrors();
        $parsedCorruptionInfo = $this->getParsedCorruptionInfo($zpoolErrors);

        return $parsedCorruptionInfo[$assetKey] ?? [];
    }

    /**
     * Parse the zpool status error output and fill in corruption info if any.
     */
    private function getParsedCorruptionInfo(array $zpoolStatusErrors): array
    {
        $corruptionInfo = [];
        foreach ($zpoolStatusErrors as $errorOutput) {
            $error = trim($errorOutput);
            if (preg_match(ZfsCorruptionInfo::STATUS_CORRUPTION_REGEX, $error, $matches)) {
                preg_match(ZfsCorruptionInfo::ASSET_KEY_REGEX, $matches['zfspath'], $assetKey);

                $corruptionData = new ZfsCorruptionInfo(
                    $assetKey['assetKey'],
                    $matches['zfspath'],
                    (int)$matches['snapshot'],
                    $matches['file'],
                    $error
                );

                $corruptionInfo[$corruptionData->getName()][] = $corruptionData;
            }
        }

        return $corruptionInfo;
    }

    private function getCachedData(): ZfsCorruptionData
    {
        $zfsCorruptionData = new ZfsCorruptionData();

        $cacheFile = $this->getCacheFilePath($zfsCorruptionData);
        if ($this->filesystem->exists($cacheFile)) {
            $zfsCorruptionCacheData = $this->filesystem->fileGetContents($cacheFile);
            $zfsCorruptionData->unserialize($zfsCorruptionCacheData);
        }

        return $zfsCorruptionData;
    }

    private function getCurrentData(): ZfsCorruptionData
    {
        $zfsCorruptionData = new ZfsCorruptionData(
            $this->storage->getPoolInfo(SirisStorage::PRIMARY_POOL_NAME)->getErrorCount(),
            $this->storage->getGlobalProperty('zfs_no_write_throttle'),
            $this->package->getPackageVersion('zfs-dkms')
        );

        return $zfsCorruptionData;
    }

    /**
     * Send updated zfs corruption data to device web
     */
    private function sendUpdate(ZfsCorruptionData $zfsCorruptionData)
    {
        $writeThrottle = $zfsCorruptionData->getNoWriteThrottle();
        $data = [
            'zpoolErrorCount' => $zfsCorruptionData->getZpoolErrorCount(),
            'writeThrottle' => !empty($writeThrottle) ? $writeThrottle : null, // send null instead of empty string to maintain behavior
            'kzfsVersion' => $zfsCorruptionData->getKzfsVersion()
        ];

        $update = $this->curlHelper->send('reportZfsError', $data);
        if ($update === false) {
            $message = 'Could not upload zfs corruption data to device web';
            $this->logger->warning('ZPC0001 ' . $message);
            throw new Exception($message);
        }
    }

    /**
     * Updates the zfs corruption cache file with the provided data
     *
     * @param ZfsCorruptionData $zfsCorruptionData The data to update the cache file with.
     */
    private function updateZfsCorruptionCache(ZfsCorruptionData $zfsCorruptionData)
    {
        $this->logger->info('ZPC0004 Updating ZFS corruption cache with new error values');

        $this->filesystem->mkdirIfNotExists(self::CACHED_LAST_VALUES_DIR, true, 0755);

        $cacheFile = $this->getCacheFilePath($zfsCorruptionData);
        $encodedData = $zfsCorruptionData->serialize();
        $this->filesystem->filePutContents($cacheFile, $encodedData);
    }

    private function getCacheFilePath(ZfsCorruptionData $zfsCorruptionData)
    {
        return self::CACHED_LAST_VALUES_DIR . '/' . $zfsCorruptionData->getKeyName();
    }
}
