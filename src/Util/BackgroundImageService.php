<?php

namespace Datto\Util;

use Datto\Common\Resource\ProcessFactory;
use Datto\Config\DeviceConfig;
use Datto\Common\Utility\Filesystem;
use \Exception;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Wrapper for background image customization
 *
 * @author Andrew Cope <acope@datto.com>
 */
class BackgroundImageService
{
    const IMAGE_EXTENSION_MAP = [
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png'
    ];

    const BACKGROUND_WEBROOT = '/datto/web';
    const CUSTOM_BACKGROUND_PATH = '/UI/img/bg/';
    const DEFAULT_BACKGROUND_PATH = '/UI/img/default-backgrounds/';
    const DEFAULT_BACKGROUND_BLUE = '/UI/img/default-backgrounds/blue.png';

    const THUMBNAIL_WIDTH = 250;
    const THUMBNAIL_HEIGHT = 175;
    const THUMBNAIL_WEB_PATH = '/UI/img/default-thumbs/';
    const THUMBNAIL_NAME_TEMPLATE = '%s-thumb.%s';

    const IMAGEMAGICK_CONVERT_BINARY = '/usr/bin/convert';

    const MAX_IMAGE_SIZE = 2097152; // 2 MB

    const CONFIG_BGIMAGE_KEY = 'bgImage';

    const MKDIR_MODE = 0777;

    /** @var DeviceConfig */
    private $config;

    /** @var Filesystem */
    private $filesystem;

    private ProcessFactory $processFactory;

    public function __construct(
        DeviceConfig $config = null,
        Filesystem $filesystem = null,
        ProcessFactory $processFactory = null
    ) {
        $this->config = $config ?: new DeviceConfig();
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->processFactory = $processFactory ?: new ProcessFactory();
    }

    /**
     * Returns the web path for the selected background image, or the default background image
     *
     * @return string
     */
    public function getCurrent()
    {
        $defaultImage = self::DEFAULT_BACKGROUND_BLUE;

        // Siris2, Siris3, and DNAS can have custom backgrounds
        if (!$this->config->has(self::CONFIG_BGIMAGE_KEY)) {
            return $defaultImage;
        } else {
            $backgroundImage = trim($this->config->get(self::CONFIG_BGIMAGE_KEY));
            $backgroundImageFile = self::BACKGROUND_WEBROOT . $backgroundImage;

            if ($this->filesystem->exists($backgroundImageFile)) {
                return $backgroundImage;
            } else {
                return $defaultImage;
            }
        }
    }

    /**
     * Returns the array of all default and custom background images, and their necessary properties
     *
     * @return array
     */
    public function getAll()
    {
        $currentBackground = $this->getCurrent();

        $defaultImagePathPattern = self::BACKGROUND_WEBROOT . self::DEFAULT_BACKGROUND_PATH . '*.*';
        $defaultBackgrounds = $this->filesystem->glob($defaultImagePathPattern);

        $customImagePathPattern = self::BACKGROUND_WEBROOT . self::CUSTOM_BACKGROUND_PATH . '*.*';
        $customBackgrounds = $this->filesystem->glob($customImagePathPattern);

        $backgrounds = array_merge($defaultBackgrounds, $customBackgrounds);

        $allBackgrounds = array();

        foreach ($backgrounds as $background) {
            $webPath = str_replace(self::BACKGROUND_WEBROOT, '', $background);
            $thumbnailPath = $this->buildThumbnail($webPath);
            $isCustomBackground = strpos($background, self::CUSTOM_BACKGROUND_PATH) !== false;
            $isCurrentBackground = $currentBackground === $webPath;
            $urlEncodedThumbnailPath = str_replace('%2F', '/', rawurlencode($thumbnailPath));

            $allBackgrounds[] = array(
                'webPath' => $webPath,
                'urlEncodedThumbnailPath' => $urlEncodedThumbnailPath,
                'isCurrentBackground' => $isCurrentBackground,
                'isCustomBackground' => $isCustomBackground
            );
        }

        return $allBackgrounds;
    }

