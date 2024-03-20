<?php

namespace Datto\App\Twig;

use Datto\Common\Utility\Filesystem;
use Symfony\Component\Asset\VersionStrategy\VersionStrategyInterface;

/**
 * Implements Modified Time Version Strategy.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class ModifiedTimeVersionStrategy implements VersionStrategyInterface
{
    /** @var Filesystem */
    private $filesystem;

    /** @var string */
    private $staticRoot;

    /**
     * ModifiedTimeVersionStrategy constructor.
     * @param Filesystem $filesystem
     * @param string $staticRoot
     */
    public function __construct(Filesystem $filesystem, string $staticRoot)
    {
        $this->filesystem = $filesystem;
        $this->staticRoot = $staticRoot;
    }

    /**
     * Returns the asset version for an asset.
     *
     * @param string $path A path
     *
     * @return string The version string
     */
    public function getVersion($path)
    {
        $version = @$this->filesystem->fileMTime($this->staticRoot . $path);

        return $version ? $version : "";
    }

    /**
     * Applies version to the supplied path.
     *
     * @param string $path A path
     *
     * @return string The versionized path
     */
    public function applyVersion($path)
    {
        $version = $this->getVersion($path);

        if ($version) {
            return $path . "?" . $version;
        } else {
            return $path;
        }
    }
}
