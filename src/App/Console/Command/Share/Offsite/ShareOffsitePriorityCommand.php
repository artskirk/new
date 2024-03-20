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
 * Class ShareOffsitePriorityCommand
 * Set the priority for offsiting a share
 */
class ShareOffsitePriorityCommand extends AbstractShareCommand
{
    protected static $defaultName = 'share:offsite:priority';

    protected function configure()
    {
        $this
            ->setDescription("Set the priority for offsiting a share's snapshots")
            ->addOption('share', 's', InputOption::VALUE_REQUIRED, 'Name of the share to set offsite priority for.')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Set offsite priority for all shares.')
            ->addArgument('priority', InputArgument::REQUIRED, 'Priority level to be set [low | normal | high]');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);

        $shares = $this->getShares($input);

        $priority = $input->getArgument('priority');

        /** @var Share $share */
        foreach ($shares as $share) {
            if (!$share->getOriginDevice()->isReplicated()) {
                $share->getOffsite()->setPriority($priority);
                $this->shareService->save($share);
            }
        }
        return 0;
    }

    protected function validateArgs(InputInterface $input): void
    {
        $this->validateShare($input);

        $this->commandValidator->validateValue(
            $input->getArgument('priority'),
            new Assert\Choice(array('choices' => array(OffsiteSettings::HIGH_PRIORITY, OffsiteSettings::MEDIUM_PRIORITY,
                OffsiteSettings::LOW_PRIORITY)))
        );
    }
}
