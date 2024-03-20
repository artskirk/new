<?php

namespace Datto\App\Console\Command\System\Update\Window;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Display the current update window hours.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class ShowWindowCommand extends AbstractWindowCommand
{
    protected static $defaultName = 'system:update:window:show';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Show the update window.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table = new Table($output);
        $table->setHeaders(array(
            'Start Hour',
            'End Hour'
        ));

        $window = $this->updateWindowService->getWindow();

        $table->addRow(array(
            $window->getStartHour(),
            $window->getEndHour()
        ));

        $table->render();
        return 0;
    }
}
