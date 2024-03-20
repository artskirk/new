<?php

namespace Datto\App\Console\Command\Network;

use Datto\Service\Networking\LinkProblemService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Scans the device network configuration for things that may indicate a bad configuration,
 * or any other potential network problems. Optionally, this command can indicate that the system
 * should perform any automatic repairs for problems that support them.
 *
 * @author Geoff Amey <gamey@datto.com>
 */
class ConfigScanCommand extends Command
{
    protected static $defaultName = 'network:config:scan';

    private LinkProblemService $linkProblemService;

    public function __construct(LinkProblemService $linkProblemService)
    {
        parent::__construct();
        $this->linkProblemService = $linkProblemService;
    }

    protected function configure()
    {
        $this
            ->setDescription('Scans the device for things that may be network problems, and logs potential errors in configuration')
            ->addOption('repair', 'r', InputOption::VALUE_NONE, 'Perform automatic repairs for supported problems');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repair = (bool)$input->getOption('repair');
        $this->linkProblemService->scan($repair);
        return 0;
    }
}
