<?php

namespace Datto\App\Console\Command\Asset\Remove;

use Datto\App\Console\Command\CommandValidator;
use Datto\Asset\AssetRemovalService;
use Datto\App\Console\Command\AbstractAssetCommand;
use Datto\Asset\AssetService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Remove an asset of any type.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class RemoveCommand extends AbstractAssetCommand
{
    protected static $defaultName = 'asset:remove';

    /** @var AssetRemovalService */
    private $assetRemovalService;

    public function __construct(
        AssetRemovalService $assetRemovalService,
        CommandValidator $commandValidator,
        AssetService $assetService
    ) {
        parent::__construct($commandValidator, $assetService);

        $this->assetRemovalService = $assetRemovalService;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Remove an asset.')
            ->addArgument('assetKey', InputArgument::REQUIRED, 'Key of the asset to be removed')
            ->addOption('enqueue', null, InputOption::VALUE_NONE, 'Enqueue removal to be processed later')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force removal. (Allows local removal if offsite data cannot be removed.)');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $assetKey = $input->getArgument('assetKey');
        $force = (bool) $input->getOption('force');

        if ($input->getOption('enqueue')) {
            $this->assetRemovalService->enqueueAssetRemoval($assetKey, $force);
        } else {
            $this->assetRemovalService->removeAsset($assetKey, $force);
        }

        return 0;
    }
}
