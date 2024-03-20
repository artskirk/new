<?php

namespace Datto\Screenshot;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;

/**
 * Repository class to handle screenshot files (may include .txt, jpg, etc files that are tied to an asset).
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class ScreenshotFileRepository
{
    /**
     * Constants for determining screenshot paths
     */
    const EXTENSION_TXT = 'txt';
    const EXTENSION_JPG = 'jpg';
    const EXTENSION_OS_UPDATE_PENDING = 'osUpdatePending';
    const SCREENSHOT_PATH = '/datto/config/screenshots/';
    const SCREENSHOT_PATH_FORMAT = self::SCREENSHOT_PATH . '%s.screenshot.%s';
    const SCREENSHOT_EXTENSION = '.' . self::EXTENSION_JPG;
    const ERROR_TEXT_EXTENSION = '.' . self::EXTENSION_TXT;
    const OS_UPDATE_PENDING_FILE_SUFFIX = '.' . self::EXTENSION_OS_UPDATE_PENDING;
    const SCREENSHOT_GLOB = self::SCREENSHOT_PATH_FORMAT . '.*';
    const SAMPLE_SCREENSHOT_IMAGE_PATH = '/datto/web/images/sample.jpg';

    /** @var Filesystem */
    private $filesystem;

    /**
     * @param Filesystem|null $filesystem
     */
    public function __construct(
        Filesystem $filesystem = null
    ) {
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
    }

    /**
     * Return the full path of the screenshot without an extension
     *
     * @param string $keyName Key name of the asset
     * @param integer $snapshotEpoch Epoch time of the snapshot
     * @return string Full path of the screenshot
     */
    public static function getScreenshotPath(string $keyName, int $snapshotEpoch): string
    {
        return sprintf(static::SCREENSHOT_PATH_FORMAT, $keyName, $snapshotEpoch);
    }

    /**
     * Return the full path of the screenshot image, including extension
     *
     * @param string $keyName Key name of the asset
     * @param integer $snapshotEpoch Epoch time of the snapshot
     * @return string Full path of the screenshot
     */
    public static function getScreenshotImagePath(string $keyName, int $snapshotEpoch): string
    {
        return static::getScreenshotPath($keyName, $snapshotEpoch) . static::SCREENSHOT_EXTENSION;
    }

    /**
     * Return the full path of the screenshot error text, including extension
     *
     * @param string $keyName Key name of the asset
     * @param int $snapshotEpoch Epoch time of the snapshot
     * @return string Full path of the failing OCR text from the screenshot
     */
    public static function getScreenshotErrorTextPath(string $keyName, int $snapshotEpoch): string
    {
        return static::getScreenshotPath($keyName, $snapshotEpoch) . static::ERROR_TEXT_EXTENSION;
    }

    /**
     * Return the full path of the OS update pending file, including extension
     *
     * @param string $keyName Key name of the asset
     * @param integer $snapshotEpoch Epoch time of the snapshot
     * @return string Full path of the file
     */
    public static function getOsUpdatePendingPath(string $keyName, int $snapshotEpoch): string
    {
        return static::getScreenshotPath($keyName, $snapshotEpoch) . static::OS_UPDATE_PENDING_FILE_SUFFIX;
    }

    /**
     * Get all screenshot files for a specific asset and snapshot.
     *
     * @param string $assetKey
     * @param int $snapshotEpoch
     * @return ScreenshotFile[]
     */
    public function getAllByAssetAndEpoch(string $assetKey, int $snapshotEpoch): array
    {
        $glob = sprintf(static::SCREENSHOT_GLOB, $assetKey, $snapshotEpoch);
        return $this->getAllByGlob($glob);
    }

    /**
     * @return ScreenshotFile|null
     */
    public function getLatestByKeyName(string $keyName)
    {
        /** @var ScreenshotFile|null $max */
        $max = null;
        $files = $this->getAllByKeyName($keyName);

        foreach ($files as $file) {
            if ($max === null || $max->getSnapshotEpoch() < $file->getSnapshotEpoch()) {
                $max = $file;
            }
        }

        return $max;
    }

    /**
     * Get all screenshot files.
     *
     * @return ScreenshotFile[]
     */
    public function getAll() : array
    {
        $glob = sprintf(static::SCREENSHOT_GLOB, '*', '*');
        return $this->getAllByGlob($glob);
    }

    /**
     * Get all screenshot files for a specific asset.
     *
     * @return ScreenshotFile[]
     */
    public function getAllByKeyName(string $keyName) : array
    {
        $glob = sprintf(static::SCREENSHOT_GLOB, $keyName, '*');
        return $this->getAllByGlob($glob);
    }

    /**
     * Remove a screenshot file.
     *
     * @param ScreenshotFile $screenshot
     */
    public function remove(ScreenshotFile $screenshot)
    {
        $this->filesystem->unlinkIfExists($screenshot->getFile());
    }

    /**
     * @param string $screenshotFileGlob
     * @return ScreenshotFile[]
     */
    private function getAllByGlob($screenshotFileGlob) : array
    {
        $files = $this->filesystem->glob($screenshotFileGlob);

        $screenshots = [];
        foreach ($files as $file) {
            if (preg_match('/(?<assetKey>.+)\.screenshot\.(?<snapshotEpoch>\d+)\.(?<extension>.+)/', basename($file), $matches)) {
                $assetKey = $matches['assetKey'];
                $snapshotEpoch = $matches['snapshotEpoch'];
                // Extension may include the tool name, this string should include anything after the snapshot epoch
                $extension = $matches['extension'];

                $modifiedAt = $this->filesystem->fileMTime($file);

                $screenshots[] = new ScreenshotFile(
                    $assetKey,
                    $snapshotEpoch,
                    $file,
                    $modifiedAt,
                    $extension
                );
            }
        }

        return $screenshots;
    }
}
