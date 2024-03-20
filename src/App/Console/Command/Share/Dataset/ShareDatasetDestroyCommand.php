<?php

namespace Datto\App\Console\Command\Share\Dataset;

use Datto\App\Console\Command\AbstractShareCommand;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Datto\Asset\Share\Share;
use Datto\Dataset\Dataset;

/**
 * Destroys the live dataset of a share
 *
 * @author Brian Grogan <bgrogan@datto.com>
 */
class ShareDatasetDestroyCommand extends AbstractShareCommand
{
    protected static $defaultName = 'share:dataset:delete';

    protected function configure()
    {
        $this
            ->setDescription("Destroy a share's live dataset")
            ->addOption('share', 's', InputOption::VALUE_REQUIRED, 'Destroy the live dataset of a share')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);

        $shares = $this->getShares($input);

        /** @var Share $share */
        /** @var Dataset $dataset */
        foreach ($shares as $share) {
            $dataset = $share->getDataset();
            $dataset->delete();
        }
        return 0;
    }

    protected function validateArgs(InputInterface $input): void
    {
        $this->validateShare($input);
    }
}