    /**
     * Sets the /datto/config/bgImage key file to the new background web path
     *
     * @param $backgroundImage
     */
    public function change($backgroundImage)
    {
        if (!$this->isValidImageWebPath($backgroundImage)) {
            throw new Exception('BKG0007 The background image path is invalid');
        }

        $backgroundImagePath = self::BACKGROUND_WEBROOT . $backgroundImage;

        if (!$this->filesystem->exists($backgroundImagePath)) {
            throw new Exception('BKG0008 The background image does not exist');
        }

        $this->config->set(self::CONFIG_BGIMAGE_KEY, $backgroundImage);
    }

    /**
     * Deletes the specified background image (given by web path). If the current background
     * is being deleted, then the default background is selected and returned (to update the
     * background in the UI)
     *
     * @param $backgroundImage
     * @return string the current background web path
     */
    public function delete($backgroundImage)
    {
        if (!$this->isValidImageWebPath($backgroundImage)) {
            throw new Exception('BKG0007 The background image path is invalid');
        }

        if (strpos($backgroundImage, self::CUSTOM_BACKGROUND_PATH) === false) {
            throw new Exception('BKG0009 The background image is not a custom background');
        }

        $backgroundImagePath = self::BACKGROUND_WEBROOT . $backgroundImage;
        if (!$this->filesystem->exists($backgroundImagePath)) {
            throw new Exception('BKG0008 The background image does not exist');
        }

        // Preserve the current background value before unlinking the background image
        $currentBackground = $this->getCurrent();
        if (!$this->filesystem->unlink($backgroundImagePath)) {
            throw new Exception('BKG0010 The background image could not be deleted');
        }

        if ($currentBackground === $backgroundImage) {
            // The current background image no longer exists, get the appropriate background image
            $newBackgroundImage = $this->getCurrent();
            $this->config->set(self::CONFIG_BGIMAGE_KEY, $newBackgroundImage);
            return $newBackgroundImage;
        }
        return $currentBackground;
    }

    /**
     * Upload a background image and generate a thumbnail for it
     *
     * @param UploadedFile $file the uploaded file
     * @return array the image's web path and thumbnail's web path
     */
    public function upload(UploadedFile $file)
    {
        if (!$file->isValid()) {
            throw new Exception('BKG0001 There was an error uploading the file');
        }

        $realFileType = $this->filesystem->exifImageType($file->getPathname());

        if (!$realFileType || !isset(self::IMAGE_EXTENSION_MAP[$realFileType])) {
            throw new Exception('BKG0003 Only jpg, png, or gif files are allowed');
        }

        $extension = self::IMAGE_EXTENSION_MAP[$realFileType];

        $targetDirectory = self::BACKGROUND_WEBROOT . self::CUSTOM_BACKGROUND_PATH;
        $targetFileName = $file->getBasename() . '.' . $extension;
        $targetFilePath = $targetDirectory . $targetFileName;
        $targetFileWebPath = self::CUSTOM_BACKGROUND_PATH . $targetFileName;

        if ($file->getSize() > self::MAX_IMAGE_SIZE) {
            throw new Exception('BKG0004 The maximum file size allowed is 2MB');
        }

        if (!$this->filesystem->isDir($targetDirectory)) {
            $this->filesystem->mkdir($targetDirectory, false, self::MKDIR_MODE);
        }

        if ($this->filesystem->exists($targetFilePath)) {
            throw new Exception('BKG0005 An image with the same name already exists');
        }

        $result = $this->filesystem->moveUploadedFile($file->getPathname(), $targetFilePath);
        if ($result === false) {
            throw new Exception('BKG0006 The uploaded file could not be moved');
        }

        $thumbnailPath = $this->buildThumbnail($targetFileWebPath);
        $this->config->set(self::CONFIG_BGIMAGE_KEY, $targetFileWebPath);

        return array('webpath' => $targetFileWebPath, 'thumbnailpath' => $thumbnailPath);
    }

