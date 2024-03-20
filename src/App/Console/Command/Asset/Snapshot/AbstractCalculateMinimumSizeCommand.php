<?php

namespace Datto\App\Console\Command\Asset\Snapshot;

use Datto\App\Console\Command\AbstractAssetCommand;
use Datto\App\Console\Command\CommandValidator;
use Datto\Asset\AssetService;
use Datto\Common\Resource\ProcessFactory;

/**
 * Partial snapctl command for doing a filesystem minimum size calculation.
 */
abstract class AbstractCalculateMinimumSizeCommand extends AbstractAssetCommand
{
    const MIN_SIZE = 'minSize';
    const CLUSTER_SIZE = 'clusterSize';
    const ORIGINAL_SIZE = 'originalSize';
    const TIMEOUT = 432000; // 5 days
    const CODE_CANNOT_RESIZE = 102;

    protected ProcessFactory $processFactory;

    public function __construct(
        CommandValidator $commandValidator,
        AssetService $assetService,
        ProcessFactory $processFactory = null
    ) {
        parent::__construct($commandValidator, $assetService);
        $this->processFactory = $processFactory ?? new ProcessFactory();
    }
}
