<?php
namespace Datto\App\Console\Command\Share\Offsite;

use Datto\Asset\Share\Share;
use Datto\App\Console\Command\AbstractShareCommand;
use Datto\Asset\OffsiteSettings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Class ShareOffsiteReplicationCommand
 * Set the offsiting schedule for a share
 * Custom schedule is not supported via command line
 */
class ShareOffsiteReplicationCommand extends AbstractShareCommand
{
    protected static $defaultName = 'share:offsite:replication';

    protected function configure()
    {
        $options = OffsiteSettings::getReplicationOptions();
        $this
            ->setDescription('Set offsite replication interval for a share')
            ->addOption('share', 's', InputOption::VALUE_REQUIRED, 'Name of the share to set offsite replication for.')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Set offsite replication for all shares.')
            ->addArgument('replication', InputArgument::REQUIRED, 'inteval in seconds (' . implode(', ', $options).')');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);

        $shares = $this->getShares($input);

        $replication = $input->getArgument('replication');

        /** @var Share $share */
        foreach ($shares as $share) {
            if (!$share->getOriginDevice()->isReplicated()) {
                $share->getOffsite()->setReplication($replication);
                $this->shareService->save($share);
            }
        }
        return 0;
    }

    protected function validateArgs(InputInterface $input): void
    {
        $this->validateShare($input);

        $options = OffsiteSettings::getReplicationOptions();

        $replication = $input->getArgument('replication');
        $this->commandValidator->validateValue(
            $replication,
            new Assert\Choice(array('choices' => $options)),
            'Interval must be one of the allowed options (' . implode(', ', $options) . ')'
        );
    }
}
