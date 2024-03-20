<?php
namespace Datto\Verification\Screenshot;

use Datto\Common\Utility\Filesystem;
use Exception;
use Imagick;
use InvalidArgumentException;

/**
 * Analyze screenshot bitmaps for unwanted situations.
 *
 * When a system reaches its GUI, it does not always immediately display
 * graphics that would be reassuring for our users to see in a screenshot.
 * This class aids in detecting those situations.
 */
class BitmapAnalyzer
{
    /** @var Filesystem */
    private $filesystem;

    public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * Determine if an image is blank.
     *
     * Uses the number of colors in the image. If there's only one, it's blank.
     *
     * @param $path
     *   The path to the image to be examined.
     * @param Imagick|null $imagick
     *   Imagick object for testing corners and doing unit tests.
     *
     * @return bool
     *   TRUE if the image only contains a single color.
     */
    public function isBlank($path, Imagick $imagick = null)
    {
        if (!isset($imagick)) {
            if (!$this->filesystem->exists($path ?? '')) {
                throw new InvalidArgumentException('File does not exist: ' . $path);
            }

            $image = new Imagick($path);
        } else {
            $image = $imagick;
        }

        return $image->getImageColors() === 1;
    }

    /**
     * Test if a screenshot's corners are blank.
     *
     * Screenshotting generates a JPEG image file. This function checks that
     * file to determine if the top-right corner, bottom-left corner, and
     * bottom-right corner are all a single solid color.
     *
     * This accounts for:
     *  * solid black/grey/blue screens
     *  * Windows Vista's glowing orb animation
     *  * any mouse cursor in the middle of either of the above
     *  * situation observed on Kim Francis's device where a Windows 7 VM's
     *    screenshots (for multiple different snapshots) regularly have a green
     *    dot in the top left corner, with a dozen or so near-black pixels
     *    scattered near it
     *
     * @param string $path
     *   A string containing the path to an image file.
     * @param Imagick $imagick
     *   Imagick object for unit testing.
     *
     * @return bool
     *   TRUE if the image's top-right, bottom-left, and bottom-right corners
     *   are blank, otherwise FALSE.
     */
    public function areCornersBlank($path, Imagick $imagick = null)
    {
        if (!$this->filesystem->exists($path ?? '')) {
            throw new InvalidArgumentException('File does not exist: ' . $path);
        }

        $image = $imagick ?: new Imagick($path);

        if ($image === null) {
            throw new Exception('Error while attempting to load image: ' . $path);
        }

        $imageHeight = $image->getImageHeight();
        $imageWidth = $image->getImageWidth();

        // Calculate corner width and height.
        // Vista has a 300px square glowing orb animation that it displays
        // immediately upon startup. We skip validation of that region so that
        // the glowing orb will not produce a positive validation result.
        // This also rules out images that are blank except for a mouse cursor.
        $ignoredRegionHeight = $ignoredRegionWidth = 300;
        $cornerHeight = $imageHeight / 2 - $ignoredRegionHeight / 2;
        $cornerWidth = $imageWidth / 2 - $ignoredRegionWidth / 2;

        // Get Imagick regions for the top-right, bottom-left, and bottom-right corners.
        $topRightX = $imageWidth - $cornerWidth;
        $topRightY = 0;
        $topRight = $image->getImageRegion($cornerWidth, $cornerHeight, $topRightX, $topRightY);

        $bottomLeftX = 0;
        $bottomLeftY = $imageHeight - $cornerHeight;
        $bottomLeft = $image->getImageRegion($cornerWidth, $cornerHeight, $bottomLeftX, $bottomLeftY);

        $bottomRightX = $topRightX;
        $bottomRightY = $bottomLeftY;
        $bottomRight = $image->getImageRegion($cornerWidth, $cornerHeight, $bottomRightX, $bottomRightY);

        $cornersAreBlank = $this->isBlank('', $topRight)
            && $this->isBlank('', $bottomLeft)
            && $this->isBlank('', $bottomRight);

        return $cornersAreBlank;
    }
}
