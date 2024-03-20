<?php

namespace Datto\App\Console\Command\Feature;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;

/**
 * Class FeatureListCommand
 * Lists all known feature names and keys
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class FeatureListCommand extends AbstractFeatureCommand
{
    protected static $defaultName = 'feature:list';

    protected function configure()
    {
        $this
            ->setDescription('List existing features for this device');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $features = $this->featureService->listAll();

        $table = new Table($output);
        $table->setHeaders(['Feature', 'Supported']);

        foreach ($features as $featureName) {
            $supported = $this->featureService->isSupported($featureName) ? 'yes' : 'no *';
            $table->addRow([$featureName, $supported]);
        }

        $table->render();

        $output->writeln("");
        $output->writeln("* Please note: Some features can only be checked if an asset is given.");
        $output->writeln("  They may show up as 'no' in this list.");
        $output->writeln("");
        return 0;
    }
}
