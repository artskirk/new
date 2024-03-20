<?php

namespace Datto\App\Console\Command\Asset\Local;

use Datto\Asset\Asset;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AssetLocalEnableRansomwareCheckCommand extends AbstractRansomwareCommand
{
    protected static $defaultName = 'asset:local:enableRansomwareCheck';

    protected function configure()
    {
        $this
            ->setDescription('Enable ransomware check for the given asset(s).')
            ->addOption('asset', 's', InputOption::VALUE_REQUIRED, 'Enable ransomware check for this asset')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Enable ransomware check for assets of this type')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Enable ransomware check for all assets')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);
        $assets = $this->getAssets($input);

        /** @var Asset $asset */
        foreach ($assets as $asset) {
            $asset->getLocal()->enableRansomwareCheck();
            $this->assetService->save($asset);
        }
        return 0;
    }

    protected function validateArgs(InputInterface $input): void
    {
        $this->validateAsset($input);
    }
}
