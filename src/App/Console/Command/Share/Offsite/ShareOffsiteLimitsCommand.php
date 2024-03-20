<?php
namespace Datto\App\Console\Command\Share\Offsite;

use Datto\Asset\OffsiteSettings;
use Datto\Asset\Share\Share;
use Datto\App\Console\Command\AbstractShareCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Class ShareOffsiteLimitsCommand
 * Set the limits for nightly retention and on demand retention of a share
 */
class ShareOffsiteLimitsCommand extends AbstractShareCommand
{
    protected static $defaultName = 'share:offsite:limits';

    protected function configure()
    {
        $this
            ->setDescription('Set maximum number of on-demand and nightly offsites deleted when retention is run.')
            ->addOption('share', 's', InputOption::VALUE_REQUIRED, 'Name of the share to set offsite limits for.')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Set the offsite limits for all shares.')
            ->addArgument(
                'onDemand',
                InputOption::VALUE_REQUIRED,
                'maximum deletions when retention is run on demand',
                OffsiteSettings::DEFAULT_ON_DEMAND_RETENTION_LIMIT
            )
            ->addArgument(
                'nightly',
                InputOption::VALUE_REQUIRED,
                'maximum deletions when nightly retention is run',
                OffsiteSettings::DEFAULT_NIGHTLY_RETENTION_LIMIT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);

        $shares = $this->getShares($input);

        $nightlyLimit = $input->getArgument('nightly');
        $onDemandLimit = $input->getArgument('onDemand');

        /** @var Share $share */
        foreach ($shares as $share) {
            if (!$share->getOriginDevice()->isReplicated()) {
                $share->getOffsite()->setNightlyRetentionLimit($nightlyLimit);
                $share->getOffsite()->setOnDemandRetentionLimit($onDemandLimit);
                $this->shareService->save($share);
            }
        }
        return 0;
    }

    protected function validateArgs(InputInterface $input): void
    {
        $this->validateShare($input);

        $this->commandValidator->validateValue(
            $input->getArgument('nightly'),
            new Assert\Range(array('min' => 1, 'max' => 1000000)),
            'Nightly limit must be between 1 and 1000000'
        );
        $this->commandValidator->validateValue(
            $input->getArgument('onDemand'),
            new Assert\Range(array('min' => 1, 'max' => 1000000)),
            'On demand limit must be between 1 and 1000000'
        );
    }
}
