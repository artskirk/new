<?php

namespace Datto\Asset\Share\ExternalNas;

use Datto\File\Xattr;
use Datto\Log\LoggerAwareTrait;
use Datto\Common\Utility\Filesystem;
use Psr\Log\LoggerAwareInterface;

/**
 * Service that handles backing up ExtendedAttributes for ExternalNasShares
 *
 * @author Alexander Mechler <amechler@datto.com>
 */
class XattrService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var Filesystem */
    private $filesystem;

    /** @var Xattr */
    private $extendedAttributeService;

    public function __construct(
        Filesystem $filesystem,
        Xattr $xattr
    ) {
        $this->filesystem = $filesystem;
        $this->extendedAttributeService = $xattr;
    }

    /**
     * Note: assumes identical directory structures in the source and destination
     *
     * @param string $fromXattr
     * @param string $toXattr
     * @param string $sourceRoot
     * @param string $destinationRoot The root of for the destination files
     * @param array $files List of paths for the files to copy attributes from
     */
    public function copyXattrsFiles(
        string $fromXattr,
        string $toXattr,
        string $sourceRoot,
        string $destinationRoot,
        array $files
    ): void {
        foreach ($files as $file) {
            $basename = basename($file);
            if ($basename === '.' || $basename === '..') {
                continue;
            }

            $sourcePath = $this->filesystem->join($sourceRoot, $file);
            $destinationPath = $this->filesystem->join($destinationRoot, $file);

            if ($this->filesystem->exists($sourcePath) && $this->filesystem->exists($destinationPath)) {
                $this->extendedAttributeService->copyAttribute($fromXattr, $toXattr, $sourcePath, $destinationPath);
            }
        }
    }
}