    /**
     * Validates a background image's web path, to ensure that
     * directory traversal attacks aren't attempted.
     *
     * @param string $backgroundImagePath
     * @return bool if the input web path is a valid path
     */
    private function isValidImageWebPath($backgroundImagePath)
    {
        $inputFileBaseName = $this->filesystem->basename($backgroundImagePath);
        $isInDefaultFolder = strpos($backgroundImagePath, self::DEFAULT_BACKGROUND_PATH) !== false;
        $isInCustomFolder = strpos($backgroundImagePath, self::CUSTOM_BACKGROUND_PATH) !== false;

        if ($isInDefaultFolder) {
            $strippedPath = str_replace(self::DEFAULT_BACKGROUND_PATH, '', $backgroundImagePath);
            return $strippedPath === $inputFileBaseName;
        } elseif ($isInCustomFolder) {
            $strippedPath = str_replace(self::CUSTOM_BACKGROUND_PATH, '', $backgroundImagePath);
            return $strippedPath === $inputFileBaseName;
        } else {
            return false;
        }
    }

    /**
     * Build the thumbnail file for a background image (given by its web path).
     * If the thumbnail already exists, return its web path. Otherwise, use
     * ImageMagick to create the thumbnail file. If the thumbnail cannot be created,
     * then the background image file web path is returned.
     *
     * @param $file
     * @return string
     */
    private function buildThumbnail($file)
    {
        $localFilePath = self::BACKGROUND_WEBROOT . $file;

        $fileBaseName = basename($file);
        $fileParts = explode('.', $fileBaseName);
        $fileExtension = array_pop($fileParts);
        $fileName = implode('.', $fileParts);

        $thumbnailName = sprintf(self::THUMBNAIL_NAME_TEMPLATE, $fileName, $fileExtension);
        $thumbnailPath = self::BACKGROUND_WEBROOT . self::THUMBNAIL_WEB_PATH;

        $thumbnailFilePath = $thumbnailPath . $thumbnailName;
        $thumbnailWebPath = self::THUMBNAIL_WEB_PATH . $thumbnailName;

        // Create thumbnail directory if non-existent
        if (!$this->filesystem->isDir($thumbnailPath)) {
            $this->filesystem->mkdir($thumbnailPath, false, self::MKDIR_MODE);
        }

        // If the thumbnail exists, return the web path
        if ($this->filesystem->exists($thumbnailFilePath)) {
            return $thumbnailWebPath;
        }

        // Imagemagick calculations
        $imageDimensions = getimagesize($localFilePath);
        if (!is_array($imageDimensions) || count($imageDimensions) < 2) {
            return $file;
        }
        $imageWidth = $imageDimensions[0];
        $imageHeight = $imageDimensions[1];
        $thumbnailRatio = self::THUMBNAIL_WIDTH / self::THUMBNAIL_HEIGHT;
        $imageRatio = $imageWidth / $imageHeight;
        $isLandscape = $imageRatio > $thumbnailRatio;
        $imageSize = $isLandscape ? '1000x' . self::THUMBNAIL_HEIGHT : self::THUMBNAIL_WIDTH . 'x1000';
        $xOff = $isLandscape ? floor((($imageRatio * self::THUMBNAIL_HEIGHT) - self::THUMBNAIL_WIDTH) / 2) : 0;

        $cropString = self::THUMBNAIL_WIDTH . 'x' . self::THUMBNAIL_HEIGHT . '+' . $xOff . '+0';
        $processArgs = [
            self::IMAGEMAGICK_CONVERT_BINARY,
            $localFilePath,
            '-resize',
            $imageSize,
            '-crop',
            $cropString,
            '-colorspace',
            'sRGB',
            '-strip',
            '-quality',
            '90',
            $thumbnailFilePath
        ];

        $process = $this->processFactory->get($processArgs);
        $process->run();
        if (!$process->isSuccessful()) {
            return $file;
        }

        if ($this->filesystem->exists($thumbnailFilePath)) {
            return $thumbnailWebPath;
        }

        return $file;
    }
}
