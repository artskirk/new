<?php

namespace Datto\App\Console\Command\Config;

use Datto\Config\ConfigurationRepairService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command class for repairing missing/outdated configuration.
 * This command is intended to be run by systemd datto-config-repair.service at boot.
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class RepairConfigCommand extends Command
{
    protected static $defaultName = 'config:repair';

    /** @var ConfigurationRepairService */
    private $configRepairService;

    public function __construct(
        ConfigurationRepairService $configRepairService
    ) {
        parent::__construct();

        $this->configRepairService = $configRepairService;
    }

    protected function configure()
    {
        $this
            ->setDescription('Repair configuration values that may be missing or changed after an upgrade.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln("Repairing configuration...");
        $this->configRepairService->runTasks();
        $output->writeln("Repairing configuration complete.");
        return 0;
    }
}
