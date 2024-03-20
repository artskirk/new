<?php

namespace Datto\App\Console\Command\System\Update\Window;

use Datto\App\Console\Input\InputArgument;
use Datto\Feature\FeatureService;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Set the update window.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class SetWindowCommand extends AbstractWindowCommand
{
    protected static $defaultName = 'system:update:window:set';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Set the update window hours.')
            ->addArgument('startHour', InputArgument::REQUIRED, 'The start hour for the update window.')
            ->addArgument('endHour', InputArgument::REQUIRED, 'The end hour for the update window.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startHour = $input->getArgument('startHour');
        $endHour = $input->getArgument('endHour');

        if (!is_numeric($startHour)) {
            throw new InvalidArgumentException('Invalid argument for startHour. It must be an integer between 0-24 (inclusive)');
        }

        if (!is_numeric($endHour)) {
            throw new InvalidArgumentException('Invalid argument for endHour. It must be an integer between 0-24 (inclusive)');
        }

        $this->updateWindowService->setWindow((int) $startHour, (int) $endHour);
        $output->writeln('done.');
        return 0;
    }
}
