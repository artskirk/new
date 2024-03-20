<?php

namespace Datto\App\Console\Command\Asset\Local;

use Datto\Asset\Asset;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AssetLocalDisableRansomwareCheckCommand extends AbstractRansomwareCommand
{
    protected static $defaultName = 'asset:local:disableRansomwareCheck';

    protected function configure()
    {
        $this
            ->setDescription('Disable ransomware check for the given asset(s).')
            ->addOption('asset', 's', InputOption::VALUE_REQUIRED, 'Disable ransomware check for this asset')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Disable ransomware check for assets of this type')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Disable ransomware check for all assets')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);
        $assets = $this->getAssets($input);

        /** @var Asset $asset */
        foreach ($assets as $asset) {
            $asset->getLocal()->disableRansomwareCheck();
            $this->assetService->save($asset);
        }
        return 0;
    }

    protected function validateArgs(InputInterface $input): void
    {
        $this->validateAsset($input);
    }
}
