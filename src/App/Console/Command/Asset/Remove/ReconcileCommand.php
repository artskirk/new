<?php

namespace Datto\App\Console\Command\Asset\Remove;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Asset\AssetRemovalService;
use Datto\Feature\FeatureService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class ReconcileCommand extends AbstractCommand
{
    protected static $defaultName = 'asset:remove:reconcile';

    /** @var AssetRemovalService */
    private $assetRemovalService;

    public function __construct(
        AssetRemovalService $assetRemovalService
    ) {
        parent::__construct();

        $this->assetRemovalService = $assetRemovalService;
    }

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_ASSETS];
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Reconcile asset removals');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->assetRemovalService->reconcileAssetRemovals();
        return 0;
    }
}
