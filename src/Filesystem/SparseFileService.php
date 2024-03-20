<?php

namespace Datto\Filesystem;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Utility\File\DdUtility;
use InvalidArgumentException;

/**
 * Used to initialize sparse files on the filesystem
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class SparseFileService
{
    const DEFAULT_SECTOR_SIZE_BYTES = 512;

    /** @var DdUtility */
    private $ddUtility;

    /**
     * @param DdUtility|null $ddUtility
     */
    public function __construct(DdUtility $ddUtility = null)
    {
        $processFactory = new ProcessFactory();
        $this->ddUtility = $ddUtility ?: new DdUtility($processFactory, new Filesystem($processFactory));
    }

    /**
     * Create an empty file
     *
     * @param string $filePath
     * @param int $sizeInBytes
     * @param int $sectorSizeInBytes size of each sector in bytes
     */
    public function create(
        string $filePath,
        int $sizeInBytes,
        int $sectorSizeInBytes = self::DEFAULT_SECTOR_SIZE_BYTES
    ) {
        if ($sizeInBytes % $sectorSizeInBytes !== 0) {
            throw new InvalidArgumentException(
                sprintf(
                    "Expected size to be a multiple of %s, actual=%s",
                    $sectorSizeInBytes,
                    $sizeInBytes
                )
            );
        }

        $sectors = ($sizeInBytes / $sectorSizeInBytes);

        $this->ddUtility->createSparseFile($filePath, $sectorSizeInBytes, $sectors);
    }
}
