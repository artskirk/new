<?php

namespace Datto\App\Console\Command\Migration;

use Datto\System\Migration\MigrationService;
use Datto\Utility\Screen;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Scans the system for scheduled migrations and runs if necessary
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class RunIfScheduledCommand extends AbstractMigrationCommand
{
    protected static $defaultName = 'migrate:scheduled:run';

    /**
     * Note:
     *      If any arguments/options are added to this command, please ensure they are reflected
     *      on "Datto\System\Migration\MigrationService".
     *
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Scans and starts a migration if scheduled');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln("Checking if migration is scheduled");

        $this->migrationService->runIfScheduled();
        return 0;
    }
}
