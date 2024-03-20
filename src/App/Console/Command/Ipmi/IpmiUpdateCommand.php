<?php

namespace Datto\App\Console\Command\Ipmi;

use Datto\Ipmi\IpmiService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command for performing an IPMI update if needed.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class IpmiUpdateCommand extends Command
{
    protected static $defaultName = 'ipmi:update';

    /** @var IpmiService */
    private $ipmiService;

    public function __construct(
        IpmiService $ipmiService
    ) {
        parent::__construct();

        $this->ipmiService = $ipmiService;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->addOption('force', '-f', InputOption::VALUE_NONE, 'Force the update (even if already updated).')
            ->addOption('skip-backup', null, InputOption::VALUE_NONE, 'Skip backing up the previous firmware');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = $input->getOption('force');
        $backup = !$input->getOption('skip-backup');

        if ($force) {
            $this->ipmiService->update($backup);
        } else {
            $this->ipmiService->updateIfNeeded($backup);
        }
        return 0;
    }
}
