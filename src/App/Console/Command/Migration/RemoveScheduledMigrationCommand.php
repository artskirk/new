<?php

namespace Datto\App\Console\Command\Migration;

use Datto\System\Migration\MigrationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command that removes the scheduled migration if is not already running.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class RemoveScheduledMigrationCommand extends AbstractMigrationCommand
{
    protected static $defaultName = 'migrate:scheduled:cancel';

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Remove a scheduled device migration');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->migrationService->cancelScheduled();

        $output->writeln('Scheduled migration removed.');
        return 0;
    }
}
