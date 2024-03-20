<?php

namespace Datto\App\Console\Command\Migration;

use Datto\System\Migration\MigrationService;
use Datto\System\Migration\MigrationType;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Schedules a migration to happen in the future with the specified parameters.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class ScheduleMigrationCommand extends AbstractMigrationCommand
{
    protected static $defaultName = 'migrate:schedule';

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Schedule a device migration')
            ->addOption(
                'type',
                'y',
                InputOption::VALUE_REQUIRED,
                'type of migration to schedule (zpool or device)'
            )
            ->addOption(
                'time',
                'c',
                InputOption::VALUE_REQUIRED,
                'time to schedule the migration'
            )
            ->addOption(
                'maintenance',
                'm',
                InputOption::VALUE_NONE,
                'specify to turn on maintenance during migration'
            )
            ->addOption(
                "target",
                "t",
                InputOption::VALUE_IS_ARRAY|InputOption::VALUE_REQUIRED,
                "Specify a target drive or multiple targets"
            )
            ->addOption(
                "source",
                "s",
                InputOption::VALUE_IS_ARRAY|InputOption::VALUE_REQUIRED,
                "Specify a source drive or multiple sources"
            )
            ->addOption(
                "foreground",
                null,
                InputOption::VALUE_NONE,
                "Run in the foreground"
            );
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sources = $input->getOption("source");
        $targets = $input->getOption("target");

        $time = $input->getOption('time');
        $maintenance = $input->getOption('maintenance');
        $runInForeground = $input->getOption('foreground');
        $type = $input->getOption('type') === MigrationType::DEVICE ?
            MigrationType::DEVICE() :
            MigrationType::ZPOOL_REPLACE();

        $this->migrationService->schedule($time, $sources, $targets, $maintenance, $type, !$runInForeground);
        return 0;
    }
}
