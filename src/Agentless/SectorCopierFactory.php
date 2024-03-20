<?php

namespace Datto\Agentless;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;

/**
 * Creates SectorCopier instances.
 *
 * @author Jason Lodice <JLodice@datto.com>
 */
class SectorCopierFactory
{
    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @param Filesystem|null $filesystem
     */
    public function __construct(Filesystem $filesystem = null)
    {
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
    }

    /**
     * Create a new SectorCopier instance.
     *
     * @param string $sourcePath path to source data
     * @param string $destPath file path to backup destination
     * @param bool $diffMerge true if only differences are written to backup destination
     * @return SectorCopier
     */
    public function create($sourcePath, $destPath, $diffMerge)
    {
        return new SectorCopier($this->filesystem, $sourcePath, $destPath, $diffMerge);
    }
}
